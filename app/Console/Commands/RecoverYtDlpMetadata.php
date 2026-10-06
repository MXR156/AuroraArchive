<?php

namespace App\Console\Commands;

use App\Services\YtDlpMetadataRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('archive:recover-metadata
    {file : Path to the yt-dlp JSON/string extract}
    {--write : Write recovered records to the database}')]
#[Description('Rebuild video metadata records from a yt-dlp extract')]
class RecoverYtDlpMetadata extends Command
{
    public function handle(YtDlpMetadataRecovery $recovery): int
    {
        if (! $this->option('write')) {
            $this->warn('Dry run only. Add --write after reviewing the totals.');
        }

        try {
            $stats = $recovery->recover((string) $this->argument('file'), (bool) $this->option('write'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Lines', 'Malformed', 'Records', 'Unique videos', 'Created', 'Updated'], [[
            $stats['lines'], $stats['malformed'], $stats['records'], $stats['unique_videos'], $stats['created'], $stats['updated'],
        ]]);

        return self::SUCCESS;
    }
}
