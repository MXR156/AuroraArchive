<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = ['user_id', 'type', 'external_id', 'name', 'url', 'enabled', 'auto_download', 'scan_interval_minutes', 'last_scanned_at', 'next_scan_at', 'last_scan_status', 'last_scan_error'];

    protected $attributes = ['enabled' => true, 'auto_download' => true, 'scan_interval_minutes' => 360];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'auto_download' => 'boolean', 'last_scanned_at' => 'datetime', 'next_scan_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function playlistMedia(): BelongsToMany
    {
        return $this->belongsToMany(Media::class)->withPivot('position')->withTimestamps();
    }
}
