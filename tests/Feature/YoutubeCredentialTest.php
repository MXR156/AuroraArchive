<?php

use App\Contracts\YoutubeDownloader;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\mock;

uses(LazilyRefreshDatabase::class);

it('encrypts youtube cookies in the database', function () {
    $user = User::factory()->create();
    $cookies = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tsecret-value";
    $this->actingAs($user)->put(route('settings.cookies.store'), ['cookies' => UploadedFile::fake()->createWithContent('cookies.txt', $cookies)])->assertRedirect();
    $credential = $user->youtubeCredential()->firstOrFail();
    expect($credential->cookies)->toBe($cookies)->and(DB::table('youtube_credentials')->where('id', $credential->id)->value('cookies'))->not->toContain('secret-value');
});

it('updates yt-dlp to a selected release channel', function () {
    $user = User::factory()->create();
    $youtube = mock(YoutubeDownloader::class);
    $youtube->shouldReceive('update')->once()->with('nightly')->andReturn([
        'successful' => true,
        'message' => 'Updated yt-dlp to nightly.',
        'version' => '2026.08.21.123456',
    ]);

    $this->actingAs($user)
        ->post(route('settings.yt-dlp.update'), ['channel' => 'nightly'])
        ->assertRedirect()
        ->assertSessionHas('success', 'yt-dlp updated to nightly (2026.08.21.123456).');
});

it('only permits supported yt-dlp release channels', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.yt-dlp.update'), ['channel' => 'untrusted/repository'])
        ->assertSessionHasErrors('channel');
});
