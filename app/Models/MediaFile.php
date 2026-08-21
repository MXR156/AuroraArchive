<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFile extends Model
{
    protected $fillable = ['media_id', 'path', 'mime_type', 'size_bytes', 'width', 'height'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
