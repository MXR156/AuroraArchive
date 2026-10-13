<?php

namespace App\Jobs;

use App\Contracts\YoutubeDownloader;
use App\Models\Media;
use App\Services\AvailabilityAudit;
use App\Services\RecordMediaAvailability;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CheckMediaAvailability implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public Media $media, public ?string $auditId = null)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return $this->media->id.':'.($this->auditId ?? 'scheduled');
    }

    public function handle(YoutubeDownloader $youtube, AvailabilityAudit $audit, RecordMediaAvailability $recordAvailability): void
    {
        $result = $youtube->checkAvailability($this->media->loadMissing('source'));
        $recordAvailability->handle($this->media, $result);
        if ($this->auditId !== null) {
            $audit->record($this->auditId, $result['status']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $metadata = $this->media->metadata ?? [];
        Arr::set($metadata, 'youtube.availability_check_status', 'unknown');
        Arr::set($metadata, 'youtube.availability_checked_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.availability_check_reason', str($exception?->getMessage())->limit(500, '')->toString());
        $this->media->update(['metadata' => $metadata]);
        if ($this->auditId !== null) {
            app(AvailabilityAudit::class)->record($this->auditId, 'unknown');
        }
    }
}
