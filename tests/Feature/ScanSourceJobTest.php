<?php

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Jobs\ScanSource;
use App\Models\Media;
use App\Models\MediaTombstone;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('discovers media without contacting youtube', function () {
    Queue::fake();
    $source = Source::create(['user_id' => User::factory()->create()->id, 'type' => 'channel', 'external_id' => 'UC1', 'name' => 'Channel', 'url' => 'https://youtube.com/channel/UC1', 'auto_download' => true]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('discover')->once()->andReturn([['id' => 'video123', 'title' => 'A video', 'channel' => 'Channel', 'webpage_url' => 'https://youtube.com/watch?v=video123']]);
    (new ScanSource($source))->handle($youtube);
    expect($source->media()->where('youtube_id', 'video123')->exists())->toBeTrue();
    Queue::assertPushed(DownloadMedia::class);
});

it('preserves manual metadata and download state during later scans', function () {
    Queue::fake();
    $source = Source::create(['user_id' => User::factory()->create()->id, 'type' => 'channel', 'external_id' => 'UC1', 'name' => 'Channel', 'url' => 'https://youtube.com/channel/UC1']);
    $medium = Media::query()->create([
        'source_id' => $source->id,
        'youtube_id' => 'video123',
        'title' => 'Manual title',
        'description' => 'Manual description',
        'channel_name' => 'Manual channel',
        'original_url' => 'https://youtube.com/watch?v=video123',
        'status' => MediaStatus::Downloaded,
        'metadata' => ['manual' => ['channel_name' => true, 'title' => true, 'description' => true], 'tubesync' => ['preserved' => true]],
    ]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('discover')->once()->andReturn([[
        'id' => 'video123',
        'title' => 'YouTube title',
        'description' => 'YouTube description',
        'channel' => 'YouTube channel',
        'webpage_url' => 'https://youtube.com/watch?v=video123',
    ]]);

    (new ScanSource($source))->handle($youtube);

    $medium->refresh();
    expect($medium->title)->toBe('Manual title')
        ->and($medium->description)->toBe('Manual description')
        ->and($medium->channel_name)->toBe('Manual channel')
        ->and($medium->status)->toBe(MediaStatus::Downloaded)
        ->and(data_get($medium->metadata, 'tubesync.preserved'))->toBeTrue();
    Queue::assertNothingPushed();
});

it('records unavailable playlist entries without queueing them', function () {
    Queue::fake();
    $source = Source::create(['user_id' => User::factory()->create()->id, 'type' => 'playlist', 'external_id' => 'PL1', 'name' => 'Playlist', 'url' => 'https://youtube.com/playlist?list=PL1', 'auto_download' => true]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('discover')->once()->andReturn([['id' => 'unavailable1', 'title' => '[Deleted video]', 'availability' => 'unavailable']]);

    (new ScanSource($source))->handle($youtube);

    $medium = Media::query()->where('youtube_id', 'unavailable1')->firstOrFail();
    expect($medium->status)->toBe(MediaStatus::Failed)
        ->and(data_get($medium->metadata, 'youtube.unavailable'))->toBeTrue()
        ->and($source->playlistMedia()->whereKey($medium->id)->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

it('does not downgrade an archived video when youtube later reports it unavailable', function () {
    Queue::fake();
    $source = Source::create(['user_id' => User::factory()->create()->id, 'type' => 'playlist', 'external_id' => 'PL1', 'name' => 'Playlist', 'url' => 'https://youtube.com/playlist?list=PL1']);
    $medium = Media::query()->create([
        'source_id' => $source->id,
        'youtube_id' => 'archived123',
        'title' => 'Archived title',
        'description' => 'Archived description',
        'channel_name' => 'Archived channel',
        'original_url' => 'https://www.youtube.com/watch?v=archived123',
        'status' => MediaStatus::Downloaded,
    ]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('discover')->once()->andReturn([['id' => 'archived123', 'title' => '[Private video]', 'availability' => 'private']]);

    (new ScanSource($source))->handle($youtube);

    $medium->refresh();
    expect($medium->status)->toBe(MediaStatus::Downloaded)
        ->and($medium->title)->toBe('Archived title')
        ->and($medium->description)->toBe('Archived description')
        ->and($medium->channel_name)->toBe('Archived channel')
        ->and(data_get($medium->metadata, 'youtube.unavailable'))->toBeTrue();
});

it('ignores deliberately deleted media IDs during later scans', function () {
    Queue::fake();
    $source = Source::create(['user_id' => User::factory()->create()->id, 'type' => 'playlist', 'external_id' => 'PL1', 'name' => 'Playlist', 'url' => 'https://youtube.com/playlist?list=PL1']);
    MediaTombstone::factory()->create(['youtube_id' => 'deleted1234']);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('discover')->once()->andReturn([['id' => 'deleted1234', 'title' => '[Deleted video]', 'availability' => 'unavailable']]);

    (new ScanSource($source))->handle($youtube);

    expect(Media::query()->where('youtube_id', 'deleted1234')->exists())->toBeFalse()
        ->and($source->playlistMedia()->exists())->toBeFalse();
});
