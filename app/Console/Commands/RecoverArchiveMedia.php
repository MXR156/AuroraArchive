<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ArchiveRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('archive:recover-media
    {--path= : Media directory; defaults to MEDIA_ROOT}
    {--write : Write recovered records to the database}
    {--online : Enrich recoverable records from YouTube}
    {--playlists : Reconstruct local playlists from Playlist/Channel/video folders}
    {--user= : User ID whose stored YouTube cookies should be used}')]
#[Description('Rebuild media records from surviving archive files')]
class RecoverArchiveMedia extends Command
{
    public function handle(ArchiveRecovery $recovery): int
    {
        $path = (string) ($this->option('path') ?: config('auroraarchive.media_root'));
        $userId = filled($this->option('user')) ? (int) $this->option('user') : null;
        if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
            $this->error('The selected user does not exist. Create the account first or omit --user.');

            return self::FAILURE;
        }

        if (! $this->option('write')) {
            $this->warn('Dry run only. Add --write after reviewing the results.');
        }

        try {
            $stats = $recovery->recover(
                $path,
                (bool) $this->option('write'),
                (bool) $this->option('online'),
                $userId,
                fn (string $message) => $this->line($message),
                (bool) $this->option('playlists'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Scanned', 'Recognised', 'Unrecognised', 'Created', 'Updated', 'Files', 'Online', 'Playlists', 'Memberships'], [[
            $stats['scanned'], $stats['recognised'], $stats['unrecognised'], $stats['media_created'],
            $stats['media_updated'], $stats['files_attached'], $stats['enriched'], $stats['playlists_created'], $stats['memberships_attached'],
        ]]);

        return self::SUCCESS;
    }
}
