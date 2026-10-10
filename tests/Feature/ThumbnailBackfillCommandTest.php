<?php

use App\Models\Media;
use App\Services\MediaThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills archived thumbnails and reports their sources', function () {
    $first = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Published thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $first->files()->create(['path' => 'channel/first.mp4']);
    $second = Media::query()->create([
        'youtube_id' => 'BBBBBBBBBBB',
        'title' => 'Local thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=BBBBBBBBBBB',
    ]);
    $second->files()->create(['path' => 'channel/second.mp4']);
    $thumbnails = Mockery::mock(MediaThumbnail::class);
    $thumbnails->shouldReceive('backfill')->once()->with(Mockery::on(fn (Media $media): bool => $media->is($first)), true, false)->andReturn('youtube');
    $thumbnails->shouldReceive('backfill')->once()->with(Mockery::on(fn (Media $media): bool => $media->is($second)), true, false)->andReturn('local');
    $this->app->instance(MediaThumbnail::class, $thumbnails);

    $this->artisan('archive:backfill-thumbnails', ['--delay' => 0])
        ->expectsOutputToContain('Writing canonical thumbnails')
        ->assertSuccessful();
});
