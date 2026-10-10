<?php

use App\Models\Media;
use App\Services\MediaOrganiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/media-organiser');
    File::deleteDirectory($this->root);
    File::ensureDirectoryExists($this->root.'/Recovered/Old Channel');
    config()->set('auroraarchive.media_root', $this->root);
});

afterEach(fn () => File::deleteDirectory($this->root));

it('previews and then moves media into its stable channel ID folder', function () {
    $source = $this->root.'/Recovered/Old Channel/old-name.mkv';
    File::put($source, 'archived video');
    $medium = Media::query()->create([
        'youtube_id' => 'ABCDEFGHIJK',
        'title' => 'Recovered title',
        'channel_name' => 'A Channel Name That May Change',
        'channel_id' => 'UCSTABLEID',
        'published_at' => '2025-05-05',
        'original_url' => 'https://www.youtube.com/watch?v=ABCDEFGHIJK',
    ]);
    $mediaFile = $medium->files()->create(['path' => 'Recovered/Old Channel/old-name.mkv']);
    $manifest = $this->root.'/organise-plan.json';

    $preview = app(MediaOrganiser::class)->organise(manifestPath: $manifest);

    expect($preview)->toMatchArray(['scanned' => 1, 'planned' => 1, 'moved' => 0])
        ->and(File::exists($source))->toBeTrue()
        ->and($mediaFile->refresh()->path)->toBe('Recovered/Old Channel/old-name.mkv')
        ->and(File::exists($manifest))->toBeTrue();

    $written = app(MediaOrganiser::class)->organise(write: true);
    $destination = $this->root.'/Videos/UCSTABLEID/2025-05-05 - Recovered title [ABCDEFGHIJK].mkv';

    expect($written)->toMatchArray(['scanned' => 1, 'moved' => 1, 'failed' => 0])
        ->and(File::exists($source))->toBeFalse()
        ->and(File::get($destination))->toBe('archived video')
        ->and($mediaFile->refresh()->path)->toBe('Videos/UCSTABLEID/2025-05-05 - Recovered title [ABCDEFGHIJK].mkv')
        ->and(app(MediaOrganiser::class)->organise(write: true))->toMatchArray(['existing' => 1, 'moved' => 0]);
});

it('places media without a channel ID in the orphaned folder', function () {
    $source = $this->root.'/Recovered/Old Channel/orphan.mp4';
    File::put($source, 'orphaned video');
    $medium = Media::query()->create([
        'youtube_id' => 'ABCDEFGHIJK',
        'title' => 'Orphaned title',
        'channel_name' => 'Known display name only',
        'original_url' => 'https://www.youtube.com/watch?v=ABCDEFGHIJK',
    ]);
    $mediaFile = $medium->files()->create(['path' => 'Recovered/Old Channel/orphan.mp4']);

    app(MediaOrganiser::class)->organise(write: true);

    expect($mediaFile->refresh()->path)->toBe('Videos/Orphaned/Orphaned title [ABCDEFGHIJK].mp4')
        ->and(File::exists($this->root.'/Videos/Orphaned/Orphaned title [ABCDEFGHIJK].mp4'))->toBeTrue();
});

it('does not overwrite a conflicting destination', function () {
    $source = $this->root.'/Recovered/Old Channel/source.mp4';
    $destination = $this->root.'/Videos/UCSTABLEID/Title [ABCDEFGHIJK].mp4';
    File::put($source, 'source video');
    File::ensureDirectoryExists(dirname($destination));
    File::put($destination, 'different video');
    $medium = Media::query()->create([
        'youtube_id' => 'ABCDEFGHIJK',
        'title' => 'Title',
        'channel_id' => 'UCSTABLEID',
        'original_url' => 'https://www.youtube.com/watch?v=ABCDEFGHIJK',
    ]);
    $mediaFile = $medium->files()->create(['path' => 'Recovered/Old Channel/source.mp4']);

    $stats = app(MediaOrganiser::class)->organise(write: true);

    expect($stats)->toMatchArray(['conflicts' => 1, 'moved' => 0])
        ->and(File::get($source))->toBe('source video')
        ->and(File::get($destination))->toBe('different video')
        ->and($mediaFile->refresh()->path)->toBe('Recovered/Old Channel/source.mp4');
});
