<?php

namespace App\Models;

use Database\Factories\MediaTombstoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaTombstone extends Model
{
    /** @use HasFactory<MediaTombstoneFactory> */
    use HasFactory;

    protected $fillable = ['youtube_id', 'reason'];
}
