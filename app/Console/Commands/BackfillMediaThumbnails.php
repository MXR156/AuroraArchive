<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaThumbnail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('archive:backfill-thumbnails
    {--local-only : Do not request published thumbnails from YouTube}
    {--force : Replace thumbnails that already exist in the canonical store}
    {--delay=200 : Milliseconds to wait between YouTube requests}')]
#[Description('Build the persistent media thumbnail library')]
class BackfillMediaThumbnails extends Command
{
    public function handle(MediaThumbnail $thumbnails): int
    {
        $online = ! $this->option('local-only');
        $delay = max(0, (int) $this->option('delay'));
        $stats = ['youtube' => 0, 'local' => 0, 'existing' => 0, 'failed' => 0];
        $total = Media::query()->whereHas('files')->count();

        if ($total === 0) {
            $this->info('No archived media files were found.');

            return self::SUCCESS;
        }

        $this->info('Writing canonical thumbnails to '.rtrim((string) config('auroraarchive.media_root'), '/\\').DIRECTORY_SEPARATOR.'Thumbs');
        $progress = $this->output->createProgressBar($total);
        $progress->start();

        Media::query()
            ->whereHas('files')
            ->with('files')
            ->lazyById(50)
            ->each(function (Media $media) use ($thumbnails, $online, $delay, &$stats, $progress): void {
                $result = null;
                try {
                    $result = $thumbnails->backfill($media, $online, (bool) $this->option('force'));
                    $stats[$result]++;
                } catch (Throwable $exception) {
                    $stats['failed']++;
                    $this->newLine();
                    $this->warn($media->youtube_id.': '.$exception->getMessage());
                }

                $progress->advance();
                if ($online && $result !== 'existing' && $delay > 0) {
                    usleep($delay * 1000);
                }
            });

        $progress->finish();
        $this->newLine(2);
        $this->table(['Videos', 'YouTube', 'Local fallback', 'Already present', 'Failed'], [[
            $total, $stats['youtube'], $stats['local'], $stats['existing'], $stats['failed'],
        ]]);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
