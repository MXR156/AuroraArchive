<?php

use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function manageableMedium(string $youtubeId, MediaStatus $status): Media
{
    return Media::query()->create([
        'youtube_id' => $youtubeId,
        'title' => 'Manage '.$youtubeId,
        'status' => $status,
        'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
    ]);
}

test('selected skipped and failed videos can be queued for download', function () {
    Queue::fake();
    $user = User::factory()->create();
    $skipped = manageableMedium('AAAAAAAAAAA', MediaStatus::Skipped);
    $failed = manageableMedium('BBBBBBBBBBB', MediaStatus::Failed);
    $alreadyQueued = manageableMedium('CCCCCCCCCCC', MediaStatus::Queued);
    $downloaded = manageableMedium('DDDDDDDDDDD', MediaStatus::Downloaded);
    MediaFile::query()->create(['media_id' => $downloaded->id, 'path' => 'existing.mkv']);

    $this->actingAs($user)
        ->post(route('media.bulk-manage'), [
            'action' => 'download',
            'media_ids' => [$skipped->id, $failed->id, $alreadyQueued->id, $downloaded->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '2 selected videos queued for download.');

    expect($skipped->refresh()->status)->toBe(MediaStatus::Queued)
        ->and($failed->refresh()->status)->toBe(MediaStatus::Queued)
        ->and($alreadyQueued->refresh()->status)->toBe(MediaStatus::Queued)
        ->and($downloaded->refresh()->status)->toBe(MediaStatus::Downloaded);
    Queue::assertPushed(DownloadMedia::class, 2);
});

test('selected videos and their files can be deleted in bulk', function () {
    $user = User::factory()->create();
    $root = storage_path('framework/testing/bulk-media-deletion');
    File::ensureDirectoryExists($root.'/channel');
    config()->set('auroraarchive.media_root', $root);
    $first = manageableMedium('AAAAAAAAAAA', MediaStatus::Downloaded);
    $second = manageableMedium('BBBBBBBBBBB', MediaStatus::Skipped);
    $videoPath = $root.'/channel/video [AAAAAAAAAAA].mkv';
    File::put($videoPath, 'video');
    MediaFile::query()->create(['media_id' => $first->id, 'path' => 'channel/video [AAAAAAAAAAA].mkv']);

    $this->actingAs($user)
        ->post(route('media.bulk-manage'), [
            'action' => 'delete',
            'media_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '2 selected videos deleted.');

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
    expect(File::exists($videoPath))->toBeFalse();

    File::deleteDirectory($root);
});

test('bulk media actions validate the action and selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('media.bulk-manage'), ['action' => 'invalid', 'media_ids' => []])
        ->assertSessionHasErrors(['action', 'media_ids']);
});

test('all library cards expose selection controls and the bulk action toolbar', function () {
    $user = User::factory()->create();
    manageableMedium('AAAAAAAAAAA', MediaStatus::Skipped);
    manageableMedium('BBBBBBBBBBB', MediaStatus::Downloaded);

    $this->actingAs($user)
        ->get(route('library'))
        ->assertOk()
        ->assertSee('Download / retry')
        ->assertSee('Select all visible')
        ->assertSee('ARE YOU SURE?', escape: false)
        ->assertSee('media_ids[]', escape: false);
});

test('guests cannot perform bulk media actions', function () {
    $medium = manageableMedium('AAAAAAAAAAA', MediaStatus::Skipped);

    $this->post(route('media.bulk-manage'), [
        'action' => 'download',
        'media_ids' => [$medium->id],
    ])->assertRedirect(route('login'));
});
