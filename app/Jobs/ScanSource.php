<?php

namespace App\Jobs;

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
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
        foreach ($youtube->discover($this->source) as $position => $entry) {
            $medium = Media::query()->firstOrNew(['youtube_id' => (string) $entry['id']]);
            $isNew = ! $medium->exists;
            $metadata = array_replace_recursive($medium->metadata ?? [], Arr::only($entry, ['availability', 'live_status']));
            $medium->fill([
                'source_id' => $isNew ? $this->source->id : $medium->source_id,
                'title' => Arr::get($metadata, 'manual.title') ? $medium->title : (string) ($entry['title'] ?? $entry['id']),
                'description' => Arr::get($metadata, 'manual.description') ? $medium->description : Arr::get($entry, 'description'),
                'channel_name' => Arr::get($metadata, 'manual.channel_name') ? $medium->channel_name : (Arr::get($entry, 'channel') ?: Arr::get($entry, 'uploader')),
                'channel_id' => Arr::get($entry, 'channel_id'),
                'published_at' => filled(Arr::get($entry, 'timestamp')) ? now()->setTimestamp((int) Arr::get($entry, 'timestamp')) : null,
                'duration_seconds' => Arr::get($entry, 'duration'),
                'thumbnail_url' => Arr::get($entry, 'thumbnail'),
                'original_url' => Arr::get($entry, 'webpage_url') ?: 'https://www.youtube.com/watch?v='.$entry['id'],
                'status' => $isNew ? MediaStatus::Discovered : $medium->status,
                'metadata' => $metadata,
            ])->save();
            if ($isNew && $this->source->auto_download) {
                $medium->update(['status' => MediaStatus::Queued]);
                DownloadMedia::dispatch($medium);
            }
            $this->source->playlistMedia()->syncWithoutDetaching([
                $medium->id => ['position' => (int) (Arr::get($entry, 'playlist_index') ?: $position + 1)],
            ]);
        }
        $this->source->update(['last_scanned_at' => now(), 'next_scan_at' => now()->addMinutes($this->source->scan_interval_minutes), 'last_scan_status' => 'successful', 'last_scan_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->source->update(['last_scan_status' => 'failed', 'last_scan_error' => $exception?->getMessage()]);
    }
}
