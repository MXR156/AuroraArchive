<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaThumbnail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshMediaThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [30, 120];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public Media $media)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return (string) $this->media->id;
    }

    public function handle(MediaThumbnail $thumbnail): void
    {
        $thumbnail->refreshFromYoutube($this->media);
    }
}
