<?php

namespace App\Services;

use App\Models\Media;

class RecoverMediaUploaderNames
{
    public function handle(): int
    {
        $recovered = 0;
        Media::query()
            ->where(fn ($query) => $query->whereNull('channel_name')->orWhere('channel_name', ''))
            ->whereHas('files')
            ->with('files:id,media_id,path')
            ->chunkById(100, function ($media) use (&$recovered): void {
                foreach ($media as $medium) {
                    $path = str_replace('\\', '/', (string) $medium->files->first()?->path);
                    $segments = array_values(array_filter(explode('/', $path), fn (string $segment): bool => $segment !== ''));
                    if (count($segments) < 2) {
                        continue;
                    }

                    $medium->update(['channel_name' => $segments[count($segments) - 2]]);
                    $recovered++;
                }
            });

        return $recovered;
    }
}
