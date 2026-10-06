<?php

namespace App\Services;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\Playlist;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class ArchiveRecovery
{
    private const MEDIA_EXTENSIONS = ['mkv', 'mp4', 'webm', 'mov', 'm4v'];

    public function __construct(private YtDlpService $youtube) {}

    /**
     * @param  callable(string): void|null  $report
     * @return array{scanned:int,recognised:int,unrecognised:int,media_created:int,media_updated:int,files_attached:int,enriched:int,playlists_created:int,memberships_attached:int}
     */
    public function recover(string $path, bool $write = false, bool $online = false, ?int $userId = null, ?callable $report = null, bool $createPlaylists = false): array
    {
        $root = realpath($path);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('The media path does not exist or is not a directory: '.$path);
        }

        if ($createPlaylists && $userId === null) {
            throw new RuntimeException('A user ID is required when reconstructing playlists.');
        }

        $stats = ['scanned' => 0, 'recognised' => 0, 'unrecognised' => 0, 'media_created' => 0, 'media_updated' => 0, 'files_attached' => 0, 'enriched' => 0, 'playlists_created' => 0, 'memberships_attached' => 0];
        $candidates = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! in_array(Str::lower($file->getExtension()), self::MEDIA_EXTENSIONS, true)) {
                continue;
            }

            $stats['scanned']++;
            $local = $this->localMetadata($file, $root);
            if ($local['youtube_id'] === null) {
                $stats['unrecognised']++;
                if ($report !== null) {
                    $report('Skipped (no YouTube ID): '.$local['relative_path']);
                }

                continue;
            }

            $stats['recognised']++;
            $candidates[$local['youtube_id']][] = $local;
        }

        uasort($candidates, fn (array $left, array $right): int => $left[0]['relative_path'] <=> $right[0]['relative_path']);
        $playlistPositions = [];
        foreach ($candidates as $youtubeId => $files) {
            $primary = $files[0];
            $remote = null;
            if ($online) {
                try {
                    $remote = $this->youtube->metadataForRecovery($youtubeId, $userId);
                } catch (Throwable $exception) {
                    if ($report !== null) {
                        $report("YouTube enrichment failed for {$youtubeId}: ".Str::limit($exception->getMessage(), 200));
                    }
                }
            }
            if ($remote !== null) {
                $stats['enriched']++;
            }

            if (! $write) {
                if ($report !== null) {
                    $report("Would recover {$youtubeId}: ".$this->title($primary, $remote));
                }

                continue;
            }

            $medium = Media::query()->firstOrNew(['youtube_id' => $youtubeId]);
            $created = ! $medium->exists;
            $duration = Arr::get($remote, 'duration') ?: $primary['duration'] ?: $medium->duration_seconds;
            $metadata = $medium->metadata ?? [];
            Arr::set($metadata, 'recovery.folder', $primary['folder']);
            Arr::set($metadata, 'recovery.recovered_at', now()->toIso8601String());
            Arr::set($metadata, 'recovery.online_enriched', $remote !== null);
            if ($remote !== null) {
                Arr::set($metadata, 'youtube.archive_snapshot', Arr::only($remote, [
                    'title', 'description', 'channel', 'channel_id', 'channel_url', 'uploader', 'uploader_id',
                    'uploader_url', 'timestamp', 'upload_date', 'duration', 'thumbnail', 'availability',
                ]));
            }

            $medium->fill([
                'title' => Arr::get($remote, 'title') ?: ($created ? $this->title($primary, null) : $medium->title),
                'description' => Arr::get($remote, 'description') ?: $medium->description ?: $primary['description'],
                'channel_name' => Arr::get($remote, 'channel') ?: Arr::get($remote, 'uploader') ?: $medium->channel_name ?: $primary['channel_name'],
                'channel_id' => Arr::get($remote, 'channel_id') ?: Arr::get($remote, 'uploader_id') ?: $medium->channel_id,
                'published_at' => $remote !== null
                    ? ($this->publishedAt($primary, $remote) ?: $medium->published_at)
                    : ($medium->published_at ?: $this->publishedAt($primary, null)),
                'duration_seconds' => filled($duration) ? (int) $duration : null,
                'thumbnail_url' => Arr::get($remote, 'thumbnail') ?: $medium->getRawOriginal('thumbnail_url'),
                'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
                'status' => MediaStatus::Downloaded,
                'metadata' => $metadata,
            ])->save();
            $stats[$created ? 'media_created' : 'media_updated']++;

            foreach ($files as $file) {
                $mediaFile = MediaFile::query()->firstOrNew(['media_id' => $medium->id, 'path' => $file['relative_path']]);
                $wasCreated = ! $mediaFile->exists;
                $mediaFile->fill([
                    'mime_type' => $file['mime_type'], 'size_bytes' => $file['size'],
                    'width' => $file['width'], 'height' => $file['height'],
                ])->save();
                if ($wasCreated) {
                    $stats['files_attached']++;
                }
            }

            if ($createPlaylists) {
                foreach (collect($files)->pluck('playlist_name')->filter()->unique() as $playlistName) {
                    $playlist = Playlist::query()->firstOrCreate(['user_id' => $userId, 'name' => $playlistName]);
                    if ($playlist->wasRecentlyCreated) {
                        $stats['playlists_created']++;
                    }
                    if (! $playlist->media()->whereKey($medium->id)->exists()) {
                        $playlistPositions[$playlist->id] = ($playlistPositions[$playlist->id] ?? (int) $playlist->media()->max('media_playlist.position')) + 1;
                        $playlist->media()->attach($medium->id, ['position' => $playlistPositions[$playlist->id]]);
                        $stats['memberships_attached']++;
                    }
                }
            }
        }

        return $stats;
    }

    /** @return array<string, mixed> */
    private function localMetadata(SplFileInfo $file, string $root): array
    {
        $absolutePath = $file->getRealPath();
        $relativePath = str_replace('\\', '/', Str::after($absolutePath, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
        $probe = $this->probe($absolutePath);
        $tags = collect(Arr::get($probe, 'format.tags', []))->mapWithKeys(fn (mixed $value, mixed $key): array => [Str::lower((string) $key) => $value])->all();
        $video = collect(Arr::get($probe, 'streams', []))->firstWhere('codec_type', 'video');
        $youtubeId = $this->youtubeId(implode(' ', array_filter([
            $file->getBasename(), Arr::get($tags, 'purl'), Arr::get($tags, 'comment'), Arr::get($tags, 'description'),
        ], 'is_string')));
        $folder = str_replace('\\', '/', dirname($relativePath));
        $segments = explode('/', $relativePath);
        $channelName = basename(dirname($absolutePath));

        return [
            'youtube_id' => $youtubeId,
            'relative_path' => $relativePath,
            'folder' => $folder === '.' ? null : $folder,
            'playlist_name' => count($segments) >= 3 ? $segments[0] : null,
            'title' => is_string(Arr::get($tags, 'title')) ? Arr::get($tags, 'title') : $this->titleFromFilename($file->getBasename('.'.$file->getExtension())),
            'description' => Arr::get($tags, 'description') ?: Arr::get($tags, 'comment'),
            'channel_name' => Arr::get($tags, 'artist') ?: ($channelName !== basename($root) ? $channelName : null),
            'published_at' => Arr::get($tags, 'date') ?: Arr::get($tags, 'creation_time'),
            'duration' => filled(Arr::get($probe, 'format.duration')) ? (int) round((float) Arr::get($probe, 'format.duration')) : null,
            'width' => is_array($video) ? Arr::get($video, 'width') : null,
            'height' => is_array($video) ? Arr::get($video, 'height') : null,
            'mime_type' => mime_content_type($absolutePath) ?: null,
            'size' => $file->getSize(),
        ];
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        try {
            $process = new Process([(string) config('auroraarchive.ffprobe', 'ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path]);
            $process->setTimeout(30);
            $process->run();
            $result = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;

            return is_array($result) ? $result : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function youtubeId(string $value): ?string
    {
        $patterns = [
            '/youtu(?:\.be\/|be\.com\/(?:watch\?v=|shorts\/|embed\/))([A-Za-z0-9_-]{11})/i',
            '/\[([A-Za-z0-9_-]{11})\]/',
            '/_([A-Za-z0-9_-]{11})_(?:\d{3,4}p|audio)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function titleFromFilename(string $filename): string
    {
        $title = preg_replace('/^\d{4}-\d{2}-\d{2}[ _-]+/', '', $filename) ?? $filename;
        $title = preg_replace('/\s*\[[A-Za-z0-9_-]{11}\]$/', '', $title) ?? $title;
        $title = preg_replace('/_([A-Za-z0-9_-]{11})_(?:\d{3,4}p|audio).*$/i', '', $title) ?? $title;

        return Str::squish(str_replace('_', ' ', $title));
    }

    /** @param array<string, mixed>|null $remote */
    private function title(array $local, ?array $remote): string
    {
        return (string) (Arr::get($remote, 'title') ?: $local['title'] ?: $local['youtube_id']);
    }

    /** @param array<string, mixed>|null $remote */
    private function publishedAt(array $local, ?array $remote): ?Carbon
    {
        try {
            if (filled(Arr::get($remote, 'timestamp'))) {
                return Carbon::createFromTimestamp((int) Arr::get($remote, 'timestamp'));
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', basename($local['relative_path']), $matches) === 1) {
                return Carbon::parse($matches[1])->startOfDay();
            }
            if (filled($local['published_at'])) {
                return Carbon::parse($local['published_at']);
            }
        } catch (Throwable) {
        }

        return null;
    }
}
