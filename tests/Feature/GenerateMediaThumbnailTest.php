<?php

use App\Jobs\GenerateMediaThumbnail;
use App\Models\Media;
use App\Services\MediaThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('thumbnail generation runs outside the web request', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Embedded thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('generate')->once()->with($medium);

    (new GenerateMediaThumbnail($medium))->handle($thumbnail);
});

test('thumbnail jobs are unique per media record', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Embedded thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    expect((new GenerateMediaThumbnail($medium))->uniqueId())->toBe((string) $medium->id);
});
