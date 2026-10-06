<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ArchiveRecovery;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/archive-recovery');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root.'/Playlist/Recovered Channel');
    config()->set('auroraarchive.ffprobe', 'missing-ffprobe-binary');
});

afterEach(fn () => File::deleteDirectory($this->root));

test('surviving tubesync and aurora files rebuild deduplicated media records', function () {
    File::put($this->root.'/Playlist/Recovered Channel/2024-01-02_Recovered title_ABCDEFGHIJK_1080p-vp9-opus.mkv', 'first copy');
    File::put($this->root.'/Playlist/Recovered Channel/2024-01-02 - Recovered title [ABCDEFGHIJK].mp4', 'second copy');
    $youtube = Mockery::mock(YtDlpService::class);
    $youtube->shouldNotReceive('metadataForRecovery');

    $stats = (new ArchiveRecovery($youtube))->recover($this->root, write: true);

    $medium = Media::query()->where('youtube_id', 'ABCDEFGHIJK')->firstOrFail();
    expect($stats)->toMatchArray(['scanned' => 2, 'recognised' => 2, 'media_created' => 1, 'files_attached' => 2])
        ->and($medium->title)->toBe('Recovered title')
        ->and($medium->channel_name)->toBe('Recovered Channel')
        ->and($medium->published_at?->toDateString())->toBe('2024-01-02')
        ->and($medium->status)->toBe(MediaStatus::Downloaded)
        ->and($medium->files)->toHaveCount(2)
        ->and(data_get($medium->metadata, 'recovery.folder'))->toBe('Playlist/Recovered Channel');
});

test('dry runs report files without changing the database', function () {
    File::put($this->root.'/Playlist/Recovered Channel/video [ABCDEFGHIJK].mp4', 'archive');
    File::put($this->root.'/Playlist/Recovered Channel/file-without-id.mkv', 'unknown');
    $youtube = Mockery::mock(YtDlpService::class);

    $stats = (new ArchiveRecovery($youtube))->recover($this->root);

    expect($stats)->toMatchArray(['scanned' => 2, 'recognised' => 1, 'unrecognised' => 1])
        ->and(Media::query()->count())->toBe(0);
});

test('online recovery enriches metadata without requiring the old databases', function () {
    File::put($this->root.'/Playlist/Recovered Channel/video [ABCDEFGHIJK].mp4', 'archive');
    $youtube = Mockery::mock(YtDlpService::class);
    $youtube->shouldReceive('metadataForRecovery')->once()->with('ABCDEFGHIJK', null)->andReturn([
        'title' => 'YouTube title',
        'description' => 'YouTube description',
        'channel' => 'YouTube Channel',
        'channel_id' => 'UC-RECOVERED',
        'timestamp' => 1_700_000_000,
        'duration' => 123,
        'thumbnail' => 'https://example.test/thumb.jpg',
    ]);

    (new ArchiveRecovery($youtube))->recover($this->root, write: true, online: true);

    $medium = Media::query()->firstOrFail();
    expect($medium->title)->toBe('YouTube title')
        ->and($medium->description)->toBe('YouTube description')
        ->and($medium->channel_name)->toBe('YouTube Channel')
        ->and($medium->duration_seconds)->toBe(123)
        ->and(data_get($medium->metadata, 'recovery.online_enriched'))->toBeTrue();
});

test('deep folder structures reconstruct local playlist membership', function () {
    File::put($this->root.'/Playlist/Recovered Channel/video [ABCDEFGHIJK].mp4', 'archive');
    $youtube = Mockery::mock(YtDlpService::class);
    $user = User::factory()->create();

    $stats = (new ArchiveRecovery($youtube))->recover(
        $this->root,
        write: true,
        userId: $user->id,
        createPlaylists: true,
    );

    $playlist = Playlist::query()->firstOrFail();
    expect($stats)->toMatchArray(['playlists_created' => 1, 'memberships_attached' => 1])
        ->and($playlist->name)->toBe('Playlist')
        ->and($playlist->media)->toHaveCount(1)
        ->and($playlist->media->first()->youtube_id)->toBe('ABCDEFGHIJK');
});
