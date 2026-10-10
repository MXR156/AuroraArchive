<?php

namespace App\Services;

use App\Models\Media;
use App\Models\MediaFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MediaOrganiser
{
    public function __construct(private MediaStoragePath $paths) {}

    /**
     * @param  callable(string): void|null  $report
     * @return array{scanned:int,planned:int,moved:int,existing:int,deduplicated:int,conflicts:int,missing:int,failed:int}
     */
    public function organise(bool $write = false, ?string $manifestPath = null, ?callable $report = null): array
    {
        $root = realpath((string) config('auroraarchive.media_root'));
        if ($root === false) {
            throw new RuntimeException('The media root does not exist.');
        }
        if ($write && ! is_writable($root)) {
            throw new RuntimeException('The media root is not writable.');
        }

        $stats = ['scanned' => 0, 'planned' => 0, 'moved' => 0, 'existing' => 0, 'deduplicated' => 0, 'conflicts' => 0, 'missing' => 0, 'failed' => 0];
        $manifest = [];

        Media::query()->whereHas('files')->with('files')->lazyById(50)->each(
            function (Media $media) use ($root, $write, $report, &$stats, &$manifest): void {
                foreach ($media->files as $mediaFile) {
                    $entry = $this->organiseFile($root, $media, $mediaFile, $write);
                    $stats['scanned']++;
                    $stats[$entry['result']]++;
                    $manifest[] = $entry;
                    if ($report !== null) {
                        $report(Str::upper($entry['result']).': '.$entry['source'].' -> '.$entry['destination']);
                    }
                }
            },
        );

        if ($manifestPath !== null) {
            File::ensureDirectoryExists(dirname($manifestPath));
            File::put($manifestPath, json_encode([
                'generated_at' => now()->toIso8601String(),
                'media_root' => $root,
                'write' => $write,
                'stats' => $stats,
                'files' => $manifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        return $stats;
    }

    /** @return array{scanned:int,planned:int,moved:int,existing:int,deduplicated:int,conflicts:int,missing:int,failed:int} */
    public function organiseMedium(Media $media): array
    {
        $root = realpath((string) config('auroraarchive.media_root'));
        if ($root === false || ! is_writable($root)) {
            throw new RuntimeException('The media root is not writable.');
        }

        $stats = ['scanned' => 0, 'planned' => 0, 'moved' => 0, 'existing' => 0, 'deduplicated' => 0, 'conflicts' => 0, 'missing' => 0, 'failed' => 0];
        foreach ($media->loadMissing('files')->files as $mediaFile) {
            $entry = $this->organiseFile($root, $media, $mediaFile, true);
            $stats['scanned']++;
            $stats[$entry['result']]++;
        }

        return $stats;
    }

    /** @return array{media_id:int,youtube_id:string,source:string,destination:string,result:string,error?:string} */
    private function organiseFile(string $root, Media $media, MediaFile $mediaFile, bool $write): array
    {
        $sourceRelative = str_replace('\\', '/', $mediaFile->path);
        $extension = pathinfo($sourceRelative, PATHINFO_EXTENSION);
        $destinationRelative = $this->paths->relativeFile($media, $extension);
        $entry = [
            'media_id' => $media->id,
            'youtube_id' => $media->youtube_id,
            'source' => $sourceRelative,
            'destination' => $destinationRelative,
            'result' => $write ? 'moved' : 'planned',
        ];
        $source = $this->safeExistingPath($root, $sourceRelative);
        if ($source === null) {
            $entry['result'] = 'missing';

            return $entry;
        }
        if ($sourceRelative === $destinationRelative) {
            $entry['result'] = 'existing';

            return $entry;
        }
        $destination = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $destinationRelative);
        if (is_file($destination)) {
            $sourceHash = hash_file('sha256', $source);
            $destinationHash = hash_file('sha256', $destination);
            if ($sourceHash === false || $destinationHash === false) {
                $entry['result'] = 'failed';
                $entry['error'] = 'One of the files could not be checksummed.';

                return $entry;
            }
            if (! hash_equals($sourceHash, $destinationHash)) {
                $entry['result'] = 'conflicts';

                return $entry;
            }
            if ($write) {
                $mediaFile->update(['path' => $destinationRelative]);
                File::delete($source);
                $this->removeEmptyDirectories(dirname($source), $root);
                $entry['result'] = 'deduplicated';
            }

            return $entry;
        }
        if (! $write) {
            return $entry;
        }

        try {
            File::ensureDirectoryExists(dirname($destination));
            if (! rename($source, $destination)) {
                throw new RuntimeException('The file could not be moved.');
            }
            try {
                $mediaFile->update(['path' => $destinationRelative]);
            } catch (Throwable $exception) {
                rename($destination, $source);
                throw $exception;
            }
            $this->removeEmptyDirectories(dirname($source), $root);
        } catch (Throwable $exception) {
            $entry['result'] = 'failed';
            $entry['error'] = Str::limit($exception->getMessage(), 500, '');
        }

        return $entry;
    }

    private function safeExistingPath(string $root, string $relativePath): ?string
    {
        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        return $path !== false && is_file($path) && Str::startsWith($path, $root.DIRECTORY_SEPARATOR) ? $path : null;
    }

    private function removeEmptyDirectories(string $directory, string $root): void
    {
        while ($directory !== $root && Str::startsWith($directory, $root.DIRECTORY_SEPARATOR)) {
            if (! @rmdir($directory)) {
                return;
            }
            $directory = dirname($directory);
        }
    }
}
