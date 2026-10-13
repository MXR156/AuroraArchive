<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Arr;

class RecordMediaAvailability
{
    /** @param array{status:'available'|'unavailable'|'unknown',reason:?string,evidence?:array<string, mixed>} $result */
    public function handle(Media $media, array $result): void
    {
        $media->refresh();
        $metadata = $media->metadata ?? [];
        Arr::set($metadata, 'youtube.availability_check_status', $result['status']);
        Arr::set($metadata, 'youtube.availability_checked_at', now()->toIso8601String());
        Arr::set($metadata, 'youtube.availability_check_reason', $result['reason']);
        Arr::set($metadata, 'youtube.availability_check_evidence', $result['evidence'] ?? []);

        if ($result['status'] === 'unavailable') {
            Arr::set($metadata, 'youtube.unavailable', true);
            Arr::set($metadata, 'youtube.unavailable_at', now()->toIso8601String());
        } elseif ($result['status'] === 'available') {
            Arr::set($metadata, 'youtube.unavailable', false);
            Arr::forget($metadata, 'youtube.unavailable_at');
        }

        $media->update(['metadata' => $metadata]);
    }
}
