<?php

use App\Enums\MediaStatus;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function filterableMedium(string $id, string $title, MediaStatus $status, bool $downloaded): Media
{
    $medium = Media::query()->create([
        'youtube_id' => $id,
        'title' => $title,
        'channel_name' => 'Filter Creator',
        'status' => $status,
        'original_url' => 'https://www.youtube.com/watch?v='.$id,
    ]);
    if ($downloaded) {
        MediaFile::query()->create(['media_id' => $medium->id, 'path' => 'Filter Creator/'.$id.'.mkv']);
    }

    return $medium;
}

test('the library filters downloaded and not downloaded media using registered files', function () {
    $user = User::factory()->create();
    filterableMedium('AAAAAAAAAAA', 'Downloaded video', MediaStatus::Downloaded, true);
    filterableMedium('BBBBBBBBBBB', 'Missing video', MediaStatus::Skipped, false);

    $this->actingAs($user)->get(route('library', ['filter' => 'downloaded']))
        ->assertOk()->assertSee('Downloaded video')->assertDontSee('Missing video');
    $this->actingAs($user)->get(route('library', ['filter' => 'not_downloaded']))
        ->assertOk()->assertSee('Missing video')->assertDontSee('Downloaded video');
});

test('the library supports status filtering and title sorting', function () {
    $user = User::factory()->create();
    filterableMedium('AAAAAAAAAAA', 'Zulu skipped', MediaStatus::Skipped, false);
    filterableMedium('BBBBBBBBBBB', 'Alpha skipped', MediaStatus::Skipped, false);
    filterableMedium('CCCCCCCCCCC', 'Failed video', MediaStatus::Failed, false);

    $this->actingAs($user)->get(route('library', ['filter' => 'skipped', 'sort' => 'title']))
        ->assertOk()
        ->assertSeeInOrder(['Alpha skipped', 'Zulu skipped'])
        ->assertDontSee('Failed video');
});

test('the library can sort by the most recently downloaded file', function () {
    $user = User::factory()->create();
    $older = filterableMedium('AAAAAAAAAAA', 'Older download', MediaStatus::Downloaded, true);
    $newer = filterableMedium('BBBBBBBBBBB', 'Newer download', MediaStatus::Downloaded, true);
    $older->files()->update(['created_at' => now()->subDay()]);
    $newer->files()->update(['created_at' => now()]);

    $this->actingAs($user)
        ->get(route('library', ['sort' => 'recently_downloaded']))
        ->assertOk()
        ->assertSeeInOrder(['Newer download', 'Older download']);
});

test('media filter dropdowns expose automatic submission controls', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('library'))
        ->assertOk()
        ->assertSee('data-media-filters', escape: false)
        ->assertSee('aria-label="Media filter"', escape: false)
        ->assertSee('aria-label="Media sort order"', escape: false);
});

test('playlist pages use the shared filters while retaining playlist order by default', function () {
    $user = User::factory()->create();
    $source = Source::query()->create([
        'user_id' => $user->id,
        'type' => 'playlist',
        'external_id' => 'PLFILTER',
        'name' => 'Filter playlist',
        'url' => 'https://www.youtube.com/playlist?list=PLFILTER',
    ]);
    $first = filterableMedium('AAAAAAAAAAA', 'First item', MediaStatus::Skipped, false);
    $second = filterableMedium('BBBBBBBBBBB', 'Second item', MediaStatus::Downloaded, true);
    $source->playlistMedia()->attach([$first->id => ['position' => 1], $second->id => ['position' => 2]]);

    $this->actingAs($user)->get(route('sources.show', [$source, 'filter' => 'not_downloaded']))
        ->assertOk()
        ->assertSee('First item')
        ->assertDontSee('Second item');

    $this->actingAs($user)->get(route('sources.show', [$source, 'sort' => 'playlist_reverse']))
        ->assertOk()
        ->assertSeeInOrder(['Second item', 'First item']);
});

test('library pagination shows page numbers and first and last controls', function () {
    $user = User::factory()->create();
    foreach (range(1, 26) as $number) {
        filterableMedium(sprintf('VID%08d', $number), sprintf('Page item %02d', $number), MediaStatus::Skipped, false);
    }

    $this->actingAs($user)
        ->get(route('library', ['filter' => 'skipped', 'sort' => 'title']))
        ->assertOk()
        ->assertSee('aria-label="Pagination"', escape: false)
        ->assertSee('aria-label="First page"', escape: false)
        ->assertSee('aria-label="Last page"', escape: false)
        ->assertSee(route('library', ['filter' => 'skipped', 'sort' => 'title', 'page' => 2]));
});

test('creator pages can show only skipped media', function () {
    $user = User::factory()->create();
    filterableMedium('AAAAAAAAAAA', 'Skipped creator video', MediaStatus::Skipped, false);
    filterableMedium('BBBBBBBBBBB', 'Downloaded creator video', MediaStatus::Downloaded, true);

    $channel = Media::query()->where('youtube_id', 'AAAAAAAAAAA')->firstOrFail();
    $this->actingAs($user)->get(route('channels.show', [$channel->archiveChannelKey(), 'filter' => 'skipped']))
        ->assertOk()
        ->assertSee('Skipped creator video')
        ->assertDontSee('Downloaded creator video');
});
