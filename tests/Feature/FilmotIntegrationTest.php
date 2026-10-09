<?php

use App\Models\FilmotCredential;
use App\Models\Media;
use App\Models\User;
use App\Services\FilmotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('encrypts the Filmot RapidAPI key in the database', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.filmot.store'), ['api_key' => 'filmot-secret-key'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $credential = $user->filmotCredential()->firstOrFail();

    expect($credential->api_key)->toBe('filmot-secret-key')
        ->and(DB::table('filmot_credentials')->where('id', $credential->id)->value('api_key'))
        ->not->toContain('filmot-secret-key');
});

it('looks up and normalises matching Filmot metadata', function () {
    config()->set('auroraarchive.filmot_api_host', 'filmot.example.test');
    Http::preventStrayRequests();
    Http::fake([
        'https://filmot.example.test/getvideos*' => Http::response([[
            'id' => 'z9jn0n7rKDM',
            'title' => 'Recovered title',
            'description' => 'Recovered description',
            'channelname' => 'Recovered channel',
            'channelid' => 'UC123',
            'uploaddate' => '20240131',
            'duration' => '615',
        ]]),
    ]);

    $result = app(FilmotService::class)->lookup('z9jn0n7rKDM', 'secret-key');

    expect($result)
        ->toMatchArray([
            'youtube_id' => 'z9jn0n7rKDM',
            'title' => 'Recovered title',
            'channel_name' => 'Recovered channel',
            'channel_id' => 'UC123',
            'published_at' => '2024-01-31',
            'duration_seconds' => 615,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-rapidapi-key', 'secret-key')
        && $request->hasHeader('x-rapidapi-host', 'filmot.example.test')
        && $request['id'] === 'z9jn0n7rKDM');
});

it('previews Filmot metadata before changing a video', function () {
    $user = User::factory()->create();
    FilmotCredential::query()->create(['user_id' => $user->id, 'api_key' => 'secret-key']);
    $medium = Media::query()->create([
        'youtube_id' => 'z9jn0n7rKDM',
        'title' => 'Archive title',
        'original_url' => 'https://www.youtube.com/watch?v=z9jn0n7rKDM',
    ]);
    Http::fake(['*/getvideos*' => Http::response([[
        'id' => 'z9jn0n7rKDM',
        'title' => 'Filmot title',
        'channelname' => 'Filmot channel',
    ]])]);

    $this->actingAs($user)
        ->post(route('media.filmot.lookup', $medium))
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionHas('filmot_previews.'.$medium->id.'.title', 'Filmot title');

    $this->get(route('media.show', $medium))
        ->assertOk()
        ->assertSee('Recovered metadata preview')
        ->assertSee('Filmot title');

    expect($medium->refresh()->title)->toBe('Archive title');
});

it('converts pasted metadata JSON and preserves description line breaks', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'wfAOfJe5zx8',
        'title' => 'Recovered wfAOfJe5zx8',
        'original_url' => 'https://www.youtube.com/watch?v=wfAOfJe5zx8',
    ]);
    $json = json_encode([
        'id' => 'wfAOfJe5zx8',
        'uploaddate' => '2025-05-05',
        'duration' => 639,
        'title' => 'Uber driver sorts you out before the ride',
        'channelid' => 'UCWND-WuSwh00fJq1Akwiv-w',
        'channelname' => 'WHITE WITCH ASMR',
        'description' => "First paragraph.\n\nSecond paragraph.\nFinal line.",
    ], JSON_THROW_ON_ERROR);

    $this->actingAs($user)
        ->post(route('media.metadata.preview', $medium), ['metadata_json' => $json])
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionHas('filmot_previews.'.$medium->id.'.description', "First paragraph.\n\nSecond paragraph.\nFinal line.");

    $this->post(route('media.filmot.apply', $medium), [
        'fields' => ['title', 'description', 'channel_name', 'channel_id', 'published_at', 'duration_seconds'],
    ])->assertRedirect()->assertSessionHas('success');

    $medium->refresh();

    expect($medium->title)->toBe('Uber driver sorts you out before the ride')
        ->and($medium->description)->toBe("First paragraph.\n\nSecond paragraph.\nFinal line.")
        ->and($medium->channel_name)->toBe('WHITE WITCH ASMR')
        ->and($medium->channel_id)->toBe('UCWND-WuSwh00fJq1Akwiv-w')
        ->and($medium->published_at->toDateString())->toBe('2025-05-05')
        ->and($medium->duration_seconds)->toBe(639)
        ->and(data_get($medium->metadata, 'pasted_metadata.snapshot.description'))->toBe("First paragraph.\n\nSecond paragraph.\nFinal line.");
});

it('rejects pasted metadata for a different video ID', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'wfAOfJe5zx8',
        'title' => 'Archive title',
        'original_url' => 'https://www.youtube.com/watch?v=wfAOfJe5zx8',
    ]);

    $this->actingAs($user)
        ->post(route('media.metadata.preview', $medium), [
            'metadata_json' => json_encode(['id' => 'AAAAAAAAAAA', 'title' => 'Wrong video'], JSON_THROW_ON_ERROR),
        ])
        ->assertSessionHasErrors('metadata_json')
        ->assertSessionMissing('filmot_previews.'.$medium->id);

    expect($medium->refresh()->title)->toBe('Archive title');
});

it('imports only selected Filmot fields and records provenance', function () {
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'z9jn0n7rKDM',
        'title' => 'Archive title',
        'description' => 'Keep this description',
        'channel_name' => 'Archive channel',
        'original_url' => 'https://www.youtube.com/watch?v=z9jn0n7rKDM',
        'metadata' => ['recovery' => ['preserved' => true]],
    ]);
    $candidate = [
        'youtube_id' => 'z9jn0n7rKDM',
        'title' => 'Filmot title',
        'description' => 'Filmot description',
        'channel_name' => 'Filmot channel',
        'retrieved_at' => '2026-10-09T20:00:00+00:00',
    ];

    $this->actingAs($user)
        ->withSession(['filmot_previews.'.$medium->id => $candidate])
        ->post(route('media.filmot.apply', $medium), ['fields' => ['title', 'channel_name']])
        ->assertRedirect()
        ->assertSessionHas('success');

    $medium->refresh();

    expect($medium->title)->toBe('Filmot title')
        ->and($medium->channel_name)->toBe('Filmot channel')
        ->and($medium->description)->toBe('Keep this description')
        ->and(data_get($medium->metadata, 'recovery.preserved'))->toBeTrue()
        ->and(data_get($medium->metadata, 'filmot.snapshot.title'))->toBe('Filmot title')
        ->and(data_get($medium->metadata, 'filmot.imported_fields'))->toBe(['title', 'channel_name']);
});

it('does not preview a mismatched Filmot video', function () {
    $user = User::factory()->create();
    FilmotCredential::query()->create(['user_id' => $user->id, 'api_key' => 'secret-key']);
    $medium = Media::query()->create([
        'youtube_id' => 'z9jn0n7rKDM',
        'title' => 'Archive title',
        'original_url' => 'https://www.youtube.com/watch?v=z9jn0n7rKDM',
    ]);
    Http::fake(['*/getvideos*' => Http::response([[
        'id' => 'AAAAAAAAAAA',
        'title' => 'Wrong video',
    ]])]);

    $this->actingAs($user)
        ->post(route('media.filmot.lookup', $medium))
        ->assertSessionHasErrors('filmot')
        ->assertSessionMissing('filmot_previews.'.$medium->id);
});
