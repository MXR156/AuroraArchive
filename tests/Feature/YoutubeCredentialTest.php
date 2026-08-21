<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('encrypts youtube cookies in the database', function () {
    $user = User::factory()->create();
    $cookies = "# Netscape HTTP Cookie File\n.youtube.com\tTRUE\t/\tTRUE\t0\tSID\tsecret-value";
    $this->actingAs($user)->put(route('settings.cookies.store'), ['cookies' => UploadedFile::fake()->createWithContent('cookies.txt', $cookies)])->assertRedirect();
    $credential = $user->youtubeCredential()->firstOrFail();
    expect($credential->cookies)->toBe($cookies)->and(DB::table('youtube_credentials')->where('id', $credential->id)->value('cookies'))->not->toContain('secret-value');
});
