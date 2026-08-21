<?php

use App\Contracts\YoutubeDownloader;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

it('checks ffmpeg using its supported version argument', function () {
    Process::fake(fn () => Process::result(output: 'ffmpeg version 8.0', exitCode: 0));
    $youtube = mock(YoutubeDownloader::class);
    $youtube->shouldReceive('version')->once()->andReturn('2026.08.21');

    $this->actingAs(User::factory()->create())
        ->get(route('system-health'))
        ->assertOk()
        ->assertSee('FFmpeg')
        ->assertSee('ffmpeg version 8.0');

    Process::assertRan([config('auroraarchive.ffmpeg'), '-version']);
});
