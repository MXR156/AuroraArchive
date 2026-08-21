<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function localPlaylistMedium(string $youtubeId, string $title): Media
{
    return Media::query()->create([
        'youtube_id' => $youtubeId,
        'title' => $title,
        'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
        'status' => MediaStatus::Downloaded,
    ]);
}

test('a user can create rename and delete a playlist without deleting media', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('playlists.store'), ['name' => 'Evening viewing'])
        ->assertRedirect();

    $playlist = $user->playlists()->where('name', 'Evening viewing')->firstOrFail();
    $medium = localPlaylistMedium('AAAAAAAAAAA', 'Archived video');
    $playlist->media()->attach($medium, ['position' => 1]);

    $this->put(route('playlists.update', $playlist), ['name' => 'Late night viewing'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Playlist renamed.');
    expect($playlist->refresh()->name)->toBe('Late night viewing');

    $this->delete(route('playlists.destroy', $playlist))
        ->assertRedirect(route('playlists.index'))
        ->assertSessionHas('success');

    $this->assertModelMissing($playlist);
    $this->assertModelExists($medium);
});

test('selected media can be added once and removed from a playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['name' => 'Selected videos']);
    $first = localPlaylistMedium('AAAAAAAAAAA', 'First video');
    $second = localPlaylistMedium('BBBBBBBBBBB', 'Second video');

    $this->actingAs($user)->post(route('media.bulk-manage'), [
        'action' => 'add_to_playlist',
        'playlist_id' => $playlist->id,
        'media_ids' => [$first->id, $second->id],
    ])->assertRedirect()->assertSessionHas('success', '2 videos added to Selected videos.');

    $this->post(route('media.bulk-manage'), [
        'action' => 'add_to_playlist',
        'playlist_id' => $playlist->id,
        'media_ids' => [$first->id],
    ])->assertSessionHas('success', '0 videos added to Selected videos.');

    expect($playlist->media()->count())->toBe(2)
        ->and($playlist->media()->orderByPivot('position')->pluck('media.id')->all())->toBe([$first->id, $second->id]);

    $this->post(route('media.bulk-manage'), [
        'action' => 'remove_from_playlist',
        'playlist_id' => $playlist->id,
        'media_ids' => [$first->id],
    ])->assertSessionHas('success', '1 video removed from Selected videos.');

    expect($playlist->media()->pluck('media.id')->all())->toBe([$second->id]);
    $this->assertModelExists($first);
});

test('local playlists support ordered browsing and continuous playback', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['name' => 'Continuous playlist']);
    $first = localPlaylistMedium('AAAAAAAAAAA', 'First video');
    $second = localPlaylistMedium('BBBBBBBBBBB', 'Second video');
    $playlist->media()->attach([$first->id => ['position' => 1], $second->id => ['position' => 2]]);

    $this->actingAs($user)
        ->get(route('playlists.show', $playlist))
        ->assertOk()
        ->assertSeeInOrder(['First video', 'Second video'])
        ->assertSee(route('media.show', ['medium' => $first, 'local_playlist' => $playlist]), escape: false);

    $this->get(route('media.show', ['medium' => $first, 'local_playlist' => $playlist]))
        ->assertOk()
        ->assertSee('Next video')
        ->assertSee(route('media.show', ['medium' => $second, 'local_playlist' => $playlist]), escape: false);
});

test('the watch page can add its video to a selected playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['name' => 'Watch later']);
    $medium = localPlaylistMedium('AAAAAAAAAAA', 'Add while watching');

    $this->actingAs($user)
        ->get(route('media.show', $medium))
        ->assertOk()
        ->assertSee('Choose playlist')
        ->assertSee('Watch later')
        ->assertSee('aria-label="Add to playlist"', escape: false)
        ->assertSee(route('media.bulk-manage'), escape: false);
});

test('users cannot manage another users playlist', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();
    $medium = localPlaylistMedium('AAAAAAAAAAA', 'Private playlist video');

    $this->actingAs($viewer)->get(route('playlists.show', $playlist))->assertForbidden();
    $this->put(route('playlists.update', $playlist), ['name' => 'Changed'])->assertForbidden();
    $this->delete(route('playlists.destroy', $playlist))->assertForbidden();
    $this->post(route('media.bulk-manage'), [
        'action' => 'add_to_playlist',
        'playlist_id' => $playlist->id,
        'media_ids' => [$medium->id],
    ])->assertNotFound();
});

test('the navigation and library expose local playlist controls', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['name' => 'Navigation playlist']);
    localPlaylistMedium('AAAAAAAAAAA', 'Selectable video');

    $this->actingAs($user)
        ->get(route('library'))
        ->assertOk()
        ->assertSeeInOrder(['Library', 'Playlists'])
        ->assertSee('Navigation playlist')
        ->assertSee('Add to playlist');
});
