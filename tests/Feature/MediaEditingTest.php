<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an authenticated user can edit video channel name title and description', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Imported title',
        'description' => 'Imported description',
        'channel_name' => 'Unavailable channel',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
        'metadata' => ['tubesync' => ['preserved' => true]],
    ]);

    $this->actingAs($user)
        ->put(route('media.update', $medium), [
            'channel_name' => 'Archived creator',
            'title' => 'Corrected title',
            'description' => 'Corrected description',
        ])
        ->assertRedirect(route('media.show', $medium))
        ->assertSessionHas('success');

    $medium->refresh();
    expect($medium->channel_name)->toBe('Archived creator')
        ->and($medium->title)->toBe('Corrected title')
        ->and($medium->description)->toBe('Corrected description')
        ->and(data_get($medium->metadata, 'manual.channel_name'))->toBeTrue()
        ->and(data_get($medium->metadata, 'manual.title'))->toBeTrue()
        ->and(data_get($medium->metadata, 'manual.description'))->toBeTrue()
        ->and(data_get($medium->metadata, 'tubesync.preserved'))->toBeTrue();
});

test('video metadata editing validates the title', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Keep this title',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->actingAs($user)
        ->put(route('media.update', $medium), ['title' => '', 'description' => 'Description'])
        ->assertSessionHasErrors('title');

    expect($medium->refresh()->title)->toBe('Keep this title');
});

test('guests cannot edit video metadata', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Private edit',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->get(route('media.edit', $medium))->assertRedirect(route('login'));
    $this->put(route('media.update', $medium), ['title' => 'Changed'])->assertRedirect(route('login'));
});
