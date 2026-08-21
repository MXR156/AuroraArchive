<?php

use App\Jobs\ScanSource;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('adds all supported source types and queues discovery', function (string $type, string $url) {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('sources.store'), ['type' => $type, 'name' => 'Example', 'external_id' => 'external-'.$type, 'url' => $url, 'scan_interval_minutes' => 360, 'auto_download' => '1'])->assertRedirect();
    expect($user->sources()->where('type', $type)->exists())->toBeTrue();
    Queue::assertPushed(ScanSource::class);
})->with([['channel', 'https://www.youtube.com/channel/UC123'], ['playlist', 'https://www.youtube.com/playlist?list=PL123'], ['video', 'https://youtu.be/abc123']]);
