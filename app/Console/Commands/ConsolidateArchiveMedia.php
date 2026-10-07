<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ArchiveConsolidation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('archive:consolidate-media
    {source=/recovery : Read-only directory containing old media}
    {--destination= : New media directory; defaults to MEDIA_ROOT}
    {--write : Copy, verify, and attach files}
    {--manifest= : Write a JSON migration manifest}
    {--playlists : Reconstruct local playlists from folder paths}
    {--user= : User ID that owns reconstructed playlists}')]
#[Description('Copy and verify recovered media into the AuroraArchive library')]
class ConsolidateArchiveMedia extends Command
{
    public function handle(ArchiveConsolidation $consolidation): int
    {
        $userId = filled($this->option('user')) ? (int) $this->option('user') : null;
        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            $this->error('The selected user does not exist.');

            return self::FAILURE;
        }
        if ($this->option('playlists') && $userId === null) {
            $this->error('Add --user=<id> when using --playlists.');

            return self::FAILURE;
        }
        if (! $this->option('write')) {
            $this->warn('Dry run only. No media or database records will be changed.');
        }

        try {
            $stats = $consolidation->consolidate(
                (string) $this->argument('source'),
                (string) ($this->option('destination') ?: config('auroraarchive.media_root')),
                (bool) $this->option('write'),
                $userId,
                (bool) $this->option('playlists'),
                filled($this->option('manifest')) ? (string) $this->option('manifest') : null,
                fn (string $message) => $this->line($message),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Scanned', 'Recognised', 'Unique', 'Duplicates', 'Unrecognised', 'Copied', 'Existing', 'Conflicts', 'Failed', 'Attached', 'Playlists', 'Memberships'],
            [[
                $stats['scanned'], $stats['recognised'], $stats['unique_videos'], $stats['duplicates'], $stats['unrecognised'],
                $stats['copied'], $stats['existing'], $stats['conflicts'], $stats['failed'], $stats['files_attached'],
                $stats['playlists_created'], $stats['memberships_attached'],
            ]],
        );

        return $stats['failed'] > 0 || $stats['conflicts'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
