<?php

use App\Models\Media;
use App\Models\MediaFile;
use App\Services\RecoverMediaUploaderNames;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('uploader names are recovered from imported media folder paths', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Orphaned import',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    MediaFile::query()->create([
        'media_id' => $medium->id,
        'path' => 'Playlist Name/Recovered Creator/video_AAAAAAAAAAA.mkv',
    ]);

    expect(app(RecoverMediaUploaderNames::class)->handle())->toBe(1)
        ->and($medium->refresh()->channel_name)->toBe('Recovered Creator')
        ->and(app(RecoverMediaUploaderNames::class)->handle())->toBe(0);
});
