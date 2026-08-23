<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('shows the AuroraArchive logo and favicon on the sign in page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(asset('images/auroraarchive-192.png'), escape: false)
        ->assertSee(asset('favicon.ico'), escape: false)
        ->assertSee(asset('site.webmanifest'), escape: false);
});

it('registers only the first administrator', function () {
    $this->post(route('register'), ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])->assertRedirect(route('home'));
    $this->assertAuthenticated();
    $this->post(route('register'), ['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])->assertRedirect(route('home'));
    expect(User::query()->count())->toBe(1);
});
