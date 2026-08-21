<?php

use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Models\Media;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function retryMedium(string $youtubeId, MediaStatus $status): Media
{
    return Media::query()->create([
        'youtube_id' => $youtubeId,
        'title' => 'Retry '.$youtubeId,
        'status' => $status,
        'original_url' => 'https://www.youtube.com/watch?v='.$youtubeId,
    ]);
}

test('selected failed videos without files are queued for retry', function () {
    Queue::fake();
    $user = User::factory()->create();
    $first = retryMedium('AAAAAAAAAAA', MediaStatus::Failed);
    $second = retryMedium('BBBBBBBBBBB', MediaStatus::Failed);
    $notFailed = retryMedium('CCCCCCCCCCC', MediaStatus::Skipped);
    $failedWithFile = retryMedium('DDDDDDDDDDD', MediaStatus::Failed);
    MediaFile::query()->create(['media_id' => $failedWithFile->id, 'path' => 'existing.mkv']);

    $this->actingAs($user)
        ->post(route('media.bulk-retry'), ['media_ids' => [$first->id, $second->id, $notFailed->id, $failedWithFile->id]])
        ->assertRedirect()
        ->assertSessionHas('success', '2 failed videos queued for retry.');

    expect($first->refresh()->status)->toBe(MediaStatus::Queued)
        ->and($second->refresh()->status)->toBe(MediaStatus::Queued)
        ->and($notFailed->refresh()->status)->toBe(MediaStatus::Skipped)
        ->and($failedWithFile->refresh()->status)->toBe(MediaStatus::Failed);
    Queue::assertPushed(DownloadMedia::class, 2);
});

test('bulk retry requires at least one valid selection', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('media.bulk-retry'), ['media_ids' => []])
        ->assertSessionHasErrors('media_ids');
});

test('failed media cards expose retry selection controls', function () {
    $user = User::factory()->create();
    retryMedium('AAAAAAAAAAA', MediaStatus::Failed);
    retryMedium('BBBBBBBBBBB', MediaStatus::Skipped);

    $this->actingAs($user)
        ->get(route('library', ['filter' => 'failed']))
        ->assertOk()
        ->assertSee('Select all failed')
        ->assertSee('media_ids[]', escape: false);
});
