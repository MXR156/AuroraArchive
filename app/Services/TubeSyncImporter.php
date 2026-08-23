<?php

namespace App\Services;

use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Jobs\GenerateMediaThumbnail;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\MediaTombstone;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TubeSyncImporter
{
    /** @var array<string, array{media:?string,thumbnail:?string}>|null */
    private ?array $localFileIndex = null;

    /** @var list<string> */
    private array $youtubeIds = [];

    /** @var array<int, string|null> */
    private array $localMediaBySize = [];

    /** @return array{sources:list<array<string,mixed>>,totals:array<string,int>,media_root:string} */
    public function preview(User $user): array
    {
        $sources = $this->sourceRows();
        $media = $this->withoutTombstones($this->mediaRows($sources->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all()));
        $this->youtubeIds = $media->pluck('key')->map(fn (mixed $key): string => (string) $key)->unique()->values()->all();
        $localSourceKeys = $user->sources()->get(['type', 'external_id'])->map(fn (Source $source): string => $source->type.':'.$source->external_id)->all();
        $groupedMedia = $media->groupBy('source_id');

        $sourcePreview = $sources->map(function (object $source) use ($groupedMedia, $localSourceKeys): array {
            $rows = $groupedMedia->get((string) $source->uuid, collect());
            $type = $this->sourceType((string) $source->source_type);

            return [
                'uuid' => (string) $source->uuid,
                'name' => (string) $source->name,
                'type' => $type,
                'key' => (string) $source->key,
                'media_count' => $rows->count(),
                'downloaded_count' => $rows->where('downloaded', true)->count(),
                'existing_files' => $rows->filter(fn (object $medium): bool => $this->mediaPath($medium) !== null)->count(),
                'existing_thumbnails' => $rows->filter(fn (object $medium): bool => $this->thumbnailPath($medium) !== null)->count(),
                'queue_candidates' => $rows->filter(fn (object $medium): bool => $this->shouldQueue($medium))->count(),
                'already_imported' => in_array($type.':'.$source->key, $localSourceKeys, true),
            ];
        })->values()->all();

        $uniqueMedia = $media->unique('key');

        return [
            'sources' => $sourcePreview,
            'totals' => [
                'sources' => $sources->count(),
                'media' => $uniqueMedia->count(),
                'files' => $uniqueMedia->filter(fn (object $medium): bool => $this->mediaPath($medium) !== null)->count(),
                'thumbnails' => $uniqueMedia->filter(fn (object $medium): bool => $this->thumbnailPath($medium) !== null)->count(),
                'queue_candidates' => $uniqueMedia->filter(fn (object $medium): bool => $this->shouldQueue($medium))->count(),
            ],
            'media_root' => (string) config('auroraarchive.media_root'),
        ];
    }

    /** @param list<string> $sourceUuids @return array<string, int> */
    public function import(User $user, array $sourceUuids, bool $queueMissing): array
    {
        $sources = $this->sourceRows()->whereIn('uuid', $sourceUuids);
        if ($sources->count() !== count(array_unique($sourceUuids))) {
            throw new RuntimeException('One or more selected TubeSync sources no longer exist. Refresh the preview and try again.');
        }

        $summary = ['sources' => 0, 'media' => 0, 'files' => 0, 'thumbnails' => 0, 'queued' => 0, 'metadata_only' => 0];
        $allRows = $this->withoutTombstones($this->mediaRows($sources->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all()));
        $this->youtubeIds = $allRows->pluck('key')->map(fn (mixed $key): string => (string) $key)->unique()->values()->all();
        foreach ($sources as $tubeSyncSource) {
            $source = $this->importSource($user, $tubeSyncSource);
            $summary['sources']++;
            $rows = $allRows->where('source_id', (string) $tubeSyncSource->uuid);
            $sourceMetadata = $this->metadataForSource((string) $tubeSyncSource->uuid);
            $directMetadata = $this->metadataForMedia($rows->pluck('uuid')->map(fn (mixed $uuid): string => (string) $uuid)->all());

            foreach ($rows->values() as $position => $row) {
                $metadata = array_replace_recursive($sourceMetadata->get((string) $row->key, []), $directMetadata->get((string) $row->uuid, []));
                $medium = $this->importMedium($source, $row, $metadata);
                $source->playlistMedia()->syncWithoutDetaching([
                    $medium->id => ['position' => (int) (Arr::get($metadata, 'playlist_index') ?: $position + 1)],
                ]);
                $summary['media']++;

                $mediaPath = $this->mediaPath($row);
                if ($mediaPath !== null) {
                    $this->attachMediaFile($medium, $mediaPath, $this->pathRelativeToMediaRoot($mediaPath), $row);
                    $medium->update([
                        'status' => MediaStatus::Downloaded,
                        'channel_name' => $medium->channel_name ?: basename(dirname($mediaPath)),
                        'thumbnail_url' => route('media.thumbnail', $medium, absolute: false),
                    ]);
                    $summary['files']++;
                } elseif ($medium->files()->exists()) {
                    $medium->update(['status' => MediaStatus::Downloaded]);
                } elseif ($queueMissing && $this->shouldQueue($row)) {
                    if (! in_array($medium->status, [MediaStatus::Queued, MediaStatus::Downloading], true)) {
                        $medium->update(['status' => MediaStatus::Queued]);
                        DownloadMedia::dispatch($medium);
                        $summary['queued']++;
                    }
                } elseif (! in_array($medium->status, [MediaStatus::Queued, MediaStatus::Downloading], true)) {
                    $medium->update(['status' => MediaStatus::Skipped]);
                    $summary['metadata_only']++;
                }

                $thumbnailPath = $this->thumbnailPath($row);
                if ($thumbnailPath !== null) {
                    $this->attachThumbnail($medium, $thumbnailPath);
                    $medium->update(['thumbnail_url' => route('media.thumbnail', $medium, absolute: false)]);
                    $summary['thumbnails']++;
                } elseif ($mediaPath !== null) {
                    GenerateMediaThumbnail::dispatch($medium);
                }
            }
        }

        return $summary;
    }

    private function importSource(User $user, object $tubeSyncSource): Source
    {
        $type = $this->sourceType((string) $tubeSyncSource->source_type);

        return $user->sources()->updateOrCreate(
            ['type' => $type, 'external_id' => (string) $tubeSyncSource->key],
            [
                'name' => (string) $tubeSyncSource->name,
                'url' => $type === 'playlist' ? 'https://www.youtube.com/playlist?list='.$tubeSyncSource->key : 'https://www.youtube.com/channel/'.$tubeSyncSource->key,
                'enabled' => (int) $tubeSyncSource->index_schedule > 0,
                'auto_download' => (bool) $tubeSyncSource->download_media,
                'scan_interval_minutes' => max(15, min(10080, (int) round(((int) $tubeSyncSource->index_schedule ?: 21600) / 60))),
                'last_scanned_at' => $tubeSyncSource->last_crawl,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function importMedium(Source $source, object $row, array $metadata): Media
    {
        $medium = Media::query()->firstOrNew(['youtube_id' => (string) $row->key]);
        if (! $medium->exists) {
            $medium->source_id = $source->id;
        }

        $storedMetadata = $medium->metadata ?? [];
        Arr::set($storedMetadata, 'tubesync.sources.'.(string) $row->source_id, [
            'media_uuid' => (string) $row->uuid,
            'media_file' => $this->relativePath($row->media_file),
            'thumbnail_path' => $this->relativePath($row->thumb),
            'metadata' => $metadata,
        ]);
        $title = Arr::get($storedMetadata, 'manual.title')
            ? $medium->title
            : (string) (Arr::get($metadata, 'title') ?: $row->title ?: $row->key);
        $description = Arr::get($storedMetadata, 'manual.description')
            ? $medium->description
            : Arr::get($metadata, 'description');
        $channelName = Arr::get($storedMetadata, 'manual.channel_name')
            ? $medium->channel_name
            : (Arr::get($metadata, 'channel') ?: Arr::get($metadata, 'uploader'));

        $medium->fill([
            'title' => $title,
            'description' => $description,
            'channel_name' => $channelName,
            'channel_id' => Arr::get($metadata, 'channel_id') ?: Arr::get($metadata, 'uploader_id'),
            'published_at' => $this->publishedAt($row->published, $metadata),
            'duration_seconds' => $row->duration ?: Arr::get($metadata, 'duration'),
            'thumbnail_url' => Arr::get($metadata, 'thumbnail') ?: Arr::get($metadata, 'thumbnails.0.url'),
            'original_url' => Arr::get($metadata, 'webpage_url') ?: 'https://www.youtube.com/watch?v='.$row->key,
            'metadata' => $storedMetadata,
        ])->save();

        return $medium;
    }

    private function attachMediaFile(Media $medium, string $absolutePath, string $relativePath, object $row): void
    {
        MediaFile::updateOrCreate(
            ['media_id' => $medium->id, 'path' => $this->relativePath($relativePath)],
            [
                'mime_type' => mime_content_type($absolutePath) ?: null,
                'size_bytes' => filesize($absolutePath) ?: $row->downloaded_filesize,
                'width' => $row->downloaded_width,
                'height' => $row->downloaded_height,
            ],
        );
    }

    private function attachThumbnail(Media $medium, string $absolutePath): void
    {
        $metadata = $medium->metadata ?? [];
        Arr::set($metadata, 'local_thumbnail_path', $this->pathRelativeToMediaRoot($absolutePath));
        $medium->metadata = $metadata;
        $medium->save();
    }

    /** @param array<string, mixed> $metadata */
    private function publishedAt(mixed $published, array $metadata): ?Carbon
    {
        try {
            if (filled($published)) {
                return Carbon::parse($published);
            }
            if (filled(Arr::get($metadata, 'timestamp'))) {
                return Carbon::createFromTimestamp((int) Arr::get($metadata, 'timestamp'));
            }
            if (filled(Arr::get($metadata, 'upload_date'))) {
                return Carbon::createFromFormat('Ymd', (string) Arr::get($metadata, 'upload_date'))->startOfDay();
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** @return Collection<int, object> */
    private function sourceRows(): Collection
    {
        $connection = DB::connection('tubesync');
        $connection->getPdo();

        return $connection->table('sync_source')->orderBy('name')->get();
    }

    /** @param list<string> $sourceUuids @return Collection<int, object> */
    private function mediaRows(array $sourceUuids): Collection
    {
        if ($sourceUuids === []) {
            return collect();
        }

        return DB::connection('tubesync')->table('sync_media')->whereIn('source_id', $sourceUuids)->orderBy('created')->get();
    }

    /** @param Collection<int, object> $rows @return Collection<int, object> */
    private function withoutTombstones(Collection $rows): Collection
    {
        $youtubeIds = $rows->pluck('key')->map(fn (mixed $key): string => (string) $key)->unique()->values();
        if ($youtubeIds->isEmpty()) {
            return $rows;
        }

        $tombstones = $youtubeIds
            ->chunk(1000)
            ->flatMap(fn (Collection $chunk): Collection => MediaTombstone::query()
                ->whereIn('youtube_id', $chunk->all())
                ->pluck('youtube_id'))
            ->all();

        return $rows->reject(fn (object $row): bool => in_array((string) $row->key, $tombstones, true))->values();
    }

    /** @return Collection<string, array<string, mixed>> */
    private function metadataForSource(string $sourceUuid): Collection
    {
        return DB::connection('tubesync')->table('sync_media_metadata')->where('source_id', $sourceUuid)->get(['key', 'value'])->mapWithKeys(fn (object $row): array => [(string) $row->key => $this->decodeMetadata($row->value)]);
    }

    /** @param list<string> $mediaUuids @return Collection<string, array<string, mixed>> */
    private function metadataForMedia(array $mediaUuids): Collection
    {
        if ($mediaUuids === []) {
            return collect();
        }

        return DB::connection('tubesync')->table('sync_media_metadata')->whereIn('media_id', $mediaUuids)->get(['media_id', 'value'])->mapWithKeys(fn (object $row): array => [(string) $row->media_id => $this->decodeMetadata($row->value)]);
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $value): array
    {
        $metadata = is_string($value) ? json_decode($value, true) : null;

        return is_array($metadata) ? $metadata : [];
    }

    private function shouldQueue(object $medium): bool
    {
        return $this->mediaPath($medium) === null && (bool) $medium->can_download && ! (bool) $medium->skip && ! (bool) $medium->manual_skip;
    }

    private function mediaPath(object $medium): ?string
    {
        $path = $this->existingPath($medium->media_file) ?? $this->indexedPath((string) $medium->key, 'media');
        if ($path !== null) {
            return $path;
        }

        $size = (int) ($medium->downloaded_filesize ?? 0);

        return $size > 0 ? ($this->localMediaBySize[$size] ?? null) : null;
    }

    private function thumbnailPath(object $medium): ?string
    {
        return $this->existingPath($medium->thumb) ?? $this->indexedPath((string) $medium->key, 'thumbnail');
    }

    private function indexedPath(string $youtubeId, string $type): ?string
    {
        if ($this->localFileIndex === null) {
            $this->localFileIndex = $this->buildLocalFileIndex();
        }

        return $this->localFileIndex[$youtubeId][$type] ?? null;
    }

    /** @return array<string, array{media:?string,thumbnail:?string}> */
    private function buildLocalFileIndex(): array
    {
        $root = realpath((string) config('auroraarchive.media_root'));
        if ($root === false) {
            return [];
        }

        $index = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $extension = Str::lower($file->getExtension());
            $type = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true) ? 'thumbnail' : (in_array($extension, ['mkv', 'mp4', 'webm', 'mov', 'm4v'], true) ? 'media' : null);
            if ($type === null) {
                continue;
            }

            foreach ($this->youtubeIds as $youtubeId) {
                if (! Str::contains($file->getBasename(), $youtubeId)) {
                    continue;
                }

                $index[$youtubeId] ??= ['media' => null, 'thumbnail' => null];
                $index[$youtubeId][$type] ??= $file->getRealPath();
            }

            if ($type === 'media') {
                $size = $file->getSize();
                $path = $file->getRealPath();
                $this->localMediaBySize[$size] = array_key_exists($size, $this->localMediaBySize) ? null : $path;
            }
        }

        return $index;
    }

    private function pathRelativeToMediaRoot(string $absolutePath): string
    {
        $root = rtrim((string) realpath((string) config('auroraarchive.media_root')), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_replace('\\', '/', Str::after($absolutePath, $root));
    }

    private function existingPath(mixed $relativePath): ?string
    {
        $relativePath = $this->relativePath($relativePath);
        if ($relativePath === null) {
            return null;
        }

        $root = realpath((string) config('auroraarchive.media_root'));
        if ($root === false) {
            return null;
        }

        $absolutePath = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        return $absolutePath !== false && is_file($absolutePath) && Str::startsWith($absolutePath, $root.DIRECTORY_SEPARATOR) ? $absolutePath : null;
    }

    private function relativePath(mixed $path): ?string
    {
        if (! is_string($path) || blank($path)) {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));
        if (Str::startsWith($path, ['/']) || preg_match('/^[A-Za-z]:\//', $path) === 1 || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return ltrim($path, '/');
    }

    private function sourceType(string $sourceType): string
    {
        return $sourceType === 'p' ? 'playlist' : 'channel';
    }
}
