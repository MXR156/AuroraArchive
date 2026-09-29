<?php

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Jobs\CheckMediaAvailability;
use App\Jobs\GenerateMediaThumbnail;
use App\Jobs\QueueMediaAvailabilityChecks;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\User;
use App\Services\AvailabilityAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function availabilityMedium(string $youtubeId, bool $archived = true): Media
{
    $medium = Media::query()->create([
        'youtube_id' => $youtubeId,
        'title' => $youtubeId,
        'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
        'status' => $archived ? MediaStatus::Downloaded : MediaStatus::Failed,
    ]);
    if ($archived) {
        MediaFile::query()->create(['media_id' => $medium->id, 'path' => $youtubeId.'.mkv']);
    }

    return $medium;
}

test('a private youtube response flags archived media as unavailable', function () {
    $medium = availabilityMedium('Svio3uTT7JE');
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('checkAvailability')->once()->andReturn([
        'status' => 'unavailable',
        'reason' => 'Private video',
    ]);

    (new CheckMediaAvailability($medium))->handle($youtube, app(AvailabilityAudit::class));

    $medium->refresh();
    expect($medium->isUnavailableOnYoutube())->toBeTrue()
        ->and(data_get($medium->metadata, 'youtube.availability_check_status'))->toBe('unavailable')
        ->and(data_get($medium->metadata, 'youtube.availability_check_reason'))->toBe('Private video');
});

test('a successful youtube response clears a stale unavailable flag', function () {
    $medium = availabilityMedium('AAAAAAAAAAA');
    $medium->update(['metadata' => ['availability' => 'private', 'youtube' => ['unavailable' => true]]]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('checkAvailability')->once()->andReturn([
        'status' => 'available',
        'reason' => null,
    ]);

    (new CheckMediaAvailability($medium))->handle($youtube, app(AvailabilityAudit::class));

    expect($medium->refresh()->isUnavailableOnYoutube())->toBeFalse()
        ->and(data_get($medium->metadata, 'youtube.availability_check_status'))->toBe('available');
});

test('an inconclusive recheck no longer presents an old audit result as confirmed', function () {
    $medium = availabilityMedium('AAAAAAAAAAA');
    $medium->update(['metadata' => ['youtube' => ['availability_check_status' => 'unavailable', 'unavailable' => true]]]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('checkAvailability')->once()->andReturn([
        'status' => 'unknown',
        'reason' => 'Video unavailable',
    ]);

    (new CheckMediaAvailability($medium))->handle($youtube, app(AvailabilityAudit::class));

    expect($medium->refresh()->isUnavailableOnYoutube())->toBeFalse()
        ->and(data_get($medium->metadata, 'youtube.availability_check_status'))->toBe('unknown');
});

test('the audit queues checks only for media with archived files', function () {
    Queue::fake();
    $archived = availabilityMedium('AAAAAAAAAAA');
    availabilityMedium('BBBBBBBBBBB', false);

    (new QueueMediaAvailabilityChecks)->handle(app(AvailabilityAudit::class));

    Queue::assertPushed(CheckMediaAvailability::class, 1);
    Queue::assertPushed(CheckMediaAvailability::class, fn (CheckMediaAvailability $job): bool => $job->media->is($archived) && $job->queue === 'maintenance');
    Queue::assertPushed(GenerateMediaThumbnail::class, 1);
});

test('an authenticated user can queue an availability audit', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('library.check-availability'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(QueueMediaAvailabilityChecks::class);
});

test('an availability audit reports progress and prevents duplicate runs', function () {
    Queue::fake();
    $user = User::factory()->create();
    $medium = availabilityMedium('AAAAAAAAAAA');

    $this->actingAs($user)->post(route('library.check-availability'))->assertRedirect();
    $this->actingAs($user)->post(route('library.check-availability'))
        ->assertSessionHas('success', 'A YouTube availability audit is already in progress.');

    Queue::assertPushed(QueueMediaAvailabilityChecks::class, 1);
    $audit = app(AvailabilityAudit::class);
    $queued = $audit->latest($user->id);
    (new QueueMediaAvailabilityChecks($queued['id']))->handle($audit);

    expect($audit->latest($user->id))->toMatchArray([
        'status' => 'running',
        'total' => 1,
        'processed' => 0,
    ]);

    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('checkAvailability')->once()->andReturn([
        'status' => 'available',
        'reason' => null,
        'evidence' => [
            'yt_dlp' => ['status' => 'available', 'reason' => null],
            'watch_page' => ['status' => 'available', 'reason' => null],
        ],
    ]);
    (new CheckMediaAvailability($medium, $queued['id']))->handle($youtube, $audit);

    expect($audit->latest($user->id))->toMatchArray([
        'status' => 'completed',
        'total' => 1,
        'processed' => 1,
        'available' => 1,
    ])->and(data_get($medium->refresh()->metadata, 'youtube.availability_check_evidence.yt_dlp.status'))->toBe('available');
});
