<?php

namespace App\Console\Commands;

use App\Services\MediaOrganiser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('archive:organise-media
    {--write : Move files and update their database paths}
    {--manifest= : Write a JSON manifest of planned or completed moves}')]
#[Description('Organise archived videos into stable channel ID folders')]
class OrganiseMedia extends Command
{
    public function handle(MediaOrganiser $organiser): int
    {
        $write = (bool) $this->option('write');
        if (! $write) {
            $this->warn('Dry run only. No files or database records will be changed.');
        }

        try {
            $stats = $organiser->organise(
                $write,
                filled($this->option('manifest')) ? (string) $this->option('manifest') : null,
                fn (string $message) => $this->line($message),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Scanned', 'Planned', 'Moved', 'Existing', 'Deduplicated', 'Conflicts', 'Missing', 'Failed'], [[
            $stats['scanned'], $stats['planned'], $stats['moved'], $stats['existing'], $stats['deduplicated'],
            $stats['conflicts'], $stats['missing'], $stats['failed'],
        ]]);

        return $stats['conflicts'] > 0 || $stats['missing'] > 0 || $stats['failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
