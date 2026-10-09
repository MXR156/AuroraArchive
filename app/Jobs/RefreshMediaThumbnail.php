<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaThumbnail;
use App\Services\YtDlpService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

class RefreshMediaThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30, 120];

    public int $timeout = 420;

    public int $uniqueFor = 600;

    public function __construct(public Media $media, public ?int $userId = null)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return (string) $this->media->id;
    }

    public function handle(YtDlpService $youtube, MediaThumbnail $thumbnail): void
    {
        $remote = $youtube->metadataForRecovery($this->media->youtube_id, $this->userId);
        if ($remote !== null) {
            $this->updateMetadata($remote);
        } else {
            $this->recordFailedMetadataRefresh();
        }

        $thumbnail->refreshFromYoutube($this->media);
    }

    /** @param array<string, mixed> $remote */
    private function updateMetadata(array $remote): void
    {
        $this->media->refresh();
        $metadata = $this->media->metadata ?? [];
        $manualTitle = (bool) Arr::get($metadata, 'manual.title');
        $manualDescription = (bool) Arr::get($metadata, 'manual.description');
        $manualChannelName = (bool) Arr::get($metadata, 'manual.channel_name');
        $manualChannelId = (bool) Arr::get($metadata, 'manual.channel_id');

        Arr::set($metadata, 'youtube.archive_snapshot', Arr::only($remote, [
            'title', 'description', 'channel', 'channel_id', 'channel_url', 'uploader', 'uploader_id',
            'uploader_url', 'timestamp', 'upload_date', 'duration', 'thumbnail', 'availability',
        ]));
        Arr::set($metadata, 'youtube.metadata_refreshed_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.metadata_refresh_status', 'updated');
        Arr::set($metadata, 'youtube.availability_checked_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.availability_check_status', 'available');
        Arr::set($metadata, 'youtube.availability_check_reason', null);
        Arr::set($metadata, 'youtube.availability_check_evidence.metadata_refresh', [
            'status' => 'available',
            'reason' => null,
        ]);
        Arr::set($metadata, 'youtube.availability_checked_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.availability_check_status', 'available');
        Arr::set($metadata, 'youtube.availability_check_reason', null);
        Arr::set($metadata, 'youtube.availability_check_evidence.metadata_refresh', [
            'status' => 'available',
            'reason' => null,
        ]);

        foreach (['channel_url', 'uploader_url'] as $key) {
            if (filled(Arr::get($remote, $key))) {
                Arr::set($metadata, $key, Arr::get($remote, $key));
            }
        }

        $channelName = Arr::get($remote, 'channel') ?: Arr::get($remote, 'uploader');
        $channelId = Arr::get($remote, 'channel_id') ?: Arr::get($remote, 'uploader_id');
        $publishedAt = $this->publishedAt($remote);

        $this->media->update([
            'title' => ! $manualTitle && filled(Arr::get($remote, 'title')) ? Arr::get($remote, 'title') : $this->media->title,
            'description' => ! $manualDescription && filled(Arr::get($remote, 'description')) ? Arr::get($remote, 'description') : $this->media->description,
            'channel_name' => ! $manualChannelName && filled($channelName) ? $channelName : $this->media->channel_name,
            'channel_id' => ! $manualChannelId && filled($channelId) ? $channelId : $this->media->channel_id,
            'published_at' => $publishedAt ?? $this->media->published_at,
            'duration_seconds' => filled(Arr::get($remote, 'duration')) ? (int) Arr::get($remote, 'duration') : $this->media->duration_seconds,
            'thumbnail_url' => filled(Arr::get($remote, 'thumbnail')) ? Arr::get($remote, 'thumbnail') : $this->media->getRawOriginal('thumbnail_url'),
            'original_url' => $this->media->youtubeVideoUrl(),
            'metadata' => $metadata,
        ]);
    }

    private function recordFailedMetadataRefresh(): void
    {
        $this->media->refresh();
        $metadata = $this->media->metadata ?? [];
        Arr::set($metadata, 'youtube.metadata_refreshed_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.metadata_refresh_status', 'no_metadata');
        $this->media->update(['metadata' => $metadata]);
    }

    /** @param array<string, mixed> $remote */
    private function publishedAt(array $remote): ?Carbon
    {
        try {
            if (filled(Arr::get($remote, 'timestamp'))) {
                return Carbon::createFromTimestamp((int) Arr::get($remote, 'timestamp'));
            }

            if (preg_match('/^\d{8}$/', (string) Arr::get($remote, 'upload_date')) === 1) {
                return Carbon::createFromFormat('Ymd', (string) Arr::get($remote, 'upload_date'))->startOfDay();
            }
        } catch (Throwable) {
        }

        return null;
    }
}
