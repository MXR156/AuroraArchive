<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TubeSyncImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportTubeSyncSources implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7200;

    /** @param list<string> $sourceUuids */
    public function __construct(public User $user, public array $sourceUuids, public bool $queueMissing) {}

    public function uniqueId(): string
    {
        $sourceUuids = $this->sourceUuids;
        sort($sourceUuids);

        return $this->user->id.':'.hash('sha256', implode('|', $sourceUuids).':'.(int) $this->queueMissing);
    }

    public function handle(TubeSyncImporter $importer): void
    {
        $summary = $importer->import($this->user, $this->sourceUuids, $this->queueMissing);

        Log::info('TubeSync background import completed.', ['user_id' => $this->user->id, ...$summary]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('TubeSync background import failed.', [
            'user_id' => $this->user->id,
            'source_uuids' => $this->sourceUuids,
            'error' => $exception?->getMessage(),
        ]);
    }
}
