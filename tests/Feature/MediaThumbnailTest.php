<?php

use App\Jobs\GenerateMediaThumbnail;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
