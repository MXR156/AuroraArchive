<?php

use App\Models\Media;
use App\Models\User;
use App\Services\TubeSyncImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('it imports saved media metadata in preference to sparse playlist metadata', function () {
    config()->set('database.connections.tubesync', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('auroraarchive.media_root', storage_path('framework/testing/missing-tubesync-media'));
    DB::purge('tubesync');

    Schema::connection('tubesync')->create('sync_source', function (Blueprint $table): void {
        $table->uuid('uuid')->primary();
        $table->string('source_type');
        $table->string('key');
        $table->string('name');
        $table->integer('index_schedule')->default(21600);
        $table->boolean('download_media')->default(false);
        $table->timestamp('last_crawl')->nullable();
    });
    Schema::connection('tubesync')->create('sync_media', function (Blueprint $table): void {
        $table->uuid('uuid')->primary();
        $table->uuid('source_id');
        $table->string('key');
        $table->string('title')->nullable();
        $table->timestamp('published')->nullable();
        $table->string('thumb')->nullable();
        $table->string('media_file')->nullable();
        $table->boolean('downloaded')->default(false);
        $table->boolean('can_download')->default(false);
        $table->boolean('skip')->default(false);
        $table->boolean('manual_skip')->default(false);
        $table->integer('duration')->nullable();
        $table->unsignedBigInteger('downloaded_filesize')->nullable();
        $table->integer('downloaded_width')->nullable();
        $table->integer('downloaded_height')->nullable();
        $table->timestamp('created')->nullable();
    });
    Schema::connection('tubesync')->create('sync_media_metadata', function (Blueprint $table): void {
        $table->uuid('source_id')->nullable();
        $table->uuid('media_id')->nullable();
        $table->string('key')->nullable();
        $table->json('value');
    });

    $sourceUuid = '52c347fc-d11f-4c2b-8268-e392ccc12565';
    $mediaUuid = '4dd32a22-7707-4556-a07e-226645689a84';
    DB::connection('tubesync')->table('sync_source')->insert([
        'uuid' => $sourceUuid,
        'source_type' => 'p',
        'key' => 'PL123',
        'name' => 'Archived playlist',
    ]);
    DB::connection('tubesync')->table('sync_media')->insert([
        'uuid' => $mediaUuid,
        'source_id' => $sourceUuid,
        'key' => 'video123',
        'title' => 'Fallback media title',
    ]);
    DB::connection('tubesync')->table('sync_media_metadata')->insert([
        [
            'source_id' => $sourceUuid,
            'media_id' => null,
            'key' => 'video123',
            'value' => json_encode(['id' => 'video123', 'title' => 'Sparse playlist title', 'playlist_index' => 7], JSON_THROW_ON_ERROR),
        ],
        [
            'source_id' => null,
            'media_id' => $mediaUuid,
            'key' => 'video123',
            'value' => json_encode([
                'id' => 'video123',
                'title' => 'Saved TubeSync title',
                'description' => 'Saved TubeSync description',
                'channel' => 'Saved channel name',
                'channel_id' => 'UCsaved',
                'availability' => 'unavailable',
                'webpage_url' => 'https://www.youtube.com/watch?v=video123',
            ], JSON_THROW_ON_ERROR),
        ],
    ]);

    app(TubeSyncImporter::class)->import(User::factory()->create(), [$sourceUuid], false);

    $medium = Media::query()->where('youtube_id', 'video123')->firstOrFail();

    expect($medium)
        ->title->toBe('Saved TubeSync title')
        ->description->toBe('Saved TubeSync description')
        ->channel_name->toBe('Saved channel name')
        ->channel_id->toBe('UCsaved')
        ->and($medium->isUnavailableOnYoutube())->toBeTrue()
        ->and(data_get($medium->metadata, "tubesync.sources.{$sourceUuid}.metadata.playlist_index"))->toBe(7);
});
