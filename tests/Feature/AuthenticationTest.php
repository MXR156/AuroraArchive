<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('registers only the first administrator', function () {
    $this->post(route('register'), ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])->assertRedirect(route('home'));
    $this->assertAuthenticated();
    $this->post(route('register'), ['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secure-password', 'password_confirmation' => 'secure-password'])->assertRedirect(route('home'));
    expect(User::query()->count())->toBe(1);
});
