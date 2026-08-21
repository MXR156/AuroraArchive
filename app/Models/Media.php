<?php

namespace App\Models;

use App\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = ['source_id', 'youtube_id', 'title', 'description', 'channel_name', 'channel_id', 'published_at', 'duration_seconds', 'thumbnail_url', 'original_url', 'status', 'metadata'];

    protected $attributes = ['status' => 'discovered'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'status' => MediaStatus::class, 'metadata' => 'array'];
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
}
