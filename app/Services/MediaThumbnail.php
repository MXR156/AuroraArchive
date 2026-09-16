<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class MediaThumbnail
{
    public function path(Media $media): ?string
    {
        $youtubeThumbnailPath = $this->youtubeThumbnailPath($media);
        if (is_file($youtubeThumbnailPath) && $this->isUsableGeneratedThumbnail($youtubeThumbnailPath)) {
            return $youtubeThumbnailPath;
        }

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
                $mediaName = pathinfo($mediaPath, PATHINFO_FILENAME);
                $candidateName = pathinfo($candidate, PATHINFO_FILENAME);

                return (Str::contains($candidate, $media->youtube_id) || Str::startsWith($candidateName, $mediaName))
                    && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)
                    && is_file(dirname($mediaPath).DIRECTORY_SEPARATOR.$candidate);
            });
        if (is_string($sidecarPath)) {
            return dirname($mediaPath).DIRECTORY_SEPARATOR.$sidecarPath;
        }

        $cachePath = $this->cachePath($media, $mediaPath);
        if (is_file($cachePath) && filesize($cachePath) > 0) {
            if ($this->isUsableGeneratedThumbnail($cachePath)) {
                return $cachePath;
            }

            @unlink($cachePath);
            @unlink($this->validationPath($cachePath));
        }

        return null;
    }

    public function refreshFromYoutube(Media $media): bool
    {
        $cachePath = $this->youtubeThumbnailPath($media);
        $this->removeGeneratedThumbnail($cachePath);

        foreach (['maxresdefault', 'hqdefault'] as $variant) {
            $response = Http::connectTimeout(5)
                ->timeout(20)
                ->retry(2, 250, throw: false)
                ->get("https://i.ytimg.com/vi/{$media->youtube_id}/{$variant}.jpg");
            if (! $response->successful() || ! Str::startsWith((string) $response->header('Content-Type'), 'image/')) {
                continue;
            }

            File::put($cachePath, $response->body());
            $dimensions = @getimagesize($cachePath);
            if (is_array($dimensions) && $dimensions[0] >= 320 && $this->isUsableGeneratedThumbnail($cachePath)) {
                return true;
            }

            $this->removeGeneratedThumbnail($cachePath);
        }

        return false;
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
            if (! $this->isUsableGeneratedThumbnail($cachePath)) {
                $this->removeGeneratedThumbnail($cachePath);
                $this->extractAttachedPicture($mediaPath, $cachePath);
            }
            if (! $this->isUsableGeneratedThumbnail($cachePath)) {
                $this->removeGeneratedThumbnail($cachePath);
                $this->extractFrame($mediaPath, $cachePath, '00:00:03');
            }
            if (! $this->isUsableGeneratedThumbnail($cachePath)) {
                $this->removeGeneratedThumbnail($cachePath);
                $this->extractFrame($mediaPath, $cachePath, '00:00:30');
            }
        } catch (Throwable) {
            $this->removeGeneratedThumbnail($cachePath);
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

    private function youtubeThumbnailPath(Media $media): string
    {
        $cacheDirectory = storage_path('app/thumbnails');
        File::ensureDirectoryExists($cacheDirectory);

        return $cacheDirectory.DIRECTORY_SEPARATOR.$media->id.'-youtube.jpg';
    }

    private function extractAttachment(string $mediaPath, string $cachePath): void
    {
        $this->removeEmptyCacheFile($cachePath);
        $process = new Process([
            (string) config('auroraarchive.ffmpeg'), '-y', '-dump_attachment:t:0', $cachePath,
            '-i', $mediaPath, '-t', '0', '-f', 'null', '-',
        ]);
        $process->setTimeout(10)->run();
    }

    private function extractAttachedPicture(string $mediaPath, string $cachePath): void
    {
        $this->removeEmptyCacheFile($cachePath);
        $process = new Process([
            (string) config('auroraarchive.ffmpeg'), '-y', '-i', $mediaPath,
            '-map', '0:v:disp:attached_pic?', '-frames:v', '1', '-vf', 'scale=640:-2', $cachePath,
        ]);
        $process->setTimeout(15)->run();
    }

    private function extractFrame(string $mediaPath, string $cachePath, string $position): void
    {
        $this->removeEmptyCacheFile($cachePath);
        $process = new Process([
            (string) config('auroraarchive.ffmpeg'), '-y', '-ss', $position, '-i', $mediaPath,
            '-frames:v', '1', '-vf', 'scale=640:-2', $cachePath,
        ]);
        $process->setTimeout(20)->run();
    }

    private function isUsableGeneratedThumbnail(string $path): bool
    {
        if (! is_file($path) || filesize($path) === 0) {
            return false;
        }

        $validationPath = $this->validationPath($path);
        if (is_file($validationPath) && filemtime($validationPath) >= filemtime($path)) {
            return true;
        }

        $imageContents = file_get_contents($path);
        $image = is_string($imageContents) && function_exists('imagecreatefromstring')
            ? @imagecreatefromstring($imageContents)
            : false;
        if ($image !== false) {
            $sample = imagecreatetruecolor(1, 1);
            imagecopyresampled($sample, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
            $colour = imagecolorat($sample, 0, 0);
            imagedestroy($sample);
            imagedestroy($image);
            $luminance = (0.2126 * (($colour >> 16) & 0xFF))
                + (0.7152 * (($colour >> 8) & 0xFF))
                + (0.0722 * ($colour & 0xFF));
        } else {
            $luminance = null;
        }

        if ($luminance !== null && $luminance <= 24) {
            return false;
        }

        File::put($validationPath, '');

        return true;
    }

    private function validationPath(string $cachePath): string
    {
        return $cachePath.'.validated';
    }

    private function removeGeneratedThumbnail(string $cachePath): void
    {
        if (is_file($cachePath)) {
            unlink($cachePath);
        }
        if (is_file($this->validationPath($cachePath))) {
            unlink($this->validationPath($cachePath));
        }
    }

    private function removeEmptyCacheFile(string $cachePath): void
    {
        if (is_file($cachePath) && filesize($cachePath) === 0) {
            unlink($cachePath);
        }
    }
}
