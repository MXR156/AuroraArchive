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
        $canonicalPath = $this->canonicalPath($media);
        if (is_file($canonicalPath) && filesize($canonicalPath) > 0) {
            return $canonicalPath;
        }

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
        $destination = $this->canonicalPath($media, createDirectory: true);

        foreach (['maxresdefault', 'hqdefault'] as $variant) {
            try {
                $response = Http::connectTimeout(5)
                    ->timeout(20)
                    ->retry(2, 250, throw: false)
                    ->get("https://i.ytimg.com/vi/{$media->youtube_id}/{$variant}.jpg");
            } catch (Throwable) {
                continue;
            }
            if (! $response->successful() || ! Str::startsWith((string) $response->header('Content-Type'), 'image/')) {
                continue;
            }

            $temporaryPath = $this->temporaryPath($destination);
            File::put($temporaryPath, $response->body());
            $dimensions = @getimagesize($temporaryPath);
            if (is_array($dimensions) && $dimensions[0] >= 320 && $this->isUsableGeneratedThumbnail($temporaryPath)) {
                $this->publish($temporaryPath, $destination);
                $this->recordCanonicalThumbnail($media);

                return true;
            }

            $this->removeGeneratedThumbnail($temporaryPath);
        }

        return false;
    }

    public function generate(Media $media): void
    {
        $destination = $this->canonicalPath($media, createDirectory: true);
        if (is_file($destination) && filesize($destination) > 0) {
            return;
        }

        foreach ($this->existingThumbnailCandidates($media) as $candidate) {
            if ($this->storeFromFile($media, $candidate)) {
                return;
            }
        }

        $mediaPath = $this->mediaPath($media);
        if ($mediaPath === null) {
            return;
        }

        $temporaryPath = $this->temporaryPath($destination);
        try {
            $this->extractAttachedPicture($mediaPath, $temporaryPath);
            if (! $this->isUsableGeneratedThumbnail($temporaryPath)) {
                $this->removeGeneratedThumbnail($temporaryPath);
                $this->extractFrame($mediaPath, $temporaryPath, '00:00:03');
            }
            if (! $this->isUsableGeneratedThumbnail($temporaryPath)) {
                $this->removeGeneratedThumbnail($temporaryPath);
                $this->extractFrame($mediaPath, $temporaryPath, '00:00:30');
            }
            if ($this->isUsableGeneratedThumbnail($temporaryPath)) {
                $this->publish($temporaryPath, $destination);
                $this->recordCanonicalThumbnail($media);
            }
        } catch (Throwable) {
            $this->removeGeneratedThumbnail($temporaryPath);
        }
    }

    public function storeFromFile(Media $media, string $sourcePath): bool
    {
        if (! is_file($sourcePath) || filesize($sourcePath) === 0) {
            return false;
        }

        $destination = $this->canonicalPath($media, createDirectory: true);
        $temporaryPath = $this->temporaryPath($destination);
        $dimensions = @getimagesize($sourcePath);
        if (is_array($dimensions) && ($dimensions[2] ?? null) === IMAGETYPE_JPEG) {
            File::copy($sourcePath, $temporaryPath);
        } else {
            $process = new Process([
                (string) config('auroraarchive.ffmpeg'), '-y', '-i', $sourcePath,
                '-frames:v', '1', '-vf', 'scale=1280:-2', '-q:v', '3', $temporaryPath,
            ]);
            $process->setTimeout(30)->run();
            if (! $process->isSuccessful()) {
                $this->removeGeneratedThumbnail($temporaryPath);

                return false;
            }
        }
        if (! $this->isUsableGeneratedThumbnail($temporaryPath)) {
            $this->removeGeneratedThumbnail($temporaryPath);

            return false;
        }

        $this->publish($temporaryPath, $destination);
        $this->recordCanonicalThumbnail($media);

        return true;
    }

    public function backfill(Media $media, bool $online = true, bool $force = false): string
    {
        $destination = $this->canonicalPath($media);
        $this->removeTemporaryFiles($destination);
        if ($force) {
            $this->removeGeneratedThumbnail($destination);
        } elseif (is_file($destination) && filesize($destination) > 0) {
            $this->recordCanonicalThumbnail($media);

            return 'existing';
        }

        if ($online && $this->refreshFromYoutube($media)) {
            return 'youtube';
        }

        $this->generate($media);

        return is_file($destination) && filesize($destination) > 0 ? 'local' : 'failed';
    }

    public function canonicalPath(Media $media, bool $createDirectory = false): string
    {
        $directory = rtrim((string) config('auroraarchive.media_root'), '/\\').DIRECTORY_SEPARATOR.'Thumbs';
        if ($createDirectory) {
            File::ensureDirectoryExists($directory);
        }

        $youtubeId = preg_replace('/[^A-Za-z0-9_-]/', '_', $media->youtube_id);

        return $directory.DIRECTORY_SEPARATOR.$youtubeId.'.jpg';
    }

    public function isCanonicalPath(Media $media, string $path): bool
    {
        $canonicalPath = realpath($this->canonicalPath($media));

        return $canonicalPath !== false && realpath($path) === $canonicalPath;
    }

    /** @return list<string> */
    private function existingThumbnailCandidates(Media $media): array
    {
        $candidates = [$this->youtubeThumbnailPath($media), $this->localThumbnailPath($media)];
        $mediaPath = $this->mediaPath($media);
        if ($mediaPath !== null) {
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
                $candidates[] = dirname($mediaPath).DIRECTORY_SEPARATOR.$sidecarPath;
            }
            $candidates[] = $this->cachePath($media, $mediaPath);
        }

        return array_values(array_filter($candidates, fn (mixed $path): bool => is_string($path) && is_file($path)));
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
        $relativePath = $media->relationLoaded('files')
            ? $media->files->first()?->path
            : $media->files()->value('path');

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

    private function temporaryPath(string $destination): string
    {
        return dirname($destination).DIRECTORY_SEPARATOR.'.'.basename($destination).'.'.bin2hex(random_bytes(6)).'.jpg';
    }

    private function publish(string $temporaryPath, string $destination): void
    {
        File::delete($this->validationPath($temporaryPath));
        if (is_file($destination)) {
            File::delete($destination);
        }
        File::move($temporaryPath, $destination);
    }

    private function recordCanonicalThumbnail(Media $media): void
    {
        $relativePath = 'Thumbs/'.$media->youtube_id.'.jpg';
        $metadata = $media->metadata ?? [];
        $thumbnailVersion = hash_file('sha256', $this->canonicalPath($media));
        if (Arr::get($metadata, 'local_thumbnail_path') === $relativePath
            && Arr::get($metadata, 'local_thumbnail_version') === $thumbnailVersion
            && $media->getRawOriginal('thumbnail_url') === route('media.thumbnail', $media, absolute: false)) {
            return;
        }

        Arr::set($metadata, 'local_thumbnail_path', $relativePath);
        Arr::set($metadata, 'local_thumbnail_version', $thumbnailVersion);
        $media->update([
            'thumbnail_url' => route('media.thumbnail', $media, absolute: false),
            'metadata' => $metadata,
        ]);
    }

    private function removeTemporaryFiles(string $destination): void
    {
        foreach (glob(dirname($destination).DIRECTORY_SEPARATOR.'.'.basename($destination).'.*.jpg*') ?: [] as $path) {
            File::delete($path);
        }
    }
}
