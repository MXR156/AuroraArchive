<?php

namespace App\Http\Controllers;

use App\Contracts\YoutubeDownloader;
use App\Models\YoutubeCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use Throwable;

class SystemHealthController extends Controller
{
    public function __invoke(YoutubeDownloader $youtube): View
    {
        $credential = auth()->check() ? YoutubeCredential::query()->whereBelongsTo(auth()->user())->first() : null;
        $ytDlpVersion = $youtube->version();
        $checks = [['name' => 'Laravel', 'value' => app()->version(), 'healthy' => true], ['name' => 'PHP', 'value' => PHP_VERSION, 'healthy' => true], $this->database(), $this->storage(), $this->process('Queue worker', 'queue:work'), $this->process('Scheduler', 'schedule:work'), ['name' => 'yt-dlp', 'value' => $ytDlpVersion ?: 'Unavailable', 'healthy' => $ytDlpVersion !== null], $this->binary('Deno', config('auroraarchive.deno')), $this->binary('FFmpeg', config('auroraarchive.ffmpeg'), '-version'), ['name' => 'YouTube cookies', 'value' => $credential?->status_message ?: 'Not configured', 'healthy' => $credential?->status->value === 'valid']];

        return view('system-health', compact('checks'));
    }

    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            $driver = DB::connection()->getDriverName();

            return ['name' => 'Database', 'value' => ucfirst($driver).' connected', 'healthy' => true];
        } catch (Throwable $e) {
            return ['name' => 'Database', 'value' => $e->getMessage(), 'healthy' => false];
        }
    }

    private function storage(): array
    {
        $path = config('auroraarchive.media_root');

        return ['name' => 'Media storage', 'value' => $path, 'healthy' => is_dir($path) && is_writable($path)];
    }

    private function process(string $name, string $pattern): array
    {
        try {
            $result = Process::timeout(5)->run(['pgrep', '-f', $pattern]);

            return ['name' => $name, 'value' => $result->successful() ? 'Running' : 'Not detected', 'healthy' => $result->successful()];
        } catch (Throwable) {
            return ['name' => $name, 'value' => 'Unable to inspect', 'healthy' => false];
        }
    }

    private function binary(string $name, string $binary, string $versionArgument = '--version'): array
    {
        try {
            $result = Process::timeout(5)->run([$binary, $versionArgument]);

            return ['name' => $name, 'value' => $result->successful() ? trim(strtok($result->output(), "\n")) : 'Unavailable', 'healthy' => $result->successful()];
        } catch (Throwable) {
            return ['name' => $name, 'value' => 'Unavailable', 'healthy' => false];
        }
    }
}
