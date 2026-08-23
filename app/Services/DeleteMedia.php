<?php

namespace App\Services;

use App\Models\Media;
use App\Models\MediaTombstone;
use Illuminate\Support\Str;
use RuntimeException;

class DeleteMedia
{
    public function handle(Media $media): void
    {
        $paths = $this->associatedPaths($media->loadMissing('files'));
        foreach ($paths as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('The media file could not be deleted. Check the media directory permissions.');
            }
        }

        foreach (glob(storage_path('app/thumbnails/'.$media->id.'-*')) ?: [] as $cachePath) {
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }

        MediaTombstone::query()->updateOrCreate(
            ['youtube_id' => $media->youtube_id],
            ['reason' => 'deleted_by_user'],
        );
        $media->delete();
    }

    /** @return list<string> */
    private function associatedPaths(Media $media): array
    {
        $root = realpath((string) config('auroraarchive.media_root'));
        if ($root === false) {
            return [];
        }

        $paths = [];
        foreach ($media->files as $file) {
            $path = $this->safePath($root, $file->path);
            if ($path === null) {
                continue;
            }

            $paths[] = $path;
            foreach (scandir(dirname($path)) ?: [] as $candidate) {
                $candidatePath = dirname($path).DIRECTORY_SEPARATOR.$candidate;
                if (is_file($candidatePath) && Str::contains($candidate, $media->youtube_id)) {
                    $paths[] = $candidatePath;
                }
            }
        }

        $thumbnailPath = data_get($media->metadata, 'local_thumbnail_path');
        if (is_string($thumbnailPath) && ($path = $this->safePath($root, $thumbnailPath)) !== null) {
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private function safePath(string $root, string $relativePath): ?string
    {
        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        return $path !== false && Str::startsWith($path, $root.DIRECTORY_SEPARATOR) ? $path : null;
    }
}
