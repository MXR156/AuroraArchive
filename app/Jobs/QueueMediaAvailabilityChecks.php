<?php

namespace App\Jobs;

use App\Models\Media;
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

    public function handle(): void
    {
        Media::query()
            ->whereHas('files')
            ->select('id')
            ->chunkById(250, fn ($media) => $media->each(fn (Media $medium) => CheckMediaAvailability::dispatch($medium)));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
