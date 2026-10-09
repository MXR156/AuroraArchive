<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'target_channel' => ['required', 'string', 'max:1000'],
        ];
    }
}
