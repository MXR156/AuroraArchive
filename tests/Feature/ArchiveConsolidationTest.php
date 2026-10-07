<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ArchiveConsolidation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->base = storage_path('framework/testing/archive-consolidation');
    $this->source = $this->base.'/recovery';
    $this->destination = $this->base.'/media';
    File::deleteDirectory($this->base);
    File::ensureDirectoryExists($this->source.'/downloads/Recovered Playlist/Recovered Channel');
    File::ensureDirectoryExists($this->source.'/video/Recovered Channel');
    File::ensureDirectoryExists($this->destination);
    config()->set('auroraarchive.ffprobe', 'missing-ffprobe-binary');
});

afterEach(fn () => File::deleteDirectory($this->base));

test('it copies one best candidate verifies it and reconstructs playlist membership', function () {
    $smaller = $this->source.'/downloads/Recovered Playlist/Recovered Channel/old_ABCDEFGHIJK_1080p.mkv';
    $larger = $this->source.'/video/Recovered Channel/new [ABCDEFGHIJK].mp4';
    File::put($smaller, 'small');
    File::put($larger, 'the larger and preferred surviving file');
    $medium = Media::query()->create([
        'youtube_id' => 'ABCDEFGHIJK',
        'title' => 'Recovered title',
        'channel_name' => 'Recovered Channel',
        'published_at' => '2024-01-02',
        'original_url' => 'https://www.youtube.com/watch?v=ABCDEFGHIJK',
        'status' => MediaStatus::Discovered,
    ]);
    $user = User::factory()->create();
    $manifest = $this->base.'/manifest.json';

    $stats = app(ArchiveConsolidation::class)->consolidate(
        $this->source,
        $this->destination,
        write: true,
        userId: $user->id,
        createPlaylists: true,
        manifestPath: $manifest,
    );

    $copied = $this->destination.'/Recovered Playlist/Recovered Channel/2024-01-02 - Recovered title [ABCDEFGHIJK].mp4';
    expect($stats)->toMatchArray(['scanned' => 2, 'unique_videos' => 1, 'duplicates' => 1, 'copied' => 1, 'files_attached' => 1, 'playlists_created' => 1, 'memberships_attached' => 1])
        ->and(File::get($copied))->toBe(File::get($larger))
        ->and(File::get($smaller))->toBe('small')
        ->and(File::get($larger))->toBe('the larger and preferred surviving file')
        ->and($medium->refresh()->status)->toBe(MediaStatus::Downloaded)
        ->and($medium->files->first()->path)->toBe('Recovered Playlist/Recovered Channel/2024-01-02 - Recovered title [ABCDEFGHIJK].mp4')
        ->and(Playlist::query()->firstOrFail()->media)->toHaveCount(1)
        ->and(File::exists($manifest))->toBeTrue();
});

test('dry runs create no media files or database attachments', function () {
    File::put($this->source.'/video/Recovered Channel/video [ABCDEFGHIJK].mp4', 'archive');
    Media::query()->create(['youtube_id' => 'ABCDEFGHIJK', 'title' => 'Video', 'original_url' => 'https://www.youtube.com/watch?v=ABCDEFGHIJK']);

    $messages = [];
    $stats = app(ArchiveConsolidation::class)->consolidate(
        $this->source,
        $this->destination,
        report: function (string $message) use (&$messages): void {
            $messages[] = $message;
        },
    );

    expect($stats)->toMatchArray(['planned' => 1, 'copied' => 0, 'files_attached' => 0])
        ->and(File::allFiles($this->destination))->toBeEmpty()
        ->and(Media::query()->firstOrFail()->files)->toBeEmpty()
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toStartWith('PLANNED:');
});

test('it never overwrites a conflicting destination file', function () {
    File::put($this->source.'/video/Recovered Channel/video [ABCDEFGHIJK].mp4', 'source content');
    Media::query()->create(['youtube_id' => 'ABCDEFGHIJK', 'title' => 'Video', 'channel_name' => 'Recovered Channel', 'original_url' => 'https://www.youtube.com/watch?v=ABCDEFGHIJK']);
    $destination = $this->destination.'/Recovered/Recovered Channel/Video [ABCDEFGHIJK].mp4';
    File::ensureDirectoryExists(dirname($destination));
    File::put($destination, 'existing different content');

    $stats = app(ArchiveConsolidation::class)->consolidate($this->source, $this->destination, write: true);

    expect($stats)->toMatchArray(['conflicts' => 1, 'copied' => 0, 'files_attached' => 0])
        ->and(File::get($destination))->toBe('existing different content');
});
