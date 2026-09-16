<?php

use App\Jobs\GenerateMediaThumbnail;
use App\Jobs\RefreshMediaThumbnail;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('thumbnail generation runs outside the web request', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Embedded thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('generate')->once()->with($medium);

    (new GenerateMediaThumbnail($medium))->handle($thumbnail);
});

test('thumbnail jobs are unique per media record', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Embedded thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    expect((new GenerateMediaThumbnail($medium))->uniqueId())->toBe((string) $medium->id);
});

test('an authenticated user can queue a published thumbnail refresh', function () {
    Queue::fake();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Published thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('media.thumbnail.refresh', $medium))
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(RefreshMediaThumbnail::class, fn (RefreshMediaThumbnail $job): bool => $job->media->is($medium));
});
