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
    $representative = downloadedChannelMedia('BBBBBBBBBBB', 'Example Creator', 'UC123');
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
        ->assertSee($representative->thumbnailRoute(), escape: false)
        ->assertSee(route('channels.show', 'id-UC123'), escape: false);
});

test('media routes use youtube ids and still resolve legacy database ids', function () {
    $user = User::factory()->create();
    $medium = downloadedChannelMedia('AAAAAAAAAAA', 'Example Creator', 'UC123');

    expect(route('media.show', $medium))->toEndWith('/watch/AAAAAAAAAAA');

    $this->actingAs($user)
        ->get(route('media.show', $medium))
        ->assertOk()
        ->assertSee('Video AAAAAAAAAAA');

    $this->actingAs($user)
        ->get('/watch/'.$medium->id)
        ->assertOk()
        ->assertSee('Video AAAAAAAAAAA');
});

test('a numeric youtube id takes precedence over a legacy database id', function () {
    $user = User::factory()->create();
    downloadedChannelMedia('AAAAAAAAAAA', 'First Creator', 'UC123');
    $numeric = downloadedChannelMedia('12345678901', 'Numeric Creator', 'UC456');

    $this->actingAs($user)
        ->get('/watch/12345678901')
        ->assertOk()
        ->assertSee($numeric->title)
        ->assertDontSee('Video AAAAAAAAAAA');
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
