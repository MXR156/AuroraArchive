<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\AvailabilityAudit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class QueueMediaAvailabilityChecks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60, 300];

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(public ?string $auditId = null)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return 'youtube-availability-audit';
    }

    public function handle(AvailabilityAudit $audit): void
    {
        $query = Media::query()->whereHas('files');
        if ($this->auditId !== null) {
            $audit->start($this->auditId, (clone $query)->count());
        }

        $query
            ->select('id')
            ->chunkById(250, fn ($media) => $media->each(function (Media $medium): void {
                CheckMediaAvailability::dispatch($medium, $this->auditId);
                GenerateMediaThumbnail::dispatch($medium);
            }));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
