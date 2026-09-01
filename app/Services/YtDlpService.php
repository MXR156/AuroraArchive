<?php

namespace App\Services;

use App\Contracts\YoutubeDownloader;
use App\Models\Media;
use App\Models\Source;
use App\Models\YoutubeCredential;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class YtDlpService implements YoutubeDownloader
{
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
        $template = $directory.'/%(upload_date>%Y-%m-%d)s - %(title).160B [%(id)s].%(ext)s';
        $arguments = [
            '--newline',
            '--no-playlist',
            '--format',
            'bestvideo[vcodec^=avc1]+bestaudio[ext=m4a]/best[vcodec^=avc1][ext=mp4]/bestvideo+bestaudio/best',
            '--embed-thumbnail',
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
        if (filled($cookies) && $this->requiresUnauthenticatedRetry($result)) {
            $retry = $this->run($arguments, null, 7200);
            if ($retry['exit_code'] === 0) {
                $result = $retry;
            } else {
                $result['stderr'] .= PHP_EOL.'Unauthenticated retry:'.PHP_EOL.$retry['stderr'];
            }
        }
        $result['files'] = array_values(array_filter(glob($directory.'/*') ?: [], fn (string $path): bool => Str::contains(basename($path), '['.$media->youtube_id.']')));
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

        return $this->availabilityResult($result);
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
        ])) {
            return [
                'status' => 'unavailable',
                'reason' => Str::limit(trim(Str::afterLast($result['stderr'], 'ERROR:')), 500, ''),
            ];
        }

        return ['status' => 'unknown', 'reason' => Str::limit(trim($result['stderr']), 500, '')];
    }

    /** @param array{exit_code:int,stdout:string,stderr:string} $result */
    private function requiresUnauthenticatedRetry(array $result): bool
    {
        return $result['exit_code'] !== 0
            && Str::contains(Str::lower($result['stderr']), [
                'playback on other websites has been disabled',
                'embedding disabled',
            ]);
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
        try {
            $result = $this->run(['--update-to', $channel], null, 120);
            $message = trim($result['stdout'].PHP_EOL.$result['stderr']);

            return [
                'successful' => $result['exit_code'] === 0,
                'message' => $message ?: 'yt-dlp did not return an update status.',
                'version' => $this->version(),
            ];
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'message' => $this->sanitise($exception->getMessage()),
                'version' => $this->version(),
            ];
        }
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
        $root = rtrim(config('auroraarchive.media_root'), DIRECTORY_SEPARATOR);
        $sourceFolder = $this->safeFolderName($media->source?->name ?: $media->channel_name ?: 'Unsorted');
        $path = $root.DIRECTORY_SEPARATOR.$sourceFolder;
        if ($media->source?->type === 'playlist' && filled($media->channel_name)) {
            $path .= DIRECTORY_SEPARATOR.$this->safeFolderName($media->channel_name);
        }
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Media destination is not writable.');
        }

        return $path;
    }

    private function safeFolderName(string $name): string
    {
        return Str::of($name)->replace(['..', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-')->trim()->limit(120, '')->toString() ?: 'Unsorted';
    }

    private function sanitise(string $value): string
    {
        return Str::limit(preg_replace('/(?i)(cookie|authorization|password)(\s*[:=]\s*)\S+/', '$1$2[redacted]', $value) ?? '', 50000, '');
    }
}
