<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['channel', 'playlist', 'video'])],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'scan_interval_minutes' => ['required', 'integer', 'min:15', 'max:10080'],
            'auto_download' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->hasAny(['type', 'url']) && $this->youtubeId() === null) {
                    $validator->errors()->add('url', 'Enter a valid YouTube URL for the selected source type.');
                }
            },
        ];
    }

    public function youtubeId(): ?string
    {
        $url = parse_url($this->string('url')->trim()->toString());
        $host = Str::lower($url['host'] ?? '');
        $path = trim($url['path'] ?? '', '/');
        parse_str($url['query'] ?? '', $query);

        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'], true)) {
            return null;
        }

        return match ($this->string('type')->toString()) {
            'video' => $this->videoId($host, $path, $query),
            'playlist' => $this->playlistId($host, $query),
            'channel' => $this->channelId($host, $path),
            default => null,
        };
    }

    /** @param array<string, mixed> $query */
    private function videoId(string $host, string $path, array $query): ?string
    {
        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            return $this->identifier(Str::before($path, '/'));
        }

        if ($videoId = $this->identifier($query['v'] ?? null)) {
            return $videoId;
        }

        $segments = explode('/', $path);

        return in_array($segments[0] ?? null, ['shorts', 'live', 'embed'], true) ? $this->identifier($segments[1] ?? null) : null;
    }

    /** @param array<string, mixed> $query */
    private function playlistId(string $host, array $query): ?string
    {
        return ! in_array($host, ['youtu.be', 'www.youtu.be'], true) ? $this->identifier($query['list'] ?? null) : null;
    }

    private function channelId(string $host, string $path): ?string
    {
        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            return null;
        }

        $segments = explode('/', $path);
        if (Str::startsWith($segments[0] ?? '', '@')) {
            return $this->identifier($segments[0]);
        }

        return in_array($segments[0] ?? null, ['channel', 'c', 'user'], true) ? $this->identifier($segments[1] ?? null) : null;
    }

    private function identifier(mixed $value): ?string
    {
        return is_string($value) && filled($value) && strlen($value) <= 255 ? $value : null;
    }
}
