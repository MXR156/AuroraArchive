<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkManageMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['download', 'delete', 'add_to_playlist', 'remove_from_playlist'])],
            'playlist_id' => [Rule::requiredIf(fn (): bool => in_array($this->input('action'), ['add_to_playlist', 'remove_from_playlist'], true)), 'nullable', 'integer', 'exists:playlists,id'],
            'media_ids' => ['required', 'array', 'min:1', 'max:500'],
            'media_ids.*' => ['required', 'integer', 'distinct', 'exists:media,id'],
        ];
    }
}
