<?php

namespace App\Jobs;

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Models\DownloadAttempt;
use App\Models\Media;
use App\Models\MediaFile;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DownloadMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 1800];

    public int $timeout = 7200;

    public int $uniqueFor = 7200;

    public function __construct(public Media $media) {}

    public function uniqueId(): string
    {
        return (string) $this->media->id;
    }

    public function handle(YoutubeDownloader $youtube): void
    {
        $this->media->update(['status' => MediaStatus::Downloading]);
        $attempt = DownloadAttempt::create(['media_id' => $this->media->id, 'attempt_number' => $this->media->attempts()->count() + 1, 'status' => 'running', 'started_at' => now()]);
        $result = $youtube->download($this->media->loadMissing('source'));
        $category = $this->category($result['stderr']);
        $attempt->update(['status' => $result['exit_code'] === 0 ? 'successful' : 'failed', 'finished_at' => now(), 'exit_code' => $result['exit_code'], 'yt_dlp_version' => $result['version'], 'error_category' => $result['exit_code'] === 0 ? null : $category, 'stdout' => $result['stdout'], 'stderr' => $result['stderr']]);
        if ($result['exit_code'] !== 0) {
            $this->media->update(['status' => MediaStatus::Failed]);
            throw new RuntimeException('yt-dlp failed: '.$category);
        }
        foreach ($result['files'] as $path) {
            if (is_file($path) && ! Str::endsWith($path, ['.json', '.jpg', '.jpeg', '.webp', '.part'])) {
                MediaFile::updateOrCreate(['media_id' => $this->media->id, 'path' => Str::after(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', config('auroraarchive.media_root')), '/').'/')], ['mime_type' => mime_content_type($path) ?: null, 'size_bytes' => filesize($path) ?: null]);
            }
        }
        $thumbnail = collect($result['files'])->first(fn (string $path): bool => is_file($path) && Str::endsWith(Str::lower($path), ['.jpg', '.jpeg', '.png', '.webp', '.avif']));
        $metadata = $this->media->metadata ?? [];
        if ($thumbnail !== null) {
            Arr::set($metadata, 'local_thumbnail_path', Str::after(str_replace('\\', '/', $thumbnail), rtrim(str_replace('\\', '/', config('auroraarchive.media_root')), '/').'/'));
        }
        $this->media->update([
            'status' => MediaStatus::Downloaded,
            'thumbnail_url' => $thumbnail === null ? $this->media->thumbnail_url : route('media.thumbnail', $this->media, absolute: false),
            'metadata' => $metadata,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->media->update(['status' => MediaStatus::Failed]);
    }

    private function category(string $error): string
    {
        $error = Str::lower($error);

        return match (true) {
            Str::contains($error, ['sign in', 'cookies']) => 'Authentication', Str::contains($error, ['429', 'too many requests']) => 'Rate limiting', Str::contains($error, ['private', 'unavailable']) => 'Unavailable/private', Str::contains($error, ['dns', 'network', 'timed out']) => 'Network', Str::contains($error, ['javascript', 'deno']) => 'JavaScript challenge', Str::contains($error, ['ffmpeg']) => 'FFmpeg', Str::contains($error, ['permission denied', 'no space']) => 'Filesystem', Str::contains($error, ['extractor']) => 'Extractor', default => 'Unknown'
        };
    }
}
