<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Services\YtDlpMetadataRecovery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->extract = storage_path('framework/testing/yt-dlp-recovery.txt');
    File::ensureDirectoryExists(dirname($this->extract));
});

afterEach(fn () => File::delete($this->extract));

test('it merges duplicate snapshots and stores only durable metadata', function () {
    $records = [
        ['id' => 'ABCDEFGHIJK', 'title' => 'Recovered title', 'channel' => 'Recovered channel', 'channel_id' => 'UC123', 'duration' => 123, 'url' => 'https://signed.googlevideo.test/private'],
        ['id' => 'ABCDEFGHIJK', 'description' => 'The complete recovered description', 'timestamp' => 1_700_000_000, 'thumbnail' => 'https://i.ytimg.com/vi/ABCDEFGHIJK/maxresdefault.jpg?temporary=1'],
        ['id' => 'LMNOPQRSTUV', 'title' => 'Second video', 'availability' => 'subscriber_only'],
    ];
    File::put($this->extract, collect($records)->map(fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR))->push('incomplete data')->implode(PHP_EOL));

    $stats = app(YtDlpMetadataRecovery::class)->recover($this->extract, write: true);

    $medium = Media::query()->where('youtube_id', 'ABCDEFGHIJK')->firstOrFail();
    expect($stats)->toMatchArray(['records' => 3, 'unique_videos' => 2, 'malformed' => 1, 'created' => 2])
        ->and($medium->title)->toBe('Recovered title')
        ->and($medium->description)->toBe('The complete recovered description')
        ->and($medium->channel_name)->toBe('Recovered channel')
        ->and($medium->published_at?->timestamp)->toBe(1_700_000_000)
        ->and($medium->thumbnail_url)->toBe('https://i.ytimg.com/vi/ABCDEFGHIJK/maxresdefault.jpg')
        ->and($medium->status)->toBe(MediaStatus::Discovered)
        ->and(data_get($medium->metadata, 'recovery.yt_dlp_extract.snapshot_count'))->toBe(2)
        ->and(data_get($medium->metadata, 'youtube.archive_snapshot.url'))->toBeNull();
});

test('dry run parses the extract without changing the database', function () {
    File::put($this->extract, json_encode(['id' => 'ABCDEFGHIJK', 'title' => 'Recovered title'], JSON_THROW_ON_ERROR));

    $stats = app(YtDlpMetadataRecovery::class)->recover($this->extract);

    expect($stats)->toMatchArray(['records' => 1, 'unique_videos' => 1, 'created' => 0])
        ->and(Media::query()->count())->toBe(0);
});
