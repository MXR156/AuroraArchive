<?php

use App\Jobs\ScanSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('derives youtube IDs from all supported source URLs and queues discovery', function (string $type, string $url, string $expectedId) {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('sources.store'), ['type' => $type, 'name' => 'Example', 'url' => $url, 'scan_interval_minutes' => 360, 'auto_download' => '1'])->assertRedirect();
    expect($user->sources()->where('type', $type)->value('external_id'))->toBe($expectedId);
    Queue::assertPushed(ScanSource::class);
})->with([
    ['channel', 'https://www.youtube.com/channel/UC123', 'UC123'],
    ['channel', 'https://youtube.com/@example/videos', '@example'],
    ['playlist', 'https://www.youtube.com/playlist?list=PL123', 'PL123'],
    ['video', 'https://youtu.be/abc123', 'abc123'],
    ['video', 'https://www.youtube.com/watch?v=xyz789', 'xyz789'],
    ['video', 'https://youtube.com/shorts/short123', 'short123'],
]);

it('rejects a youtube URL that does not match the selected source type', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('sources.store'), [
        'type' => 'playlist',
        'name' => 'Not a playlist',
        'url' => 'https://www.youtube.com/watch?v=abc123',
        'scan_interval_minutes' => 360,
    ])->assertSessionHasErrors('url');

    expect($user->sources()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('allows an owner to change a source scan interval', function () {
    $user = User::factory()->create();
    $source = $user->sources()->create(['type' => 'playlist', 'external_id' => 'PL1', 'name' => 'Playlist', 'url' => 'https://youtube.com/playlist?list=PL1']);

    $this->actingAs($user)
        ->patch(route('sources.schedule', $source), ['scan_interval_minutes' => 180])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($source->refresh()->scan_interval_minutes)->toBe(180)
        ->and($source->next_scan_at)->not->toBeNull();
});

it('rejects invalid or unauthorised source schedule changes', function () {
    $owner = User::factory()->create();
    $source = $owner->sources()->create(['type' => 'playlist', 'external_id' => 'PL1', 'name' => 'Playlist', 'url' => 'https://youtube.com/playlist?list=PL1']);

    $this->actingAs($owner)
        ->patch(route('sources.schedule', $source), ['scan_interval_minutes' => 5])
        ->assertSessionHasErrors('scan_interval_minutes');

    $this->actingAs(User::factory()->create())
        ->patch(route('sources.schedule', $source), ['scan_interval_minutes' => 60])
        ->assertForbidden();
});
