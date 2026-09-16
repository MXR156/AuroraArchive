<?php

use App\Contracts\YoutubeDownloader;
use App\Models\User;
use App\Services\YtDlpReleaseVersions;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

it('shows installed stable and nightly yt-dlp versions', function () {
    Cache::forget('yt-dlp.release-versions.v1');
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/yt-dlp/yt-dlp/releases/latest' => Http::response([
            'tag_name' => '2026.09.01',
            'html_url' => 'https://github.com/yt-dlp/yt-dlp/releases/tag/2026.09.01',
        ]),
        'https://api.github.com/repos/yt-dlp/yt-dlp-nightly-builds/releases/latest' => Http::response([
            'tag_name' => '2026.09.15.232829',
            'html_url' => 'https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/tag/2026.09.15.232829',
        ]),
    ]);
    $youtube = mock(YoutubeDownloader::class);
    $youtube->shouldReceive('version')->once()->andReturn('2026.09.10.010101');

    $this->actingAs(User::factory()->create())
        ->get(route('settings'))
        ->assertOk()
        ->assertSee('2026.09.10.010101')
        ->assertSee('2026.09.01')
        ->assertSee('2026.09.15.232829')
        ->assertSee('https://github.com/yt-dlp/yt-dlp/releases/tag/2026.09.01', escape: false)
        ->assertSee('https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/tag/2026.09.15.232829', escape: false);

    Http::assertSentCount(2);
});

it('keeps release versions optional when github is unavailable', function () {
    Cache::forget('yt-dlp.release-versions.v1');
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/*' => Http::response([], 503)]);

    $versions = app(YtDlpReleaseVersions::class)->get();

    expect($versions['stable']['version'])->toBeNull()
        ->and($versions['nightly']['version'])->toBeNull()
        ->and($versions['stable']['url'])->toBe('https://github.com/yt-dlp/yt-dlp/releases/latest')
        ->and($versions['nightly']['url'])->toBe('https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/latest');
});

it('encrypts youtube cookies in the database', function () {
    $user = User::factory()->create();
    $cookies = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tsecret-value";
    $this->actingAs($user)->put(route('settings.cookies.store'), ['cookies' => UploadedFile::fake()->createWithContent('cookies.txt', $cookies)])->assertRedirect();
    $credential = $user->youtubeCredential()->firstOrFail();
    expect($credential->cookies)->toBe($cookies)->and(DB::table('youtube_credentials')->where('id', $credential->id)->value('cookies'))->not->toContain('secret-value');
});

it('updates yt-dlp to a selected release channel', function () {
    $user = User::factory()->create();
    $youtube = mock(YoutubeDownloader::class);
    $youtube->shouldReceive('update')->once()->with('nightly')->andReturn([
        'successful' => true,
        'message' => 'Updated yt-dlp to nightly.',
        'version' => '2026.08.21.123456',
    ]);

    $this->actingAs($user)
        ->post(route('settings.yt-dlp.update'), ['channel' => 'nightly'])
        ->assertRedirect()
        ->assertSessionHas('success', 'yt-dlp updated to nightly (2026.08.21.123456).');
});

it('only permits supported yt-dlp release channels', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.yt-dlp.update'), ['channel' => 'untrusted/repository'])
        ->assertSessionHasErrors('channel');
});

it('provides the runtime environment required by the yt-dlp executable', function () {
    $method = new ReflectionMethod(YtDlpService::class, 'processEnvironment');
    $environment = $method->invoke(app(YtDlpService::class), storage_path('app/tmp'));

    expect($environment)
        ->toHaveKeys(['TMPDIR', 'TMP', 'TEMP', 'PYTHONHASHSEED'])
        ->and($environment['PYTHONHASHSEED'])->toBe('0');

    if (PHP_OS_FAMILY === 'Windows') {
        expect($environment)
            ->toHaveKeys(['SYSTEMROOT', 'WINDIR'])
            ->and($environment['SYSTEMROOT'])->not->toBeEmpty();
    }
});

it('selects an official yt-dlp release asset for the host platform', function () {
    $method = new ReflectionMethod(YtDlpService::class, 'releaseAssetName');

    expect($method->invoke(app(YtDlpService::class)))->toBeIn([
        'yt-dlp.exe',
        'yt-dlp_arm64.exe',
        'yt-dlp_x86.exe',
        'yt-dlp_linux',
        'yt-dlp_linux_aarch64',
        'yt-dlp_macos',
    ]);
});

it('replaces the yt-dlp executable without leaving an update file', function () {
    $directory = storage_path('framework/testing/yt-dlp-update');
    File::ensureDirectoryExists($directory);
    $binary = $directory.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp');
    $replacement = $directory.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'update.exe' : 'update');
    File::put($binary, 'old binary');
    File::put($replacement, 'new binary');
    chmod($binary, 0755);
    chmod($replacement, 0755);
    $method = new ReflectionMethod(YtDlpService::class, 'replaceBinary');

    $method->invoke(app(YtDlpService::class), $replacement, $binary);

    expect(File::get($binary))->toBe('new binary')
        ->and(File::exists($replacement))->toBeFalse()
        ->and(File::exists($binary.'.aurora-backup'))->toBeFalse();

    File::deleteDirectory($directory);
});

it('downloads validates and installs an official executable without retaining a file lock', function () {
    $directory = storage_path('framework/testing/yt-dlp-official-update');
    $temporaryDirectory = $directory.DIRECTORY_SEPARATOR.'tmp';
    File::ensureDirectoryExists($temporaryDirectory);
    $binary = $directory.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'yt-dlp.exe' : 'yt-dlp');
    File::copy(PHP_BINARY, $binary);
    chmod($binary, 0755);
    config()->set('auroraarchive.yt_dlp', $binary);
    config()->set('auroraarchive.temp_root', $temporaryDirectory);
    $service = app(YtDlpService::class);
    $assetMethod = new ReflectionMethod(YtDlpService::class, 'releaseAssetName');
    $asset = $assetMethod->invoke($service);
    $contents = File::get(PHP_BINARY);
    Http::preventStrayRequests();
    Http::fake([
        "https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/latest/download/{$asset}" => Http::response($contents),
        'https://github.com/yt-dlp/yt-dlp-nightly-builds/releases/latest/download/SHA2-256SUMS' => Http::response(hash('sha256', $contents).'  '.$asset.PHP_EOL),
    ]);

    $result = $service->update('nightly');

    expect($result['successful'])->toBeTrue()
        ->and($result['version'])->toContain('PHP')
        ->and(hash_file('sha256', $binary))->toBe(hash('sha256', $contents))
        ->and(File::files($temporaryDirectory))->toBeEmpty();

    File::deleteDirectory($directory);
});
