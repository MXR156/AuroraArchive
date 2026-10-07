<?php

namespace App\Services;

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\Playlist;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class ArchiveConsolidation
{
    private const MEDIA_EXTENSIONS = ['mkv', 'mp4', 'webm', 'mov', 'm4v'];

    /**
     * @param  callable(string): void|null  $report
     * @return array{scanned:int,recognised:int,unrecognised:int,unique_videos:int,duplicates:int,planned:int,copied:int,existing:int,conflicts:int,failed:int,files_attached:int,playlists_created:int,memberships_attached:int}
     */
    public function consolidate(string $sourcePath, string $destinationPath, bool $write = false, ?int $userId = null, bool $createPlaylists = false, ?string $manifestPath = null, ?callable $report = null): array
    {
        [$sourceRoot, $destinationRoot] = $this->validatedRoots($sourcePath, $destinationPath);
        if ($createPlaylists && $userId === null) {
            throw new RuntimeException('A user ID is required when reconstructing playlists.');
        }

        $stats = ['scanned' => 0, 'recognised' => 0, 'unrecognised' => 0, 'unique_videos' => 0, 'duplicates' => 0, 'planned' => 0, 'copied' => 0, 'existing' => 0, 'conflicts' => 0, 'failed' => 0, 'files_attached' => 0, 'playlists_created' => 0, 'memberships_attached' => 0];
        $candidates = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! in_array(Str::lower($file->getExtension()), self::MEDIA_EXTENSIONS, true)) {
                continue;
            }

            $stats['scanned']++;
            $candidate = $this->candidate($file, $sourceRoot);
            if ($candidate['youtube_id'] === null) {
                $stats['unrecognised']++;
                if ($report !== null) {
                    $report('Unrecognised: '.$candidate['relative_path']);
                }

                continue;
            }

            $stats['recognised']++;
            $candidates[$candidate['youtube_id']][] = $candidate;
        }

        $stats['unique_videos'] = count($candidates);
        $stats['duplicates'] = $stats['recognised'] - $stats['unique_videos'];
        $manifest = [];
        $playlistPositions = [];
        ksort($candidates);

        foreach ($candidates as $youtubeId => $videoCandidates) {
            usort($videoCandidates, fn (array $left, array $right): int => [$right['pixels'], $right['size']] <=> [$left['pixels'], $left['size']]);
            $selected = $videoCandidates[0];
            $medium = Media::query()->where('youtube_id', $youtubeId)->first();
            $playlists = collect($videoCandidates)->pluck('playlist_name')->filter()->unique()->values()->all();
            $selected['playlist_name'] ??= $playlists[0] ?? null;
            $relativeDestination = $this->destination($selected, $medium);
            $absoluteDestination = $destinationRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDestination);
            $entry = [
                'youtube_id' => $youtubeId,
                'source' => $selected['relative_path'],
                'destination' => $relativeDestination,
                'candidate_count' => count($videoCandidates),
                'size_bytes' => $selected['size'],
                'width' => $selected['width'],
                'height' => $selected['height'],
                'playlists' => $playlists,
                'result' => $write ? 'pending' : 'planned',
            ];
            $stats['planned']++;

            if ($write) {
                try {
                    $result = $this->copyVerified($selected['absolute_path'], $absoluteDestination);
                    $entry['result'] = $result;
                    $stats[$result]++;
                    if (in_array($result, ['copied', 'existing'], true)) {
                        $medium ??= $this->createRecoveredMedium($youtubeId, $selected);
                        $mediaFile = MediaFile::query()->firstOrNew(['media_id' => $medium->id, 'path' => $relativeDestination]);
                        $wasCreated = ! $mediaFile->exists;
                        $mediaFile->fill([
                            'mime_type' => mime_content_type($absoluteDestination) ?: null,
                            'size_bytes' => filesize($absoluteDestination) ?: null,
                            'width' => $selected['width'],
                            'height' => $selected['height'],
                        ])->save();
                        $medium->update(['status' => MediaStatus::Downloaded]);
                        if ($wasCreated) {
                            $stats['files_attached']++;
                        }
                        if ($createPlaylists) {
                            $this->attachPlaylists($medium, $playlists, $userId, $playlistPositions, $stats);
                        }
                    }
                } catch (Throwable $exception) {
                    $stats['failed']++;
                    $entry['result'] = 'failed';
                    $entry['error'] = Str::limit($exception->getMessage(), 500, '');
                }
            }

            $manifest[] = $entry;
            if ($report !== null) {
                $report(Str::upper($entry['result']).": {$entry['source']} -> {$entry['destination']}");
            }
        }

        if ($manifestPath !== null) {
            File::ensureDirectoryExists(dirname($manifestPath));
            File::put($manifestPath, json_encode(['generated_at' => now()->toIso8601String(), 'source' => $sourceRoot, 'destination' => $destinationRoot, 'stats' => $stats, 'files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        return $stats;
    }

    /** @return array{0:string,1:string} */
    private function validatedRoots(string $sourcePath, string $destinationPath): array
    {
        $source = realpath($sourcePath);
        $destination = realpath($destinationPath);
        if ($source === false || ! is_dir($source)) {
            throw new RuntimeException('The recovery source does not exist: '.$sourcePath);
        }
        if ($destination === false || ! is_dir($destination)) {
            throw new RuntimeException('The media destination does not exist: '.$destinationPath);
        }
        $normalise = fn (string $path): string => PHP_OS_FAMILY === 'Windows' ? Str::lower($path) : $path;
        $sourceCompared = $normalise(rtrim($source, DIRECTORY_SEPARATOR));
        $destinationCompared = $normalise(rtrim($destination, DIRECTORY_SEPARATOR));
        if ($sourceCompared === $destinationCompared
            || Str::startsWith($sourceCompared.DIRECTORY_SEPARATOR, $destinationCompared.DIRECTORY_SEPARATOR)
            || Str::startsWith($destinationCompared.DIRECTORY_SEPARATOR, $sourceCompared.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Source and destination must be separate directories, and neither may contain the other.');
        }
        if (! is_writable($destination)) {
            throw new RuntimeException('The media destination is not writable: '.$destinationPath);
        }

        return [$source, $destination];
    }

    /** @return array<string, mixed> */
    private function candidate(SplFileInfo $file, string $sourceRoot): array
    {
        $absolutePath = $file->getRealPath();
        $relativePath = str_replace('\\', '/', Str::after($absolutePath, rtrim($sourceRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
        $probe = $this->probe($absolutePath);
        $tags = collect(Arr::get($probe, 'format.tags', []))->mapWithKeys(fn (mixed $value, mixed $key): array => [Str::lower((string) $key) => $value])->all();
        $video = collect(Arr::get($probe, 'streams', []))->firstWhere('codec_type', 'video');
        $youtubeId = $this->youtubeId(implode(' ', array_filter([$file->getBasename(), Arr::get($tags, 'purl'), Arr::get($tags, 'comment')], 'is_string')));
        $segments = explode('/', $relativePath);
        if (in_array(Str::lower($segments[0] ?? ''), ['downloads', 'video', 'videos', 'media'], true)) {
            array_shift($segments);
        }

        return [
            'youtube_id' => $youtubeId,
            'absolute_path' => $absolutePath,
            'relative_path' => $relativePath,
            'playlist_name' => count($segments) >= 3 ? $segments[0] : null,
            'folder_channel' => count($segments) >= 2 ? $segments[count($segments) - 2] : null,
            'filename_title' => $this->titleFromFilename($file->getBasename('.'.$file->getExtension())),
            'extension' => Str::lower($file->getExtension()),
            'size' => $file->getSize(),
            'width' => is_array($video) ? (int) Arr::get($video, 'width') : null,
            'height' => is_array($video) ? (int) Arr::get($video, 'height') : null,
            'pixels' => is_array($video) ? (int) Arr::get($video, 'width') * (int) Arr::get($video, 'height') : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        try {
            $process = new Process([(string) config('auroraarchive.ffprobe', 'ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $path]);
            $process->setTimeout(60);
            $process->run();
            $decoded = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function youtubeId(string $value): ?string
    {
        foreach (['/youtu(?:\.be\/|be\.com\/(?:watch\?v=|shorts\/|embed\/))([A-Za-z0-9_-]{11})/i', '/\[([A-Za-z0-9_-]{11})\]/', '/_([A-Za-z0-9_-]{11})_(?:\d{3,4}p|audio)/i'] as $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function destination(array $candidate, ?Media $medium): string
    {
        $playlist = $candidate['playlist_name'] ?: 'Recovered';
        $channel = $medium?->channel_name ?: $candidate['folder_channel'] ?: 'Unknown channel';
        $title = $medium?->title ?: $candidate['filename_title'] ?: $candidate['youtube_id'];
        $date = $medium?->published_at?->format('Y-m-d');
        $filename = ($date ? $date.' - ' : '').$this->safeName($title, 150).' ['.$candidate['youtube_id'].'].'.$candidate['extension'];

        return $this->safeName($playlist, 100).'/'.$this->safeName($channel, 100).'/'.$filename;
    }

    private function safeName(string $value, int $limit): string
    {
        return Str::of($value)->replace(['..', '/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-')->trim()->limit($limit, '')->toString() ?: 'Unknown';
    }

    private function titleFromFilename(string $filename): string
    {
        $title = preg_replace('/^\d{4}-\d{2}-\d{2}[ _-]+/', '', $filename) ?? $filename;
        $title = preg_replace('/\s*\[[A-Za-z0-9_-]{11}\]$/', '', $title) ?? $title;
        $title = preg_replace('/_([A-Za-z0-9_-]{11})_(?:\d{3,4}p|audio).*$/i', '', $title) ?? $title;

        return Str::squish(str_replace('_', ' ', $title));
    }

    private function copyVerified(string $source, string $destination): string
    {
        $sourceHash = hash_file('sha256', $source);
        if ($sourceHash === false) {
            throw new RuntimeException('Could not checksum source file.');
        }
        if (is_file($destination)) {
            return hash_equals($sourceHash, (string) hash_file('sha256', $destination)) ? 'existing' : 'conflicts';
        }

        File::ensureDirectoryExists(dirname($destination));
        $temporary = $destination.'.'.Str::random(12).'.part';
        try {
            if (! copy($source, $temporary)) {
                throw new RuntimeException('Copy failed.');
            }
            $copiedHash = hash_file('sha256', $temporary);
            if ($copiedHash === false || ! hash_equals($sourceHash, $copiedHash)) {
                throw new RuntimeException('Copied file failed SHA-256 verification.');
            }
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Verified file could not be moved into place.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return 'copied';
    }

    private function createRecoveredMedium(string $youtubeId, array $candidate): Media
    {
        return Media::query()->create([
            'youtube_id' => $youtubeId,
            'title' => $candidate['filename_title'] ?: $youtubeId,
            'channel_name' => $candidate['folder_channel'],
            'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
            'status' => MediaStatus::Downloaded,
            'metadata' => ['recovery' => ['filesystem_only' => true, 'recovered_at' => now()->toIso8601String()]],
        ]);
    }

    /** @param list<string> $playlistNames @param array<int, int> $positions @param array<string, int> $stats */
    private function attachPlaylists(Media $medium, array $playlistNames, int $userId, array &$positions, array &$stats): void
    {
        foreach ($playlistNames as $playlistName) {
            $playlist = Playlist::query()->firstOrCreate(['user_id' => $userId, 'name' => $playlistName]);
            if ($playlist->wasRecentlyCreated) {
                $stats['playlists_created']++;
            }
            if ($playlist->media()->whereKey($medium->id)->exists()) {
                continue;
            }
            $positions[$playlist->id] = ($positions[$playlist->id] ?? (int) $playlist->media()->max('media_playlist.position')) + 1;
            $playlist->media()->attach($medium->id, ['position' => $positions[$playlist->id]]);
            $stats['memberships_attached']++;
        }
    }
}
