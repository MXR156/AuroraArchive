<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function downloadedChannelMedia(string $youtubeId, string $channelName, ?string $channelId = null): Media
{
    $medium = Media::query()->create([
        'youtube_id' => $youtubeId,
        'title' => 'Video '.$youtubeId,
        'channel_name' => $channelName,
        'channel_id' => $channelId,
        'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
    ]);
    MediaFile::query()->create(['media_id' => $medium->id, 'path' => $channelName.'/'.$youtubeId.'.mkv']);

    return $medium;
}

test('the channels page groups archived media by creator', function () {
    $user = User::factory()->create();
    downloadedChannelMedia('AAAAAAAAAAA', 'Example Creator', 'UC123');
    downloadedChannelMedia('BBBBBBBBBBB', 'Example Creator', 'UC123');
    Media::query()->create([
        'youtube_id' => 'CCCCCCCCCCC',
        'title' => 'Catalogue only',
        'channel_name' => 'Not Downloaded',
        'original_url' => 'https://www.youtube.com/watch?v=CCCCCCCCCCC',
    ]);

    $this->actingAs($user)
        ->get(route('channels.index'))
        ->assertOk()
        ->assertSee('Example Creator')
        ->assertSee('2 videos')
        ->assertSee('Not Downloaded')
        ->assertSee(route('channels.show', 'id-UC123'), escape: false);
});

test('downloaded video cards link creator names to the local channel page', function () {
    $user = User::factory()->create();
    $medium = downloadedChannelMedia('AAAAAAAAAAA', 'Example Creator', 'UC123');
    $medium->update(['status' => MediaStatus::Downloaded]);

    $this->actingAs($user)
        ->get(route('library'))
        ->assertOk()
        ->assertSee(route('channels.show', $medium->archiveChannelKey()), escape: false);
});

test('a creator page lists only that creators downloaded media', function () {
    $user = User::factory()->create();
    downloadedChannelMedia('AAAAAAAAAAA', 'Example Creator', 'UC123');
    downloadedChannelMedia('BBBBBBBBBBB', 'Another Creator', 'UC456');

    $this->actingAs($user)
        ->get(route('channels.show', 'id-UC123'))
        ->assertOk()
        ->assertSee('Example Creator')
        ->assertSee('Video AAAAAAAAAAA')
        ->assertDontSee('Video BBBBBBBBBBB');
});

test('folder derived creator names have browseable channel pages', function () {
    $user = User::factory()->create();
    $medium = downloadedChannelMedia('AAAAAAAAAAA', 'Recovered Creator');

    $this->actingAs($user)
        ->get(route('channels.show', $medium->archiveChannelKey()))
        ->assertOk()
        ->assertSee('Recovered Creator')
        ->assertSee('Video AAAAAAAAAAA');
});

test('guests cannot browse channels', function () {
    $this->get(route('channels.index'))->assertRedirect(route('login'));
});
