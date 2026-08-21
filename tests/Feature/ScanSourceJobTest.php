<?php

use App\Contracts\YoutubeDownloader;
use App\Jobs\DownloadMedia;
use App\Jobs\ScanSource;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

it('discovers media without contacting youtube', function () {
    Queue::fake();
    $source = Source::create(['user_id' => User::factory()->create()->id, 'type' => 'channel', 'external_id' => 'UC1', 'name' => 'Channel', 'url' => 'https://youtube.com/channel/UC1', 'auto_download' => true]);
    $youtube = Mockery::mock(YoutubeDownloader::class);
    $youtube->shouldReceive('discover')->once()->andReturn([['id' => 'video123', 'title' => 'A video', 'channel' => 'Channel', 'webpage_url' => 'https://youtube.com/watch?v=video123']]);
    (new ScanSource($source))->handle($youtube);
    expect($source->media()->where('youtube_id', 'video123')->exists())->toBeTrue();
    Queue::assertPushed(DownloadMedia::class);
});
