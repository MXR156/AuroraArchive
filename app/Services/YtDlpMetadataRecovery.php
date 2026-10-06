<?php

namespace App\Services;

use App\Enums\MediaStatus;
use App\Models\Media;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class YtDlpMetadataRecovery
{
    /**
     * @return array{lines:int,malformed:int,records:int,unique_videos:int,created:int,updated:int}
     */
    public function recover(string $path, bool $write = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The yt-dlp metadata extract is not readable: '.$path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The yt-dlp metadata extract could not be opened.');
        }

        $stats = ['lines' => 0, 'malformed' => 0, 'records' => 0, 'unique_videos' => 0, 'created' => 0, 'updated' => 0];
        $videos = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $stats['lines']++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! is_array($record)) {
                    $stats['malformed']++;

                    continue;
                }

                $youtubeId = (string) Arr::get($record, 'id');
                if (preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId) !== 1) {
                    continue;
                }

                $stats['records']++;
                $videos[$youtubeId] = $this->merge($videos[$youtubeId] ?? [], $this->snapshot($record));
                $videos[$youtubeId]['snapshot_count'] = ($videos[$youtubeId]['snapshot_count'] ?? 0) + 1;
            }
        } finally {
            fclose($handle);
        }

        $stats['unique_videos'] = count($videos);
        if (! $write) {
            return $stats;
        }

        foreach ($videos as $youtubeId => $snapshot) {
            $medium = Media::query()->firstOrNew(['youtube_id' => $youtubeId]);
            $created = ! $medium->exists;
            $metadata = $medium->metadata ?? [];
            Arr::set($metadata, 'youtube.archive_snapshot', Arr::except($snapshot, ['snapshot_count']));
            Arr::set($metadata, 'recovery.yt_dlp_extract.imported_at', now()->toIso8601String());
            Arr::set($metadata, 'recovery.yt_dlp_extract.snapshot_count', $snapshot['snapshot_count']);

            $publishedAt = $this->publishedAt($snapshot);
            $medium->fill([
                'title' => Arr::get($metadata, 'manual.title') || blank($snapshot['title'] ?? null)
                    ? ($medium->title ?: $youtubeId)
                    : $snapshot['title'],
                'description' => Arr::get($metadata, 'manual.description') || blank($snapshot['description'] ?? null)
                    ? $medium->description
                    : $snapshot['description'],
                'channel_name' => Arr::get($metadata, 'manual.channel_name')
                    ? $medium->channel_name
                    : (($snapshot['channel'] ?? null) ?: ($snapshot['uploader'] ?? null) ?: $medium->channel_name),
                'channel_id' => ($snapshot['channel_id'] ?? null) ?: ($snapshot['uploader_id'] ?? null) ?: $medium->channel_id,
                'published_at' => $publishedAt ?: $medium->published_at,
                'duration_seconds' => filled($snapshot['duration'] ?? null) ? (int) $snapshot['duration'] : $medium->duration_seconds,
                'thumbnail_url' => $this->durableThumbnail($snapshot['thumbnail'] ?? null) ?: $medium->getRawOriginal('thumbnail_url'),
                'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
                'status' => $medium->files()->exists() ? MediaStatus::Downloaded : ($medium->status ?? MediaStatus::Discovered),
                'metadata' => $metadata,
            ])->save();
            $stats[$created ? 'created' : 'updated']++;
        }

        return $stats;
    }

    /** @return array<string, mixed> */
    private function snapshot(array $record): array
    {
        return Arr::only($record, [
            'title', 'description', 'channel', 'channel_id', 'channel_url', 'uploader', 'uploader_id',
            'uploader_url', 'timestamp', 'upload_date', 'duration', 'thumbnail', 'availability',
            'live_status', 'release_timestamp', 'release_date', 'webpage_url', 'original_url',
        ]);
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $incoming @return array<string, mixed> */
    private function merge(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (blank($value)) {
                continue;
            }
            if ($key === 'description') {
                if (Str::length((string) $value) > Str::length((string) ($current[$key] ?? ''))) {
                    $current[$key] = $value;
                }

                continue;
            }
            $current[$key] ??= $value;
        }

        return $current;
    }

    private function publishedAt(array $snapshot): ?Carbon
    {
        try {
            if (filled($snapshot['timestamp'] ?? null)) {
                return Carbon::createFromTimestamp((int) $snapshot['timestamp']);
            }
            if (filled($snapshot['upload_date'] ?? null)) {
                return Carbon::createFromFormat('Ymd', (string) $snapshot['upload_date'])->startOfDay();
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function durableThumbnail(mixed $url): ?string
    {
        if (! is_string($url) || ! Str::startsWith($url, ['https://i.ytimg.com/', 'https://img.youtube.com/'])) {
            return null;
        }

        return Str::before($url, '?');
    }
}
