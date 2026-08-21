<?php

namespace App\Models;

use App\Enums\CredentialStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubeCredential extends Model
{
    protected $fillable = ['user_id', 'cookies', 'status', 'tested_at', 'status_message'];

    protected $hidden = ['cookies'];

    protected $attributes = ['status' => 'not_configured'];

    protected function casts(): array
    {
        return ['cookies' => 'encrypted', 'status' => CredentialStatus::class, 'tested_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
