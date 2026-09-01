<?php

namespace App\Models;

use App\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = ['source_id', 'youtube_id', 'title', 'description', 'channel_name', 'channel_id', 'published_at', 'duration_seconds', 'thumbnail_url', 'original_url', 'status', 'metadata'];

    protected $attributes = ['status' => 'discovered'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'status' => MediaStatus::class, 'metadata' => 'array'];
    }

    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn (?string $value): string => $value ?: 'https://i.ytimg.com/vi/'.$this->youtube_id.'/hqdefault.jpg');
    }

    public function youtubeChannelUrl(): ?string
    {
        $sourceMetadata = collect(Arr::get($this->metadata ?? [], 'tubesync.sources', []));
        $candidates = [
            Arr::get($this->metadata, 'channel_url'),
            Arr::get($this->metadata, 'uploader_url'),
            $sourceMetadata->pluck('metadata.channel_url')->first(fn (mixed $url): bool => filled($url)),
            $sourceMetadata->pluck('metadata.uploader_url')->first(fn (mixed $url): bool => filled($url)),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->isYoutubeUrl($candidate)) {
                return $candidate;
            }
        }

        if (filled($this->channel_id)) {
            return Str::startsWith($this->channel_id, '@')
                ? 'https://www.youtube.com/'.$this->channel_id
                : 'https://www.youtube.com/channel/'.$this->channel_id;
        }

        return null;
    }

    public function youtubeVideoUrl(): string
    {
        return 'https://www.youtube.com/watch?v='.$this->youtube_id;
    }

    public function isUnavailableOnYoutube(): bool
    {
        $checkStatus = Arr::get($this->metadata, 'youtube.availability_check_status');
        if (in_array($checkStatus, ['available', 'unavailable'], true)) {
            return $checkStatus === 'unavailable';
        }

        if ((bool) Arr::get($this->metadata, 'youtube.unavailable', false)) {
            return true;
        }

        $unavailableStates = ['private', 'unavailable', 'needs_auth', 'subscriber_only', 'premium_only'];

        return collect(Arr::dot($this->metadata ?? []))
            ->filter(fn (mixed $value, string $key): bool => $key === 'availability' || Str::endsWith($key, '.availability'))
            ->contains(fn (mixed $value): bool => is_string($value) && in_array(Str::lower($value), $unavailableStates, true));
    }

    public function archiveChannelKey(): string
    {
        if (filled($this->channel_id)) {
            return 'id-'.rawurlencode($this->channel_id);
        }

        return 'name-'.rtrim(strtr(base64_encode((string) $this->channel_name), '+/', '-_'), '=');
    }

    private function isYoutubeUrl(mixed $url): bool
    {
        if (! is_string($url) || ! Str::startsWith($url, ['https://', 'http://'])) {
            return false;
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'youtu.be' || $host === 'youtube.com' || Str::endsWith($host, '.youtube.com');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DownloadAttempt::class);
    }

    public function watchHistory(): HasMany
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class)->withPivot('position')->withTimestamps();
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class)->withPivot('position')->withTimestamps();
    }
}
