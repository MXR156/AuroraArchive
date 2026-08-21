<?php

use App\Jobs\ImportTubeSyncSources;
use App\Models\User;
use App\Services\TubeSyncImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('an authenticated user can preview a tubesync import', function () {
    $user = User::factory()->create();
    $preview = [
        'sources' => [[
            'uuid' => '52c347fc-d11f-4c2b-8268-e392ccc12565',
            'name' => 'Archived playlist',
            'type' => 'playlist',
            'key' => 'PL123',
            'media_count' => 12,
            'downloaded_count' => 10,
            'existing_files' => 9,
            'existing_thumbnails' => 8,
            'queue_candidates' => 3,
            'already_imported' => false,
        ]],
        'totals' => ['sources' => 1, 'media' => 12, 'files' => 9, 'thumbnails' => 8, 'queue_candidates' => 3],
        'media_root' => '/media',
    ];

    $importer = Mockery::mock(TubeSyncImporter::class);
    $importer->shouldReceive('preview')->once()->with($user)->andReturn($preview);
    app()->instance(TubeSyncImporter::class, $importer);

    $this->actingAs($user)
        ->get(route('imports.tubesync'))
        ->assertOk()
        ->assertSee('Archived playlist')
        ->assertSee('Queue missing eligible videos');
});

test('an authenticated user can import selected tubesync sources', function () {
    Queue::fake();
    $user = User::factory()->create();
    $uuid = '52c347fc-d11f-4c2b-8268-e392ccc12565';

    $this->actingAs($user)
        ->post(route('imports.tubesync.store'), ['sources' => [$uuid], 'queue_missing' => '1'])
        ->assertRedirect()
        ->assertSessionHas('success', 'TubeSync import queued. Large playlists will continue importing in the background.');

    Queue::assertPushed(ImportTubeSyncSources::class, fn (ImportTubeSyncSources $job): bool => $job->user->is($user)
        && $job->sourceUuids === [$uuid]
        && $job->queueMissing);
});

test('the queued tubesync import runs through the importer', function () {
    $user = User::factory()->create();
    $uuid = '52c347fc-d11f-4c2b-8268-e392ccc12565';
    $summary = ['sources' => 1, 'media' => 12, 'files' => 9, 'thumbnails' => 8, 'queued' => 3, 'metadata_only' => 0];
    $importer = Mockery::mock(TubeSyncImporter::class);
    $importer->shouldReceive('import')->once()->with($user, [$uuid], true)->andReturn($summary);

    (new ImportTubeSyncSources($user, [$uuid], true))->handle($importer);
});

test('tubesync source selections must be uuids', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('imports.tubesync.store'), ['sources' => ['not-a-uuid']])
        ->assertSessionHasErrors('sources.0');
});

test('guests cannot access the tubesync importer', function () {
    $this->get(route('imports.tubesync'))->assertRedirect(route('login'));
});
