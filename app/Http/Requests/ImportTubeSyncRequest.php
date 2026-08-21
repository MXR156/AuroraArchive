<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportTubeSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => ['required', 'uuid', 'distinct'],
            'queue_missing' => ['nullable', 'boolean'],
        ];
    }
}
