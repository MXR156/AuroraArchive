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

it('classifies structured youtube watch page player states', function (string $playabilityStatus, string $expectedStatus) {
    $method = new ReflectionMethod(YtDlpService::class, 'webpageAvailabilityResult');
    $html = '<html><script>var data = {"playabilityStatus":'.$playabilityStatus.',"videoDetails":{"videoId":"AAAAAAAAAAA"}};</script></html>';

    expect($method->invoke(app(YtDlpService::class), $html)['status'])->toBe($expectedStatus);
})->with([
    'available' => ['{"status":"OK","playableInEmbed":true}', 'available'],
    'removed' => ['{"status":"ERROR","reason":"This video has been removed by the uploader"}', 'unavailable'],
    'private' => ['{"status":"LOGIN_REQUIRED","reason":"This is a private video"}', 'unavailable'],
    'terminated channel' => ['{"status":"ERROR","reason":"Video unavailable. The account associated with this video has been terminated."}', 'unavailable'],
    'age gate remains inconclusive' => ['{"status":"LOGIN_REQUIRED","reason":"Sign in to confirm your age"}', 'unknown'],
    'bot challenge remains inconclusive' => ['{"status":"LOGIN_REQUIRED","reason":"Sign in to confirm you are not a bot"}', 'unknown'],
]);

it('does not infer removal when youtube omits its player status', function () {
    $method = new ReflectionMethod(YtDlpService::class, 'webpageAvailabilityResult');

    expect($method->invoke(app(YtDlpService::class), '<html>Consent required</html>')['status'])->toBe('unknown');
});

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

it('reports age verification failures separately from unavailable videos', function () {
    $medium = Media::query()->create([
        'youtube_id' => '3gKhBhpSn_A',
        'title' => 'Age restricted video',
        'original_url' => 'https://www.youtube.com/watch?v=3gKhBhpSn_A',
        'status' => MediaStatus::Queued,
    ]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('download')->once()->andReturn([
        'exit_code' => 1,
        'stdout' => '',
        'stderr' => 'Some web_creator formats are missing a URL due to SABR. ERROR: Sorry, this content is age-restricted',
        'files' => [],
        'version' => 'nightly',
    ]);

    expect(fn () => (new DownloadMedia($medium))->handle($youtube))
        ->toThrow(RuntimeException::class, 'Age verification / PO token required');
    expect($medium->attempts()->firstOrFail()->error_category)->toBe('Age verification / PO token required');
});

it('adds the configured po token provider plugin to yt dlp calls', function () {
    config()->set('auroraarchive.yt_dlp_plugin_dir', '/opt/yt-dlp-plugins');
    config()->set('auroraarchive.yt_dlp_pot_provider_url', 'http://127.0.0.1:4416/');
    $method = new ReflectionMethod(YtDlpService::class, 'potProviderArguments');

    expect($method->invoke(app(YtDlpService::class)))->toBe([
        '--plugin-dirs',
        '/opt/yt-dlp-plugins',
        '--extractor-args',
        'youtubepot-bgutilhttp:base_url=http://127.0.0.1:4416',
    ]);
});
