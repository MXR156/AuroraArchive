<?php

namespace App\Services;

use App\Contracts\YoutubeDownloader;
use App\Models\Media;
use App\Models\Source;
use App\Models\YoutubeCredential;
use Illuminate\Support\Arr;
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
        $result = $this->run($arguments, $this->cookiesFor($source->user_id));
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
        $result = $this->run(['--newline', '--no-playlist', '--write-thumbnail', '--write-info-json', '--merge-output-format', 'mkv', '-o', $template, $media->original_url], $this->cookiesFor($media->source?->user_id), 7200);
        $result['files'] = array_values(array_filter(glob($directory.'/*') ?: [], fn (string $path): bool => Str::contains(basename($path), '['.$media->youtube_id.']')));
        $result['version'] = $this->version();

        return $result;
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

    /** @param list<string> $arguments @return array{exit_code:int,stdout:string,stderr:string} */
    private function run(array $arguments, ?string $cookies = null, int $timeout = 120): array
    {
        $cookiePath = null;
        try {
            if (filled($cookies)) {
                $cookiePath = tempnam(sys_get_temp_dir(), 'aurora-cookie-');
                if ($cookiePath === false) {
                    throw new RuntimeException('Unable to create temporary cookie file.');
                }
                file_put_contents($cookiePath, $cookies, LOCK_EX);
                chmod($cookiePath, 0600);
                $arguments = ['--cookies', $cookiePath, ...$arguments];
            }
            $process = new Process([config('auroraarchive.yt_dlp'), ...$arguments]);
            $process->setTimeout($timeout)->run();

            return ['exit_code' => $process->getExitCode() ?? 1, 'stdout' => $this->sanitise($process->getOutput()), 'stderr' => $this->sanitise($process->getErrorOutput())];
        } finally {
            if ($cookiePath !== null && is_file($cookiePath)) {
                unlink($cookiePath);
            }
        }
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
        $folder = Str::of($media->channel_name ?: 'Unsorted')->replace(['..', '/', '\\'], '-')->trim()->toString();
        $path = $root.DIRECTORY_SEPARATOR.$folder;
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
