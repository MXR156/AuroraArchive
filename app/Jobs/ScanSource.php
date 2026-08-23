<?php

namespace App\Jobs;

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaTombstone;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class ScanSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public Source $source) {}

    public function uniqueId(): string
    {
        return (string) $this->source->id;
    }

    public function handle(YoutubeDownloader $youtube): void
    {
        $entries = $youtube->discover($this->source);
        $tombstones = collect($entries)
            ->pluck('id')
            ->filter()
            ->unique()
            ->chunk(1000)
            ->flatMap(fn ($youtubeIds) => MediaTombstone::query()->whereIn('youtube_id', $youtubeIds)->pluck('youtube_id'))
            ->all();

        foreach ($entries as $position => $entry) {
            $youtubeId = (string) $entry['id'];
            if (in_array($youtubeId, $tombstones, true)) {
                continue;
            }

            $medium = Media::query()->firstOrNew(['youtube_id' => $youtubeId]);
            $isNew = ! $medium->exists;
            $metadata = array_replace_recursive($medium->metadata ?? [], Arr::only($entry, ['availability', 'live_status']));
            $isUnavailable = $this->isUnavailable($entry);
            if ($isUnavailable) {
                Arr::set($metadata, 'youtube.unavailable', true);
                Arr::set($metadata, 'youtube.unavailable_at', now()->toIso8601String());
            } else {
                Arr::forget($metadata, ['youtube.unavailable', 'youtube.unavailable_at']);
            }

            $preserveArchivedMetadata = $isUnavailable && ! $isNew && $medium->status === MediaStatus::Downloaded;
            $medium->fill([
                'source_id' => $isNew ? $this->source->id : $medium->source_id,
                'title' => $preserveArchivedMetadata || Arr::get($metadata, 'manual.title') ? $medium->title : (string) ($entry['title'] ?? $youtubeId),
                'description' => $preserveArchivedMetadata || Arr::get($metadata, 'manual.description') ? $medium->description : Arr::get($entry, 'description'),
                'channel_name' => $preserveArchivedMetadata || Arr::get($metadata, 'manual.channel_name') ? $medium->channel_name : (Arr::get($entry, 'channel') ?: Arr::get($entry, 'uploader')),
                'channel_id' => $preserveArchivedMetadata ? $medium->channel_id : (Arr::get($entry, 'channel_id') ?: $medium->channel_id),
                'published_at' => $preserveArchivedMetadata || blank(Arr::get($entry, 'timestamp')) ? $medium->published_at : now()->setTimestamp((int) Arr::get($entry, 'timestamp')),
                'duration_seconds' => $preserveArchivedMetadata ? $medium->duration_seconds : (Arr::get($entry, 'duration') ?: $medium->duration_seconds),
                'thumbnail_url' => $preserveArchivedMetadata ? $medium->getRawOriginal('thumbnail_url') : (Arr::get($entry, 'thumbnail') ?: $medium->getRawOriginal('thumbnail_url')),
                'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
                'status' => $isUnavailable && ! $preserveArchivedMetadata ? MediaStatus::Failed : ($isNew ? MediaStatus::Discovered : $medium->status),
                'metadata' => $metadata,
            ])->save();
            if ($isNew && ! $isUnavailable && $this->source->auto_download) {
                $medium->update(['status' => MediaStatus::Queued]);
                DownloadMedia::dispatch($medium);
            }
            $this->source->playlistMedia()->syncWithoutDetaching([
                $medium->id => ['position' => (int) (Arr::get($entry, 'playlist_index') ?: $position + 1)],
            ]);
        }
        $this->source->update(['last_scanned_at' => now(), 'next_scan_at' => now()->addMinutes($this->source->scan_interval_minutes), 'last_scan_status' => 'successful', 'last_scan_error' => null]);
    }

    /** @param array<string, mixed> $entry */
    private function isUnavailable(array $entry): bool
    {
        $availability = Str::lower((string) Arr::get($entry, 'availability'));
        $title = Str::lower((string) Arr::get($entry, 'title'));

        return in_array($availability, ['private', 'unavailable', 'needs_auth', 'subscriber_only', 'premium_only'], true)
            || Str::contains($title, ['[deleted video]', '[private video]', '[unavailable video]']);
    }

    public function failed(?Throwable $exception): void
    {
        $this->source->update(['last_scan_status' => 'failed', 'last_scan_error' => $exception?->getMessage()]);
    }
}
