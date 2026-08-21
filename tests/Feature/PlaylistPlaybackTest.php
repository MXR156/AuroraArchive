<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function playlistFor(User $user, string $name): Source
{
    return Source::query()->create([
        'user_id' => $user->id,
        'type' => 'playlist',
        'external_id' => fake()->unique()->bothify('PL########'),
        'name' => $name,
        'url' => 'https://www.youtube.com/playlist?list=test',
    ]);
}

function playlistMedium(string $youtubeId, string $title): Media
{
    return Media::query()->create([
        'youtube_id' => $youtubeId,
        'title' => $title,
        'channel_name' => 'Example channel',
        'channel_id' => 'UC123456789',
        'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
        'status' => MediaStatus::Discovered,
    ]);
}

test('a user can open a playlist and see its media in position order', function () {
    $user = User::factory()->create();
    $playlist = playlistFor($user, 'Evening playlist');
    $first = playlistMedium('AAAAAAAAAAA', 'First video');
    $second = playlistMedium('BBBBBBBBBBB', 'Second video');
    $playlist->playlistMedia()->attach([$first->id => ['position' => 1], $second->id => ['position' => 2]]);

    $response = $this->actingAs($user)->get(route('sources.show', $playlist));

    $response->assertOk()
        ->assertSeeInOrder(['First video', 'Second video'])
        ->assertSee(route('media.thumbnail', $first), escape: false)
        ->assertSee(route('media.show', ['medium' => $first, 'playlist' => $playlist]), escape: false);
});

test('playlist playback exposes the next item and preserves playlist context', function () {
    $user = User::factory()->create();
    $playlist = playlistFor($user, 'Evening playlist');
    $first = playlistMedium('AAAAAAAAAAA', 'First video');
    $second = playlistMedium('BBBBBBBBBBB', 'Second video');
    $playlist->playlistMedia()->attach([$first->id => ['position' => 1], $second->id => ['position' => 2]]);

    $this->actingAs($user)
        ->get(route('media.show', ['medium' => $first, 'playlist' => $playlist]))
        ->assertOk()
        ->assertSee('Next video')
        ->assertSee('https://www.youtube.com/channel/UC123456789', escape: false)
        ->assertSee('https://www.youtube.com/watch?v=AAAAAAAAAAA', escape: false)
        ->assertSee('Original video')
        ->assertSee(route('media.show', ['medium' => $second, 'playlist' => $playlist]), escape: false);
});

test('stored canonical youtube channel urls take priority over constructed urls', function () {
    $medium = playlistMedium('AAAAAAAAAAA', 'Video');
    $medium->update(['metadata' => ['channel_url' => 'https://www.youtube.com/@example']]);

    expect($medium->youtubeChannelUrl())->toBe('https://www.youtube.com/@example');
});

test('one media record can belong to multiple playlists without duplication', function () {
    $user = User::factory()->create();
    $firstPlaylist = playlistFor($user, 'First playlist');
    $secondPlaylist = playlistFor($user, 'Second playlist');
    $medium = playlistMedium('AAAAAAAAAAA', 'Shared video');

    $firstPlaylist->playlistMedia()->syncWithoutDetaching([$medium->id => ['position' => 1]]);
    $secondPlaylist->playlistMedia()->syncWithoutDetaching([$medium->id => ['position' => 4]]);

    expect(Media::query()->where('youtube_id', 'AAAAAAAAAAA')->count())->toBe(1)
        ->and($medium->sources()->count())->toBe(2);
});

test('a user cannot view another users playlist', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $playlist = playlistFor($owner, 'Private playlist');

    $this->actingAs($viewer)->get(route('sources.show', $playlist))->assertForbidden();
});
