<?php

namespace App\Jobs;

use App\Contracts\YoutubeDownloader;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

class CheckMediaAvailability implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public Media $media)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return (string) $this->media->id;
    }

    public function handle(YoutubeDownloader $youtube): void
    {
        $result = $youtube->checkAvailability($this->media->loadMissing('source'));
        $metadata = $this->media->metadata ?? [];
        Arr::set($metadata, 'youtube.availability_check_status', $result['status']);
        Arr::set($metadata, 'youtube.availability_checked_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.availability_check_reason', $result['reason']);
        if ($result['status'] === 'unavailable') {
            Arr::set($metadata, 'youtube.unavailable', true);
            Arr::set($metadata, 'youtube.unavailable_at', now()->toIso8601String());
        } elseif ($result['status'] === 'available') {
            Arr::set($metadata, 'youtube.unavailable', false);
            Arr::forget($metadata, 'youtube.unavailable_at');
        }

        $this->media->update(['metadata' => $metadata]);
    }

    public function failed(?Throwable $exception): void
    {
        $metadata = $this->media->metadata ?? [];
        Arr::set($metadata, 'youtube.availability_check_status', 'unknown');
        Arr::set($metadata, 'youtube.availability_checked_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.availability_check_reason', str($exception?->getMessage())->limit(500, '')->toString());
        $this->media->update(['metadata' => $metadata]);
    }
}
