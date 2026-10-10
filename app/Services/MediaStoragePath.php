<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Str;

class MediaStoragePath
{
    public function absoluteDirectory(Media $media): string
    {
        return rtrim((string) config('auroraarchive.media_root'), '/\\')
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->relativeDirectory($media));
    }

    public function relativeDirectory(Media $media): string
    {
        return 'Videos/'.$this->channelDirectory($media);
    }

    public function relativeFile(Media $media, string $extension): string
    {
        $date = $media->published_at?->format('Y-m-d');
        $title = $this->safeName($media->title ?: $media->youtube_id, 150);
        $youtubeId = $this->safeName($media->youtube_id, 64);
        $safeExtension = preg_replace('/[^A-Za-z0-9]/', '', ltrim($extension, '.')) ?: 'bin';
        $filename = ($date ? $date.' - ' : '').$title.' ['.$youtubeId.'].'.Str::lower($safeExtension);

        return $this->relativeDirectory($media).'/'.$filename;
    }

    private function channelDirectory(Media $media): string
    {
        return filled($media->channel_id) ? $this->safeName($media->channel_id, 120) : 'Orphaned';
    }

    private function safeName(string $value, int $limit): string
    {
        return Str::of($value)
            ->replace(['..', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-')
            ->trim()
            ->limit($limit, '')
            ->toString() ?: 'Unknown';
    }
}
