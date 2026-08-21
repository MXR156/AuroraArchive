<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadAttempt extends Model
{
    protected $fillable = ['media_id', 'attempt_number', 'status', 'started_at', 'finished_at', 'exit_code', 'yt_dlp_version', 'error_category', 'stdout', 'stderr'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
