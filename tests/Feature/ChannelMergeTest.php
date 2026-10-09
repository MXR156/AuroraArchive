<?php

use App\Models\Media;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an archive channel can be merged into a canonical channel', function () {
    $user = User::factory()->create();
    $target = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Available video',
        'channel_name' => 'LeeMur ASMR',
        'channel_id' => 'UC-CANONICAL',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $orphaned = Media::query()->create([
        'youtube_id' => 'BBBBBBBBBBB',
        'title' => 'Deleted video',
        'channel_name' => 'LeeMur ASMR',
        'channel_id' => 'UC-WRONG',
        'original_url' => 'https://www.youtube.com/watch?v=BBBBBBBBBBB',
        'metadata' => ['recovery' => ['preserved' => true]],
    ]);
    $secondOrphaned = Media::query()->create([
        'youtube_id' => 'CCCCCCCCCCC',
        'title' => 'Another deleted video',
        'channel_name' => 'LeeMur ASMR',
        'channel_id' => 'UC-WRONG',
        'original_url' => 'https://www.youtube.com/watch?v=CCCCCCCCCCC',
    ]);
    $orphaned->files()->create(['path' => 'archive/deleted-video.mkv']);
    $playlist = Playlist::query()->create(['user_id' => $user->id, 'name' => 'Saved videos']);
    $playlist->media()->attach($orphaned, ['position' => 1]);

    $this->actingAs($user)
        ->post(route('channels.merge', 'id-UC-WRONG'), [
            'target_channel' => $target->archiveChannelKey(),
        ])
        ->assertRedirect(route('channels.show', $target->archiveChannelKey()))
        ->assertSessionHas('success');

    foreach ([$orphaned, $secondOrphaned] as $medium) {
        $medium->refresh();
        expect($medium->channel_name)->toBe('LeeMur ASMR')
            ->and($medium->channel_id)->toBe('UC-CANONICAL')
            ->and(data_get($medium->metadata, 'manual.channel_name'))->toBeTrue()
            ->and(data_get($medium->metadata, 'manual.channel_id'))->toBeTrue()
            ->and(data_get($medium->metadata, 'manual.channel_merged_from.channel_id'))->toBe('UC-WRONG');
    }

    expect($orphaned->files()->count())->toBe(1)
        ->and($playlist->media()->whereKey($orphaned)->exists())->toBeTrue()
        ->and(Media::query()->where('channel_id', 'UC-WRONG')->exists())->toBeFalse();
});

test('a channel cannot be merged into itself', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Video',
        'channel_name' => 'Creator',
        'channel_id' => 'UC-CREATOR',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->actingAs($user)
        ->post(route('channels.merge', $medium->archiveChannelKey()), [
            'target_channel' => $medium->archiveChannelKey(),
        ])
        ->assertUnprocessable();

    expect($medium->refresh()->channel_id)->toBe('UC-CREATOR');
});

test('guests cannot merge archive channels', function () {
    $this->post('/channels/id-UC-WRONG/merge', ['target_channel' => 'id-UC-CANONICAL'])
        ->assertRedirect(route('login'));
});
