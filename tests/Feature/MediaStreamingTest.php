<?php

use App\Models\Media;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function streamableMedia(string $root): Media
{
    File::ensureDirectoryExists($root.'/Creator');
    File::put($root.'/Creator/video.mp4', '0123456789');
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Streamable video',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    MediaFile::query()->create([
        'media_id' => $medium->id,
        'path' => 'Creator/video.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 10,
    ]);

    return $medium;
}

test('local streaming supports browser byte range requests', function () {
    $root = storage_path('framework/testing/media-streaming');
    config()->set('auroraarchive.media_root', $root);
    config()->set('auroraarchive.accelerated_streaming', false);
    $medium = streamableMedia($root);

    $this->actingAs(User::factory()->create())
        ->withHeader('Range', 'bytes=2-5')
        ->get(route('media.stream', $medium))
        ->assertStatus(206)
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Range', 'bytes 2-5/10')
        ->assertHeader('Content-Length', '4');

    File::deleteDirectory($root);
});

test('docker streaming delegates authorised files to nginx', function () {
    $root = storage_path('framework/testing/media-accelerated-streaming');
    config()->set('auroraarchive.media_root', $root);
    config()->set('auroraarchive.accelerated_streaming', true);
    $medium = streamableMedia($root);

    $this->actingAs(User::factory()->create())
        ->get(route('media.stream', $medium))
        ->assertOk()
        ->assertHeader('X-Accel-Redirect', '/protected-media/Creator/video.mp4')
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Accept-Ranges', 'bytes');

    File::deleteDirectory($root);
});

test('streaming still requires authentication', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Private video',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->get(route('media.stream', $medium))->assertRedirect(route('login'));
});
