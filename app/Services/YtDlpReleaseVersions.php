<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class YtDlpReleaseVersions
{
    /** @return array{stable:array{version:?string,url:string},nightly:array{version:?string,url:string}} */
    public function get(): array
    {
        return Cache::remember('yt-dlp.release-versions.v1', now()->addHours(6), fn (): array => [
            'stable' => $this->release('yt-dlp/yt-dlp'),
            'nightly' => $this->release('yt-dlp/yt-dlp-nightly-builds'),
        ]);
    }

    /** @return array{version:?string,url:string} */
    private function release(string $repository): array
    {
        $releasesUrl = 'https://github.com/'.$repository.'/releases/latest';

        try {
            $response = Http::acceptJson()
                ->withUserAgent('AuroraArchive')
                ->connectTimeout(2)
                ->timeout(5)
                ->retry([100, 300], throw: false)
                ->get('https://api.github.com/repos/'.$repository.'/releases/latest');

            $version = $response->successful() ? $response->json('tag_name') : null;
            $url = $response->successful() ? $response->json('html_url') : null;

            return [
                'version' => is_string($version) && $version !== '' ? $version : null,
                'url' => is_string($url) && $url !== '' ? $url : $releasesUrl,
            ];
        } catch (Throwable) {
            return ['version' => null, 'url' => $releasesUrl];
        }
    }
}
