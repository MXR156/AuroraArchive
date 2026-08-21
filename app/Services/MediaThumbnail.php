<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class MediaThumbnail
{
    public function path(Media $media): ?string
    {
        $localPath = $this->localThumbnailPath($media);
        if ($localPath !== null) {
            return $localPath;
        }

        $mediaPath = $this->mediaPath($media);
        if ($mediaPath === null) {
            return null;
        }

        $sidecarPath = collect(scandir(dirname($mediaPath)) ?: [])
            ->first(function (string $candidate) use ($media, $mediaPath): bool {
                $extension = Str::lower(pathinfo($candidate, PATHINFO_EXTENSION));

                return Str::contains($candidate, $media->youtube_id)
                    && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)
                    && is_file(dirname($mediaPath).DIRECTORY_SEPARATOR.$candidate);
            });
        if (is_string($sidecarPath)) {
            return dirname($mediaPath).DIRECTORY_SEPARATOR.$sidecarPath;
        }

        $cachePath = $this->cachePath($media, $mediaPath);
        if (is_file($cachePath) && filesize($cachePath) > 0) {
            return $cachePath;
        }

        return null;
    }

    public function generate(Media $media): void
    {
        if ($this->path($media) !== null) {
            return;
        }

        $mediaPath = $this->mediaPath($media);
        if ($mediaPath === null) {
            return;
        }

        $cachePath = $this->cachePath($media, $mediaPath);
        try {
            $this->extractAttachment($mediaPath, $cachePath);
            if (! is_file($cachePath) || filesize($cachePath) === 0) {
                $this->extractFrame($mediaPath, $cachePath);
            }
        } catch (Throwable) {
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }
    }

    private function localThumbnailPath(Media $media): ?string
    {
        $metadata = $media->metadata ?? [];
        $sources = Arr::get($metadata, 'tubesync.sources', []);
        $relativePath = Arr::get($metadata, 'local_thumbnail_path')
            ?: collect($sources)->pluck('thumbnail_path')->first(fn (mixed $path): bool => filled($path));

        return is_string($relativePath) ? $this->safeMediaPath($relativePath) : null;
    }

    private function mediaPath(Media $media): ?string
    {
        $relativePath = $media->files()->value('path');

        return is_string($relativePath) ? $this->safeMediaPath($relativePath) : null;
    }

    private function safeMediaPath(string $relativePath): ?string
    {
        $root = realpath((string) config('auroraarchive.media_root'));
        if ($root === false) {
            return null;
        }

        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        return $path !== false && is_file($path) && Str::startsWith($path, $root.DIRECTORY_SEPARATOR) ? $path : null;
    }

    private function cachePath(Media $media, string $mediaPath): string
    {
        $cacheDirectory = storage_path('app/thumbnails');
        File::ensureDirectoryExists($cacheDirectory);

        return $cacheDirectory.DIRECTORY_SEPARATOR.$media->id.'-'.filemtime($mediaPath).'.jpg';
    }

    private function extractAttachment(string $mediaPath, string $cachePath): void
    {
        $process = new Process([
            (string) config('auroraarchive.ffmpeg'), '-y', '-dump_attachment:t:0', $cachePath,
            '-i', $mediaPath, '-t', '0', '-f', 'null', '-',
        ]);
        $process->setTimeout(10)->run();
    }

    private function extractFrame(string $mediaPath, string $cachePath): void
    {
        $process = new Process([
            (string) config('auroraarchive.ffmpeg'), '-y', '-ss', '00:00:03', '-i', $mediaPath,
            '-frames:v', '1', '-vf', 'scale=640:-2', $cachePath,
        ]);
        $process->setTimeout(20)->run();
    }
}
