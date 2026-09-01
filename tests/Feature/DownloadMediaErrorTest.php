<?php

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Models\Media;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

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
    ['ERROR: Video unavailable', 'unknown'],
    ['ERROR: Sign in to confirm your age', 'unknown'],
    ['ERROR: HTTP Error 429: Too Many Requests', 'unknown'],
]);
