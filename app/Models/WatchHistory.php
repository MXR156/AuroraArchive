<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchHistory extends Model
{
    protected $table = 'watch_history';

    protected $fillable = ['user_id', 'media_id', 'position_seconds', 'watched', 'last_watched_at'];

    protected $attributes = ['position_seconds' => 0, 'watched' => false];

    protected function casts(): array
    {
        return ['watched' => 'boolean', 'last_watched_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
