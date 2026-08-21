<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaThumbnail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMediaThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public int $uniqueFor = 3600;

    public function __construct(public Media $media) {}

    public function uniqueId(): string
    {
        return (string) $this->media->id;
    }

    public function handle(MediaThumbnail $thumbnail): void
    {
        $thumbnail->generate($this->media);
    }
}
