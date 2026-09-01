<?php

use App\Contracts\YoutubeDownloader;
use App\Enums\MediaStatus;
use App\Jobs\CheckMediaAvailability;
use App\Jobs\QueueMediaAvailabilityChecks;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\User;
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

    (new CheckMediaAvailability($medium))->handle($youtube);

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

    (new CheckMediaAvailability($medium))->handle($youtube);

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

    (new CheckMediaAvailability($medium))->handle($youtube);

    expect($medium->refresh()->isUnavailableOnYoutube())->toBeFalse()
        ->and(data_get($medium->metadata, 'youtube.availability_check_status'))->toBe('unknown');
});

test('the audit queues checks only for media with archived files', function () {
    Queue::fake();
    $archived = availabilityMedium('AAAAAAAAAAA');
    availabilityMedium('BBBBBBBBBBB', false);

    (new QueueMediaAvailabilityChecks)->handle();

    Queue::assertPushed(CheckMediaAvailability::class, 1);
    Queue::assertPushed(CheckMediaAvailability::class, fn (CheckMediaAvailability $job): bool => $job->media->is($archived) && $job->queue === 'maintenance');
});

test('an authenticated user can queue an availability audit', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('library.check-availability'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(QueueMediaAvailabilityChecks::class);
});
