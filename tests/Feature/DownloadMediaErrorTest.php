<?php

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Models\Media;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

it('uses the canonical watch URL for youtube videos', function () {
    $medium = new Media([
        'youtube_id' => 'V299JJ2Rgu8',
        'original_url' => 'https://www.youtube.com/embed/V299JJ2Rgu8',
    ]);

    expect($medium->youtubeVideoUrl())->toBe('https://www.youtube.com/watch?v=V299JJ2Rgu8');
});

it('reports disabled external playback separately from unavailable videos', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'V299JJ2Rgu8',
        'title' => 'Playable on YouTube',
        'original_url' => 'https://www.youtube.com/embed/V299JJ2Rgu8',
        'status' => MediaStatus::Queued,
    ]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('download')->once()->andReturn([
        'exit_code' => 1,
        'stdout' => '',
        'stderr' => 'ERROR: Video unavailable. Playback on other websites has been disabled by the video owner',
        'files' => [],
        'version' => 'nightly',
    ]);

    expect(fn () => (new DownloadMedia($medium))->handle($youtube))
        ->toThrow(RuntimeException::class, 'Playback restricted');
    expect($medium->attempts()->firstOrFail()->error_category)->toBe('Playback restricted');
});

it('only retries playback restrictions without authentication', function (string $error, bool $expected) {
    $method = new ReflectionMethod(YtDlpService::class, 'requiresUnauthenticatedRetry');
    $result = ['exit_code' => 1, 'stdout' => '', 'stderr' => $error];

    expect($method->invoke(app(YtDlpService::class), $result))->toBe($expected);
})->with([
    ['Playback on other websites has been disabled by the video owner', true],
    ['Embedding disabled', true],
    ['This video is private', false],
    ['Sign in to confirm your age', false],
]);

it('classifies youtube availability conservatively', function (string $error, string $expectedStatus) {
    $method = new ReflectionMethod(YtDlpService::class, 'availabilityResult');
    $result = ['exit_code' => 1, 'stdout' => '', 'stderr' => $error];

    expect($method->invoke(app(YtDlpService::class), $result)['status'])->toBe($expectedStatus);
})->with([
    ['ERROR: Private video', 'unavailable'],
    ['ERROR: This video has been removed by the uploader', 'unavailable'],
    ['ERROR: Video unavailable. The account associated with this video has been terminated', 'unavailable'],
    ['ERROR: Video unavailable. Playback on other websites has been disabled by the video owner', 'available'],
    ['ERROR: Video unavailable', 'unavailable'],
    ['ERROR: Sign in to confirm your age', 'unknown'],
    ['ERROR: HTTP Error 429: Too Many Requests', 'unknown'],
]);

it('stores the authoritative metadata snapshot when a download succeeds', function () {
    $root = storage_path('framework/testing/download-metadata');
    File::ensureDirectoryExists($root.'/Creator');
    config()->set('auroraarchive.media_root', $root);
    $path = $root.'/Creator/video [AAAAAAAAAAA].mp4';
    File::put($path, 'archived video');
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Sparse playlist title',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
        'status' => MediaStatus::Queued,
    ]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('download')->once()->andReturn([
        'exit_code' => 0,
        'stdout' => '',
        'stderr' => '',
        'files' => [$path],
        'version' => 'nightly',
        'metadata' => [
            'title' => 'Authoritative archived title',
            'description' => 'Authoritative archived description',
            'channel' => 'Authoritative channel',
            'channel_id' => 'UCARCHIVE',
            'timestamp' => 1_700_000_000,
            'duration' => 125,
        ],
    ]);

    (new DownloadMedia($medium))->handle($youtube);

    $medium->refresh();
    expect($medium->status)->toBe(MediaStatus::Downloaded)
        ->and($medium->title)->toBe('Authoritative archived title')
        ->and($medium->description)->toBe('Authoritative archived description')
        ->and($medium->channel_name)->toBe('Authoritative channel')
        ->and($medium->channel_id)->toBe('UCARCHIVE')
        ->and($medium->duration_seconds)->toBe(125)
        ->and(data_get($medium->metadata, 'youtube.archive_snapshot.title'))->toBe('Authoritative archived title')
        ->and($medium->files()->exists())->toBeTrue();

    File::deleteDirectory($root);
});
