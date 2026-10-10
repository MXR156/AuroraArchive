<?php

use App\Jobs\GenerateMediaThumbnail;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('the thumbnail endpoint serves the resolved media thumbnail', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Embedded thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $thumbnailPath = storage_path('framework/testing/thumbnail.jpg');
    File::ensureDirectoryExists(dirname($thumbnailPath));
    File::put($thumbnailPath, 'image');

    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('path')
        ->once()
        ->with(Mockery::on(fn (Media $boundMedia): bool => $boundMedia->is($medium)))
        ->andReturn($thumbnailPath);
    app()->instance(MediaThumbnail::class, $thumbnail);

    $this->actingAs($user)
        ->get(route('media.thumbnail', $medium))
        ->assertOk();

    File::delete($thumbnailPath);
});

test('the thumbnail endpoint falls back to youtube when local extraction is unavailable', function () {
    Queue::fake();
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Remote thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('path')->once()->andReturnNull();
    app()->instance(MediaThumbnail::class, $thumbnail);

    $this->actingAs($user)
        ->get(route('media.thumbnail', $medium))
        ->assertRedirect('https://i.ytimg.com/vi/AAAAAAAAAAA/hqdefault.jpg');

    Queue::assertPushed(GenerateMediaThumbnail::class, fn (GenerateMediaThumbnail $job): bool => $job->media->is($medium));
});

test('a thumbnail sharing the local video filename is resolved without a youtube id', function () {
    $root = storage_path('framework/testing/media-thumbnail-sidecar');
    File::ensureDirectoryExists($root.'/channel');
    config()->set('auroraarchive.media_root', $root);
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'TubeSync sidecar thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    File::put($root.'/channel/saved-video.mkv', 'video');
    File::put($root.'/channel/saved-video.jpg', 'thumbnail');
    $medium->files()->create(['path' => 'channel/saved-video.mkv']);

    expect(app(MediaThumbnail::class)->path($medium))->toBe(realpath($root.'/channel/saved-video.jpg'));

    File::deleteDirectory($root);
});

test('a generated plain black thumbnail is discarded for regeneration', function () {
    $root = storage_path('framework/testing/media-thumbnail-black');
    File::ensureDirectoryExists($root);
    config()->set('auroraarchive.media_root', $root);
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Black generated thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $mediaPath = $root.'/video.mkv';
    File::put($mediaPath, 'video');
    $medium->files()->create(['path' => 'video.mkv']);
    $cachePath = storage_path('app/thumbnails/'.$medium->id.'-'.filemtime($mediaPath).'.jpg');
    File::ensureDirectoryExists(dirname($cachePath));
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
    imagejpeg($image, $cachePath);
    imagedestroy($image);

    expect(app(MediaThumbnail::class)->path($medium))->toBeNull()
        ->and(File::exists($cachePath))->toBeFalse();

    File::deleteDirectory($root);
});

test('a youtube thumbnail refresh stores the published thumbnail with priority', function () {
    $root = storage_path('framework/testing/media-thumbnail-youtube');
    config()->set('auroraarchive.media_root', $root);
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Published thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
    ob_start();
    imagejpeg($image);
    $contents = (string) ob_get_clean();
    imagedestroy($image);
    Http::fake([
        'i.ytimg.com/*' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $thumbnail = app(MediaThumbnail::class);

    expect($thumbnail->refreshFromYoutube($medium))->toBeTrue()
        ->and(realpath((string) $thumbnail->path($medium)))->toBe(realpath($root.'/Thumbs/AAAAAAAAAAA.jpg'))
        ->and($medium->refresh()->getRawOriginal('thumbnail_url'))->toBe(route('media.thumbnail', $medium, absolute: false))
        ->and(data_get($medium->metadata, 'local_thumbnail_path'))->toBe('Thumbs/AAAAAAAAAAA.jpg');

    File::deleteDirectory($root);
});

test('the thumbnail endpoint uses nginx for a canonical thumbnail', function () {
    $root = storage_path('framework/testing/media-thumbnail-accelerated');
    File::ensureDirectoryExists($root.'/Thumbs');
    config()->set('auroraarchive.media_root', $root);
    config()->set('auroraarchive.accelerated_streaming', true);
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Canonical thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    File::put($root.'/Thumbs/AAAAAAAAAAA.jpg', 'image');

    $this->actingAs($user)
        ->get(route('media.thumbnail', $medium))
        ->assertOk()
        ->assertHeader('X-Accel-Redirect', '/protected-media/Thumbs/AAAAAAAAAAA.jpg')
        ->assertHeader('Cache-Control', 'max-age=604800, private');

    File::deleteDirectory($root);
});

test('a local thumbnail is normalised into the canonical thumbnail store', function () {
    $root = storage_path('framework/testing/media-thumbnail-local-store');
    File::ensureDirectoryExists($root);
    config()->set('auroraarchive.media_root', $root);
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Local thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $sourcePath = $root.'/source.jpg';
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
    imagejpeg($image, $sourcePath);
    imagedestroy($image);

    $thumbnail = app(MediaThumbnail::class);

    expect($thumbnail->storeFromFile($medium, $sourcePath))->toBeTrue()
        ->and(realpath((string) $thumbnail->path($medium)))->toBe(realpath($root.'/Thumbs/AAAAAAAAAAA.jpg'))
        ->and(mime_content_type($root.'/Thumbs/AAAAAAAAAAA.jpg'))->toBe('image/jpeg');

    File::deleteDirectory($root);
});

test('backfill falls back to a saved local thumbnail when youtube has none', function () {
    $root = storage_path('framework/testing/media-thumbnail-online-fallback');
    File::ensureDirectoryExists($root.'/archive');
    config()->set('auroraarchive.media_root', $root);
    $sourcePath = $root.'/archive/saved.jpg';
    $image = imagecreatetruecolor(640, 360);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
    imagejpeg($image, $sourcePath);
    imagedestroy($image);
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Unavailable on YouTube',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
        'metadata' => ['local_thumbnail_path' => 'archive/saved.jpg'],
    ]);
    Http::fake(['i.ytimg.com/*' => Http::response('', 404)]);

    expect(app(MediaThumbnail::class)->backfill($medium))->toBe('local')
        ->and(File::exists($root.'/Thumbs/AAAAAAAAAAA.jpg'))->toBeTrue();

    File::deleteDirectory($root);
});
