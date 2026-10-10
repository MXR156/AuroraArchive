<?php

use App\Jobs\GenerateMediaThumbnail;
use App\Jobs\RefreshMediaThumbnail;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaThumbnail;
use App\Services\YtDlpService;
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
    $thumbnail->shouldReceive('backfill')->once()->with(Mockery::on(fn (Media $media): bool => $media->is($medium)))->andReturn('youtube');

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
    $user = User::factory()->create();
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Published thumbnail',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);

    $this->actingAs($user)
        ->post(route('media.thumbnail.refresh', $medium))
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(RefreshMediaThumbnail::class, fn (RefreshMediaThumbnail $job): bool => $job->media->is($medium) && $job->userId === $user->id);
});

test('the youtube refresh updates metadata and the published thumbnail', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Recovered title',
        'description' => 'Recovered description',
        'channel_name' => 'deleted',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $youtube = Mockery::mock(YtDlpService::class);
    $youtube->shouldReceive('metadataForRecovery')->once()->with('AAAAAAAAAAA', 123)->andReturn([
        'title' => 'Current YouTube title',
        'description' => 'Current YouTube description',
        'channel' => 'Current Channel',
        'channel_id' => 'UC-CURRENT',
        'channel_url' => 'https://www.youtube.com/channel/UC-CURRENT',
        'timestamp' => 1_700_000_000,
        'duration' => 321,
        'thumbnail' => 'https://i.ytimg.com/vi/AAAAAAAAAAA/maxresdefault.jpg',
        'availability' => 'public',
    ]);
    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('refreshFromYoutube')->once()->with(Mockery::on(fn (Media $refreshedMedia): bool => $refreshedMedia->is($medium)))->andReturnTrue();

    (new RefreshMediaThumbnail($medium, 123))->handle($youtube, $thumbnail);

    $medium->refresh();
    expect($medium->title)->toBe('Current YouTube title')
        ->and($medium->description)->toBe('Current YouTube description')
        ->and($medium->channel_name)->toBe('Current Channel')
        ->and($medium->channel_id)->toBe('UC-CURRENT')
        ->and($medium->duration_seconds)->toBe(321)
        ->and($medium->published_at?->timestamp)->toBe(1_700_000_000)
        ->and($medium->getRawOriginal('thumbnail_url'))->toBe('https://i.ytimg.com/vi/AAAAAAAAAAA/maxresdefault.jpg')
        ->and(data_get($medium->metadata, 'youtube.archive_snapshot.title'))->toBe('Current YouTube title')
        ->and(data_get($medium->metadata, 'channel_url'))->toBe('https://www.youtube.com/channel/UC-CURRENT')
        ->and(data_get($medium->metadata, 'youtube.metadata_refresh_status'))->toBe('updated')
        ->and(data_get($medium->metadata, 'youtube.availability_check_status'))->toBe('available')
        ->and(data_get($medium->metadata, 'youtube.metadata_refreshed_at'))->not->toBeNull();
});

test('the youtube refresh records when no metadata was returned', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Recovered title',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
    ]);
    $youtube = Mockery::mock(YtDlpService::class);
    $youtube->shouldReceive('metadataForRecovery')->once()->andReturnNull();
    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('refreshFromYoutube')->once()->andReturnFalse();

    (new RefreshMediaThumbnail($medium, 123))->handle($youtube, $thumbnail);

    expect(data_get($medium->refresh()->metadata, 'youtube.metadata_refresh_status'))->toBe('no_metadata')
        ->and(data_get($medium->metadata, 'youtube.metadata_refreshed_at'))->not->toBeNull();
});

test('the youtube refresh preserves manually edited metadata', function () {
    $medium = Media::query()->create([
        'youtube_id' => 'AAAAAAAAAAA',
        'title' => 'Manual title',
        'description' => 'Manual description',
        'channel_name' => 'Manual Channel',
        'original_url' => 'https://www.youtube.com/watch?v=AAAAAAAAAAA',
        'metadata' => ['manual' => ['title' => true, 'description' => true, 'channel_name' => true]],
    ]);
    $youtube = Mockery::mock(YtDlpService::class);
    $youtube->shouldReceive('metadataForRecovery')->once()->andReturn([
        'title' => 'YouTube title',
        'description' => 'YouTube description',
        'channel' => 'YouTube Channel',
        'channel_id' => 'UC-YOUTUBE',
    ]);
    $thumbnail = Mockery::mock(MediaThumbnail::class);
    $thumbnail->shouldReceive('refreshFromYoutube')->once()->andReturnTrue();

    (new RefreshMediaThumbnail($medium, 123))->handle($youtube, $thumbnail);

    $medium->refresh();
    expect($medium->title)->toBe('Manual title')
        ->and($medium->description)->toBe('Manual description')
        ->and($medium->channel_name)->toBe('Manual Channel')
        ->and($medium->channel_id)->toBe('UC-YOUTUBE');
});
