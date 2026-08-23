<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\MediaTombstone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('an authenticated user can permanently delete media and matching files', function () {
    $user = User::factory()->create();
    $root = storage_path('framework/testing/media-deletion');
    File::ensureDirectoryExists($root.'/channel');
    config()->set('auroraarchive.media_root', $root);

    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Delete me',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
        'status' => MediaStatus::Downloaded,
    ]);
    $videoPath = $root.'/channel/video [AAAAAAAAAAA].mkv';
    $thumbnailPath = $root.'/channel/video [AAAAAAAAAAA].webp';
    File::put($videoPath, 'video');
    File::put($thumbnailPath, 'thumbnail');
    MediaFile::query()->create(['media_id' => $medium->id, 'path' => 'channel/video [AAAAAAAAAAA].mkv']);

    $this->actingAs($user)
        ->delete(route('media.destroy', $medium))
        ->assertRedirect(route('library'))
        ->assertSessionHas('success');

    $this->assertModelMissing($medium);
    expect(File::exists($videoPath))->toBeFalse()
        ->and(File::exists($thumbnailPath))->toBeFalse()
        ->and(MediaTombstone::query()->where('youtube_id', 'AAAAAAAAAAA')->where('reason', 'deleted_by_user')->exists())->toBeTrue();

    File::deleteDirectory($root);
});

test('the watch page requires confirmation before deleting media', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Delete me',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
        'status' => MediaStatus::Discovered,
    ]);

    $this->actingAs($user)
        ->get(route('media.show', $medium))
        ->assertOk()
        ->assertSee('ARE YOU SURE?')
        ->assertSee('removes it from every playlist');
});

test('a guest cannot delete media', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Keep me',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->delete(route('media.destroy', $medium))->assertRedirect(route('login'));
    $this->assertModelExists($medium);
});
