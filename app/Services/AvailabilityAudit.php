<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AvailabilityAudit
{
    public function queue(int $userId): string
    {
        $auditId = (string) Str::uuid();
        $this->put($auditId, [
            'id' => $auditId,
            'status' => 'queued',
            'total' => 0,
            'processed' => 0,
            'available' => 0,
            'unavailable' => 0,
            'unknown' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);
        Cache::put($this->latestKey($userId), $auditId, now()->addDays(7));

        return $auditId;
    }

    public function start(string $auditId, int $total): void
    {
        $this->update($auditId, function (array $audit) use ($total): array {
            $audit['status'] = $total === 0 ? 'completed' : 'running';
            $audit['total'] = $total;
            $audit['started_at'] = now()->toIso8601String();
            $audit['completed_at'] = $total === 0 ? now()->toIso8601String() : null;

            return $audit;
        });
    }

    public function record(string $auditId, string $status): void
    {
        $this->update($auditId, function (array $audit) use ($status): array {
            $audit['processed']++;
            $audit[in_array($status, ['available', 'unavailable'], true) ? $status : 'unknown']++;
            if ($audit['processed'] >= $audit['total']) {
                $audit['status'] = 'completed';
                $audit['completed_at'] = now()->toIso8601String();
            }

            return $audit;
        });
    }

    /** @return array<string, mixed>|null */
    public function latest(int $userId): ?array
    {
        $auditId = Cache::get($this->latestKey($userId));

        return is_string($auditId) ? Cache::get($this->auditKey($auditId)) : null;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $callback */
    private function update(string $auditId, callable $callback): void
    {
        Cache::lock($this->auditKey($auditId).':lock', 10)->block(5, function () use ($auditId, $callback): void {
            $audit = Cache::get($this->auditKey($auditId));
            if (is_array($audit)) {
                $this->put($auditId, $callback($audit));
            }
        });
    }

    /** @param array<string, mixed> $audit */
    private function put(string $auditId, array $audit): void
    {
        Cache::put($this->auditKey($auditId), $audit, now()->addDays(7));
    }

    private function auditKey(string $auditId): string
    {
        return 'youtube-availability-audit:'.$auditId;
    }

    private function latestKey(int $userId): string
    {
        return 'youtube-availability-audit:latest:user:'.$userId;
    }
}
