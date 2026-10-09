<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FilmotService
{
    /** @return array<string, mixed>|null */
    public function lookup(string $youtubeId, string $apiKey): ?array
    {
        $host = (string) config('auroraarchive.filmot_api_host');
        $response = Http::acceptJson()
            ->withHeaders([
                'x-rapidapi-key' => $apiKey,
                'x-rapidapi-host' => $host,
            ])
            ->connectTimeout(5)
            ->timeout(30)
            ->retry([500, 1500], throw: false)
            ->get('https://'.$host.'/getvideos', ['id' => $youtubeId]);

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('Filmot rejected the RapidAPI credentials.');
        }
        if ($response->status() === 429) {
            throw new RuntimeException('Filmot rate limit reached. Try again later.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Filmot returned HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Filmot returned an invalid response.');
        }

        return $this->normalisePayload($payload, $youtubeId);
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    public function normalisePayload(array $payload, string $youtubeId): ?array
    {
        $candidate = collect($this->candidateRows($payload))->first(
            fn (array $row): bool => $this->value($row, ['id', 'videoid', 'video_id', 'youtube_id']) === $youtubeId,
        );

        return $candidate === null ? null : $this->normalise($candidate, $youtubeId);
    }

    /** @return list<array<string, mixed>> */
    private function candidateRows(array $payload): array
    {
        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }
        if ($this->value($payload, ['id', 'videoid', 'video_id', 'youtube_id']) !== null) {
            return [$payload];
        }

        foreach (['result', 'videos', 'items', 'data'] as $key) {
            $rows = Arr::get($payload, $key);
            if (is_array($rows)) {
                return array_values(array_filter($rows, 'is_array'));
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function normalise(array $candidate, string $youtubeId): array
    {
        $thumbnail = $this->value($candidate, ['thumbnail', 'thumbnail_url', 'thumbnailurl']);
        if (is_array($thumbnail)) {
            $thumbnail = Arr::get($thumbnail, 'url') ?: collect($thumbnail)->pluck('url')->last();
        }

        return array_filter([
            'youtube_id' => $youtubeId,
            'source' => 'filmot',
            'title' => $this->value($candidate, ['title', 'videotitle', 'video_title']),
            'description' => $this->value($candidate, ['description', 'videodescription', 'video_description']),
            'channel_name' => $this->value($candidate, ['channelname', 'channel_name', 'channelTitle', 'channel_title', 'author', 'uploader']),
            'channel_id' => $this->value($candidate, ['channelid', 'channel_id']),
            'published_at' => $this->dateValue($candidate, ['uploaddate', 'upload_date', 'published_at', 'publishedAt']),
            'duration_seconds' => $this->integerValue($candidate, ['duration', 'duration_seconds']),
            'thumbnail_url' => is_string($thumbnail) ? $thumbnail : null,
            'retrieved_at' => now()->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function value(array $candidate, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $candidate)) {
                return $candidate[$key];
            }
        }

        return null;
    }

    private function integerValue(array $candidate, array $keys): ?int
    {
        $value = $this->value($candidate, $keys);

        return is_numeric($value) ? (int) $value : null;
    }

    private function dateValue(array $candidate, array $keys): ?string
    {
        $value = $this->value($candidate, $keys);
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            $date = preg_match('/^\d{8}$/', (string) $value) === 1
                ? Carbon::createFromFormat('Ymd', (string) $value)
                : Carbon::parse((string) $value);

            return $date->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
