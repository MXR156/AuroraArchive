<?php

namespace App\Services;

use App\Contracts\YoutubeDownloader;
use App\Models\Media;
use App\Models\Source;
use App\Models\YoutubeCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class YtDlpService implements YoutubeDownloader
{
    public function __construct(private MediaStoragePath $storagePath) {}

    /** @return array<string, mixed>|null */
    public function metadataForRecovery(string $youtubeId, ?int $userId = null): ?array
    {
        $arguments = [
            '--dump-single-json', '--no-playlist', '--skip-download', '--no-warnings',
            'https://www.youtube.com/watch?v='.$youtubeId,
        ];
        $cookies = $this->cookiesFor($userId);
        $result = $this->run($arguments, $cookies, 90, true);

        if (filled($cookies) && $this->requiresPotClientFallback($result)) {
            foreach ($this->potFallbackClients() as $client) {
                $fallback = $this->run([
                    '--extractor-args',
                    "youtube:player_client={$client};fetch_pot=always",
                    ...$arguments,
                ], $cookies, 90, true);
                if ($fallback['exit_code'] === 0) {
                    $result = $fallback;
                    break;
                }
            }
        }

        if (filled($cookies) && $result['exit_code'] !== 0) {
            $fallback = $this->run($arguments, null, 90, true);
            if ($fallback['exit_code'] === 0) {
                $result = $fallback;
            }
        }

        if ($result['exit_code'] === 0) {
            $metadata = json_decode($result['stdout'], true);
            if (is_array($metadata)) {
                return $metadata;
            }
        }

        return $this->webpageMetadata($youtubeId);
    }

    public function discover(Source $source): array
    {
        $arguments = $source->type === 'video'
            ? ['--dump-single-json', '--no-playlist', '--no-warnings', $source->url]
            : ['--dump-single-json', '--flat-playlist', '--no-warnings', $source->url];
        $result = $this->run($arguments, $this->cookiesFor($source->user_id), preserveStdout: true);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException($result['stderr']);
        }
        $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        $entries = Arr::get($payload, 'entries', [$payload]);

        return array_values(array_filter($entries, fn (mixed $entry): bool => is_array($entry) && filled($entry['id'] ?? null)));
    }

    public function download(Media $media): array
    {
        $directory = $this->destination($media);
        $template = $directory.'/%(upload_date>%Y-%m-%d)s - %(title).150B [%(id)s].%(ext)s';
        $arguments = [
            '--newline',
            '--no-playlist',
            '--format',
            'bestvideo[vcodec^=avc1]+bestaudio[ext=m4a]/best[vcodec^=avc1][ext=mp4]/bestvideo+bestaudio/best',
            '--embed-thumbnail',
            '--embed-metadata',
            '--write-info-json',
            '--merge-output-format',
            'mp4',
            '--postprocessor-args',
            'Merger+ffmpeg_o:-movflags +faststart',
            '-o',
            $template,
            $media->youtubeVideoUrl(),
        ];
        $cookies = $this->cookiesFor($media->source?->user_id);
        $result = $this->run($arguments, $cookies, 7200);
        if (filled($cookies) && $this->potProviderConfigured() && $this->requiresPotClientFallback($result)) {
            foreach ($this->potFallbackClients() as $client) {
                $fallback = $this->run([
                    '--extractor-args',
                    "youtube:player_client={$client};fetch_pot=always",
                    ...$arguments,
                ], $cookies, 7200);
                if ($fallback['exit_code'] === 0) {
                    $result = $fallback;
                    break;
                }

                $result['stderr'] .= PHP_EOL.$client.' PO-token fallback:'.PHP_EOL.$fallback['stderr'];
            }
        }
        if (filled($cookies) && $this->requiresUnauthenticatedRetry($result)) {
            $retry = $this->run($arguments, null, 7200);
            if ($retry['exit_code'] === 0) {
                $result = $retry;
            } else {
                $result['stderr'] .= PHP_EOL.'Unauthenticated retry:'.PHP_EOL.$retry['stderr'];
            }
        }
        $files = array_values(array_filter(glob($directory.'/*') ?: [], fn (string $path): bool => Str::contains(basename($path), '['.$media->youtube_id.']')));
        $infoPath = collect($files)->first(fn (string $path): bool => Str::endsWith(Str::lower($path), '.info.json'));
        $downloadMetadata = $infoPath !== null ? json_decode((string) file_get_contents($infoPath), true) : null;
        if ($infoPath !== null) {
            unlink($infoPath);
            $files = array_values(array_filter($files, fn (string $path): bool => $path !== $infoPath));
        }
        $result['files'] = $files;
        $result['metadata'] = is_array($downloadMetadata) ? $downloadMetadata : [];
        $result['version'] = $this->version();

        return $result;
    }

    public function checkAvailability(Media $media): array
    {
        $arguments = ['--simulate', '--no-playlist', '--dump-single-json', $media->youtubeVideoUrl()];
        $cookies = $this->cookiesFor($media->source?->user_id);
        $result = $this->run($arguments, $cookies, 90);
        if (filled($cookies) && $this->requiresUnauthenticatedRetry($result)) {
            $retry = $this->run($arguments, null, 90);
            if ($retry['exit_code'] === 0) {
                $result = $retry;
            }
        }

        $ytDlpAvailability = $this->availabilityResult($result);
        $webpageAvailability = $this->webpageAvailability($media);

        return $this->consolidateAvailability([
            'yt_dlp' => $ytDlpAvailability,
            'watch_page' => $webpageAvailability,
        ]);
    }

    /**
     * @param  array<string, array{status:'available'|'unavailable'|'unknown',reason:?string}>  $evidence
     * @return array{status:'available'|'unavailable'|'unknown',reason:?string,evidence:array<string, array{status:string,reason:?string}>}
     */
    private function consolidateAvailability(array $evidence): array
    {
        $available = collect($evidence)->firstWhere('status', 'available');
        if ($available !== null) {
            return ['status' => 'available', 'reason' => null, 'evidence' => $evidence];
        }

        $unavailable = collect($evidence)->firstWhere('status', 'unavailable');
        if ($unavailable !== null) {
            return ['status' => 'unavailable', 'reason' => $unavailable['reason'], 'evidence' => $evidence];
        }

        return [
            'status' => 'unknown',
            'reason' => $ytDlpAvailability['reason'] ?: $webpageAvailability['reason'],
            'evidence' => $evidence,
        ];
    }

    /** @param array{exit_code:int,stdout:string,stderr:string} $result @return array{status:'available'|'unavailable'|'unknown',reason:?string} */
    private function availabilityResult(array $result): array
    {
        if ($result['exit_code'] === 0) {
            return ['status' => 'available', 'reason' => null];
        }

        $error = Str::lower($result['stderr']);
        if (Str::contains($error, [
            'playback on other websites has been disabled',
            'embedding disabled',
        ])) {
            return ['status' => 'available', 'reason' => null];
        }

        if (Str::contains($error, [
            'private video',
            'this video has been removed',
            'video has been removed',
            'removed by the uploader',
            'removed for violating',
            'is no longer available',
            'account associated with this video has been terminated',
            'video unavailable',
        ])) {
            return [
                'status' => 'unavailable',
                'reason' => Str::limit(trim(Str::afterLast($result['stderr'], 'ERROR:')), 500, ''),
            ];
        }

        return ['status' => 'unknown', 'reason' => Str::limit(trim($result['stderr']), 500, '')];
    }

    /** @return array{status:'available'|'unavailable'|'unknown',reason:?string} */
    private function webpageAvailability(Media $media): array
    {
        try {
            $response = $this->youtubeWatchPage($media->youtube_id);
            if (! $response->successful()) {
                return ['status' => 'unknown', 'reason' => 'YouTube watch page returned HTTP '.$response->status().'.'];
            }

            return $this->webpageAvailabilityResult($response->body());
        } catch (Throwable $exception) {
            return ['status' => 'unknown', 'reason' => Str::limit($this->sanitise($exception->getMessage()), 500, '')];
        }
    }

    private function youtubeWatchPage(string $youtubeId): Response
    {
        return Http::withHeaders([
            'Accept-Language' => 'en-GB,en;q=0.9',
            'User-Agent' => 'Mozilla/5.0 (compatible; AuroraArchive/1.0)',
        ])->withCookies(['CONSENT' => 'YES+cb'], '.youtube.com')
            ->connectTimeout(5)
            ->timeout(15)
            ->retry([250, 1000], throw: false)
            ->get('https://www.youtube.com/watch', [
                'v' => $youtubeId,
                'hl' => 'en',
                'has_verified' => '1',
                'bpctr' => '9999999999',
            ]);
    }

    /** @return array<string, mixed>|null */
    private function webpageMetadata(string $youtubeId): ?array
    {
        try {
            $response = $this->youtubeWatchPage($youtubeId);
            if (! $response->successful()) {
                return null;
            }

            return $this->webpageMetadataResult($response->body());
        } catch (Throwable $exception) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function webpageMetadataResult(string $html): ?array
    {
        $playerResponse = $this->jsonObjectAfter($html, 'ytInitialPlayerResponse');
        if (! is_array($playerResponse) || Arr::get($playerResponse, 'playabilityStatus.status') !== 'OK') {
            return null;
        }

        $details = Arr::get($playerResponse, 'videoDetails');
        if (! is_array($details) || blank(Arr::get($details, 'title'))) {
            return null;
        }

        $microformat = Arr::get($playerResponse, 'microformat.playerMicroformatRenderer', []);
        $thumbnail = collect(Arr::get($details, 'thumbnail.thumbnails', []))->last();
        $publishDate = Arr::get($microformat, 'publishDate') ?: Arr::get($microformat, 'uploadDate');

        return [
            'id' => Arr::get($details, 'videoId'),
            'title' => Arr::get($details, 'title'),
            'description' => Arr::get($details, 'shortDescription'),
            'channel' => Arr::get($details, 'author'),
            'channel_id' => Arr::get($details, 'channelId'),
            'channel_url' => Arr::get($microformat, 'ownerProfileUrl'),
            'uploader' => Arr::get($details, 'author'),
            'uploader_id' => Arr::get($details, 'channelId'),
            'uploader_url' => Arr::get($microformat, 'ownerProfileUrl'),
            'upload_date' => is_string($publishDate) ? str_replace('-', '', $publishDate) : null,
            'duration' => filled(Arr::get($details, 'lengthSeconds')) ? (int) Arr::get($details, 'lengthSeconds') : null,
            'thumbnail' => is_array($thumbnail) ? Arr::get($thumbnail, 'url') : null,
            'availability' => 'public',
        ];
    }

    /** @return array{status:'available'|'unavailable'|'unknown',reason:?string} */
    private function webpageAvailabilityResult(string $html): array
    {
        $playerResponse = $this->jsonObjectAfter($html, 'ytInitialPlayerResponse');
        $playability = is_array($playerResponse) ? Arr::get($playerResponse, 'playabilityStatus') : null;
        if (! is_array($playability)) {
            $playability = $this->jsonObjectAfter($html, '"playabilityStatus":');
        }
        if ($playability === null) {
            return ['status' => 'unknown', 'reason' => 'YouTube watch page did not expose a player status.'];
        }

        $status = Str::upper((string) Arr::get($playability, 'status'));
        $reason = (string) (Arr::get($playability, 'reason')
            ?: Arr::get($playability, 'messages.0')
            ?: Arr::get($playability, 'errorScreen.playerErrorMessageRenderer.reason.simpleText'));
        $normalisedReason = Str::lower($reason);

        if ($status === 'OK') {
            return ['status' => 'available', 'reason' => null];
        }
        if (Str::contains($normalisedReason, [
            'video unavailable',
            'private video',
            'has been removed',
            'no longer available',
            'account associated with this video has been terminated',
            'copyright claim',
        ])) {
            return ['status' => 'unavailable', 'reason' => Str::limit($reason, 500, '')];
        }

        return ['status' => 'unknown', 'reason' => Str::limit($reason ?: 'YouTube returned player status '.$status.'.', 500, '')];
    }

    /** @return array<string, mixed>|null */
    private function jsonObjectAfter(string $value, string $marker): ?array
    {
        $markerPosition = strpos($value, $marker);
        if ($markerPosition === false) {
            return null;
        }

        $start = strpos($value, '{', $markerPosition + strlen($marker));
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $insideString = false;
        $escaped = false;
        $length = strlen($value);
        for ($position = $start; $position < $length; $position++) {
            $character = $value[$position];
            if ($insideString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $insideString = false;
                }

                continue;
            }
            if ($character === '"') {
                $insideString = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}' && --$depth === 0) {
                $decoded = json_decode(substr($value, $start, $position - $start + 1), true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    /** @param array{exit_code:int,stdout:string,stderr:string} $result */
    private function requiresUnauthenticatedRetry(array $result): bool
    {
        if ($result['exit_code'] === 0) {
            return false;
        }

        $error = Str::lower($result['stderr']);
        if (Str::contains($error, [
            'playback on other websites has been disabled',
            'embedding disabled',
        ])) {
            return true;
        }

        return Str::contains($error, 'video unavailable')
            && ! Str::contains($error, [
                'private video',
                'this video has been removed',
                'video has been removed',
                'removed by the uploader',
                'removed for violating',
                'is no longer available',
                'account associated with this video has been terminated',
                'age-restricted',
                'confirm your age',
            ]);
    }

    /** @param array{exit_code:int,stdout:string,stderr:string} $result */
    private function requiresPotClientFallback(array $result): bool
    {
        $error = Str::lower($result['stderr']);

        return $result['exit_code'] !== 0
            && Str::contains($error, ['age-restricted', 'confirm your age'])
            && Str::contains($error, ['sabr', 'missing a url', 'po token']);
    }

    public function testAuthentication(string $cookies): array
    {
        try {
            $result = $this->run(['--simulate', '--flat-playlist', '--playlist-end', '1', '--dump-single-json', 'https://www.youtube.com/playlist?list=WL'], $cookies, 45);
            if ($result['exit_code'] === 0) {
                return ['status' => 'valid', 'message' => 'YouTube accepted the stored cookies.'];
            }
            $error = Str::lower($result['stderr']);
            if (Str::contains($error, ['sign in', 'cookies are no longer valid', 'authentication'])) {
                return ['status' => 'rejected', 'message' => 'YouTube rejected the stored cookies.'];
            }
            if (Str::contains($error, ['429', 'too many requests', 'dns', 'timed out', 'javascript'])) {
                return ['status' => 'unable_to_validate', 'message' => 'Authentication could not be tested because of an unrelated temporary error.'];
            }

            return ['status' => 'unable_to_validate', 'message' => $result['stderr'] ?: 'YouTube authentication could not be validated.'];
        } catch (Throwable $exception) {
            return ['status' => 'unable_to_validate', 'message' => $this->sanitise($exception->getMessage())];
        }
    }

    public function version(): ?string
    {
        try {
            $result = $this->run(['--version'], null, 10);

            return $result['exit_code'] === 0 ? trim($result['stdout']) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function update(string $channel): array
    {
        $temporaryPath = null;
        try {
            $repository = $channel === 'nightly' ? 'yt-dlp/yt-dlp-nightly-builds' : 'yt-dlp/yt-dlp';
            $asset = $this->releaseAssetName();
            $binaryPath = $this->resolveBinaryPath();
            $temporaryPath = rtrim((string) config('auroraarchive.temp_root'), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.'yt-dlp-update-'.Str::random(12).(Str::endsWith($asset, '.exe') ? '.exe' : '');
            File::ensureDirectoryExists(dirname($temporaryPath), 0700);

            $download = Http::withUserAgent('AuroraArchive')
                ->connectTimeout(5)
                ->timeout(120)
                ->retry([250, 1000], throw: false)
                ->sink($temporaryPath)
                ->get("https://github.com/{$repository}/releases/latest/download/{$asset}");
            $downloadSuccessful = $download->successful();
            $download->close();
            unset($download);
            clearstatcache(true, $temporaryPath);
            if (! $downloadSuccessful || ! is_file($temporaryPath)) {
                throw new RuntimeException('The official yt-dlp binary could not be downloaded from GitHub.');
            }

            $checksums = Http::withUserAgent('AuroraArchive')
                ->connectTimeout(5)
                ->timeout(15)
                ->retry([250, 1000], throw: false)
                ->get("https://github.com/{$repository}/releases/latest/download/SHA2-256SUMS");
            if (! $checksums->successful() || ! preg_match('/^([a-f0-9]{64})\s+\*?'.preg_quote($asset, '/').'\s*$/mi', $checksums->body(), $matches)) {
                throw new RuntimeException('The official yt-dlp checksum could not be verified.');
            }
            if (! hash_equals(Str::lower($matches[1]), hash_file('sha256', $temporaryPath))) {
                throw new RuntimeException('The downloaded yt-dlp binary failed checksum verification.');
            }

            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($temporaryPath, 0755);
            }
            $version = $this->validateBinary($temporaryPath);

            $this->replaceBinary($temporaryPath, $binaryPath);
            $temporaryPath = null;

            return [
                'successful' => true,
                'message' => "Installed the official {$channel} yt-dlp release.",
                'version' => $version ?: $this->version(),
            ];
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'message' => $this->sanitise($exception->getMessage()),
                'version' => $this->version(),
            ];
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function validateBinary(string $binaryPath): string
    {
        $error = '';
        $attempts = PHP_OS_FAMILY === 'Windows' ? 5 : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $validation = new Process([$binaryPath, '--version']);
            $validation->setEnv($this->processEnvironment((string) config('auroraarchive.temp_root')));
            $validation->setTimeout(30)->run();
            if ($validation->isSuccessful()) {
                return trim($validation->getOutput());
            }

            $error = $validation->getErrorOutput();
            unset($validation);
            if ($attempt < $attempts) {
                usleep(250_000);
            }
        }

        throw new RuntimeException('The downloaded yt-dlp binary could not start: '.$this->sanitise($error));
    }

    private function releaseAssetName(): string
    {
        $architecture = Str::lower(php_uname('m'));

        return match (PHP_OS_FAMILY) {
            'Windows' => Str::contains($architecture, ['arm64', 'aarch64']) ? 'yt-dlp_arm64.exe' : (Str::contains($architecture, ['x86', 'i386', 'i686']) && ! Str::contains($architecture, ['64']) ? 'yt-dlp_x86.exe' : 'yt-dlp.exe'),
            'Linux' => Str::contains($architecture, ['arm64', 'aarch64']) ? 'yt-dlp_linux_aarch64' : (Str::contains($architecture, ['x86_64', 'amd64']) ? 'yt-dlp_linux' : throw new RuntimeException("Unsupported Linux architecture: {$architecture}.")),
            'Darwin' => 'yt-dlp_macos',
            default => throw new RuntimeException('Automatic yt-dlp updates are not supported on this operating system.'),
        };
    }

    private function resolveBinaryPath(): string
    {
        $configured = (string) config('auroraarchive.yt_dlp');
        if (is_file($configured)) {
            return realpath($configured) ?: $configured;
        }

        $extensions = PHP_OS_FAMILY === 'Windows' && pathinfo($configured, PATHINFO_EXTENSION) === ''
            ? ['', '.exe', '.cmd', '.bat']
            : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$configured.$extension;
                if (is_file($candidate)) {
                    return realpath($candidate) ?: $candidate;
                }
            }
        }

        throw new RuntimeException('The configured yt-dlp executable could not be located for replacement.');
    }

    private function replaceBinary(string $temporaryPath, string $binaryPath): void
    {
        if (! is_writable($binaryPath) || ! is_writable(dirname($binaryPath))) {
            throw new RuntimeException('The configured yt-dlp executable is not writable. Rebuild the application image to update it.');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            if (! $this->renameFile($temporaryPath, $binaryPath)) {
                throw new RuntimeException('The yt-dlp executable could not be replaced.');
            }

            return;
        }

        $backupPath = $binaryPath.'.aurora-backup';
        if (is_file($backupPath)) {
            unlink($backupPath);
        }
        if (! $this->renameFile($binaryPath, $backupPath)) {
            throw new RuntimeException('The existing yt-dlp executable could not be prepared for replacement.');
        }
        if (! $this->renameFile($temporaryPath, $binaryPath)) {
            $this->renameFile($backupPath, $binaryPath);
            throw new RuntimeException('The yt-dlp executable could not be replaced.');
        }
        unlink($backupPath);
    }

    private function renameFile(string $from, string $to): bool
    {
        $attempts = PHP_OS_FAMILY === 'Windows' ? 5 : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }
            if ($attempt < $attempts) {
                usleep(250_000);
            }
        }

        return false;
    }

    /** @param list<string> $arguments @return array{exit_code:int,stdout:string,stderr:string} */
    private function run(array $arguments, ?string $cookies = null, int $timeout = 120, bool $preserveStdout = false): array
    {
        $cookiePath = null;
        try {
            $tempRoot = (string) config('auroraarchive.temp_root');
            File::ensureDirectoryExists($tempRoot, 0700);
            if (! is_writable($tempRoot)) {
                throw new RuntimeException('The application temporary directory is not writable.');
            }

            if (filled($cookies)) {
                $cookiePath = tempnam($tempRoot, 'aurora-cookie-');
                if ($cookiePath === false) {
                    throw new RuntimeException('Unable to create temporary cookie file.');
                }
                file_put_contents($cookiePath, $cookies, LOCK_EX);
                chmod($cookiePath, 0600);
                $arguments = ['--cookies', $cookiePath, ...$arguments];
            }
            $arguments = [...$this->potProviderArguments(), ...$arguments];
            $process = new Process([config('auroraarchive.yt_dlp'), ...$arguments]);
            $process->setEnv($this->processEnvironment($tempRoot));
            $process->setTimeout($timeout)->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'stdout' => $preserveStdout ? $process->getOutput() : $this->sanitise($process->getOutput()),
                'stderr' => $this->sanitise($process->getErrorOutput()),
            ];
        } finally {
            if ($cookiePath !== null && is_file($cookiePath)) {
                unlink($cookiePath);
            }
        }
    }

    /** @return list<string> */
    private function potProviderArguments(): array
    {
        $pluginDirectory = config('auroraarchive.yt_dlp_plugin_dir');
        $providerUrl = config('auroraarchive.yt_dlp_pot_provider_url');
        if (blank($pluginDirectory) || blank($providerUrl)) {
            return [];
        }

        return [
            '--plugin-dirs',
            (string) $pluginDirectory,
            '--extractor-args',
            'youtubepot-bgutilhttp:base_url='.rtrim((string) $providerUrl, '/'),
        ];
    }

    private function potProviderConfigured(): bool
    {
        return filled(config('auroraarchive.yt_dlp_plugin_dir'))
            && filled(config('auroraarchive.yt_dlp_pot_provider_url'));
    }

    /** @return list<string> */
    private function potFallbackClients(): array
    {
        return ['mweb', 'web_creator'];
    }

    /** @return array<string, string> */
    private function processEnvironment(string $tempRoot): array
    {
        $environment = [
            'TMPDIR' => $tempRoot,
            'TMP' => $tempRoot,
            'TEMP' => $tempRoot,
            'PYTHONHASHSEED' => '0',
        ];

        if (PHP_OS_FAMILY !== 'Windows') {
            return $environment;
        }

        $systemRoot = getenv('SYSTEMROOT') ?: getenv('SystemRoot') ?: getenv('WINDIR') ?: getenv('windir') ?: 'C:\\Windows';
        $environment['SYSTEMROOT'] = $systemRoot;
        $environment['WINDIR'] = $systemRoot;

        foreach (['PATH', 'COMSPEC', 'PATHEXT'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function cookiesFor(?int $userId): ?string
    {
        $credential = $userId ? YoutubeCredential::query()->where('user_id', $userId)->first() : null;
        if ($credential) {
            return $credential->cookies;
        }
        $fallback = rtrim(config('auroraarchive.config_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'cookies.txt';

        return is_readable($fallback) ? file_get_contents($fallback) ?: null : null;
    }

    private function destination(Media $media): string
    {
        $path = $this->storagePath->absoluteDirectory($media);
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Media destination is not writable.');
        }

        return $path;
    }

    private function sanitise(string $value): string
    {
        return Str::limit(preg_replace('/(?i)(cookie|authorization|password)(\s*[:=]\s*)\S+/', '$1$2[redacted]', $value) ?? '', 50000, '');
    }
}
