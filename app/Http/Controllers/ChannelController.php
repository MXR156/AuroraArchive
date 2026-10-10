<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergeChannelRequest;
use App\Models\Media;
use App\Services\ApplyMediaFilters;
use App\Services\RecoverMediaUploaderNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ChannelController extends Controller
{
    public function index(RecoverMediaUploaderNames $recoverUploaderNames): View
    {
        $recoverUploaderNames->handle();

        $channels = Media::query()
            ->whereNotNull('channel_name')
            ->where('channel_name', '!=', '')
            ->select(['channel_id', 'channel_name'])
            ->selectRaw('COUNT(*) as media_count, MAX(id) as representative_media_id')
            ->groupBy('channel_id', 'channel_name')
            ->orderBy('channel_name')
            ->paginate(48);

        $representativeMedia = Media::query()
            ->whereKey($channels->pluck('representative_media_id'))
            ->get()
            ->keyBy('id');

        return view('channels.index', compact('channels', 'representativeMedia'));
    }

    public function show(Request $request, string $channel, ApplyMediaFilters $filters): View
    {
        $mediaQuery = $this->mediaQuery($channel);
        $representative = (clone $mediaQuery)->firstOrFail();
        $media = $filters->handle($mediaQuery, $request)->paginate(48)->withQueryString();
        $playlists = $request->user()->playlists()->orderBy('name')->get();
        $channelOptions = $this->channelOptions();

        return view('channels.show', compact('representative', 'media', 'playlists', 'channelOptions'));
    }

    public function merge(MergeChannelRequest $request, string $channel): RedirectResponse
    {
        $targetChannel = $request->validated('target_channel');
        abort_if($channel === $targetChannel, 422, 'A channel cannot be merged into itself.');

        $sourceQuery = $this->mediaQuery($channel);
        $source = (clone $sourceQuery)->firstOrFail();
        $target = $this->mediaQuery($targetChannel)->firstOrFail();
        $merged = 0;

        DB::transaction(function () use ($sourceQuery, $source, $target, &$merged): void {
            $sourceQuery->orderBy('id')->chunkById(100, function ($media) use ($source, $target, &$merged): void {
                foreach ($media as $medium) {
                    $metadata = $medium->metadata ?? [];
                    Arr::set($metadata, 'manual.channel_name', true);
                    Arr::set($metadata, 'manual.channel_id', true);
                    Arr::set($metadata, 'manual.channel_merged_at', now()->toIso8601String());
                    Arr::set($metadata, 'manual.channel_merged_from', [
                        'channel_name' => $source->channel_name,
                        'channel_id' => $source->channel_id,
                    ]);
                    $medium->update([
                        'channel_name' => $target->channel_name,
                        'channel_id' => $target->channel_id,
                        'metadata' => $metadata,
                    ]);
                    $merged++;
                }
            });
        });

        return redirect()->route('channels.show', $target->archiveChannelKey())
            ->with('success', $merged.' '.str('video')->plural($merged).' merged into '.$target->channel_name.'.');
    }

    private function mediaQuery(string $channel): Builder
    {
        $query = Media::query();
        if (str_starts_with($channel, 'id-')) {
            return $query->where('channel_id', rawurldecode(substr($channel, 3)));
        }

        abort_unless(str_starts_with($channel, 'name-'), 404);
        $encodedName = strtr(substr($channel, 5), '-_', '+/');
        $decodedName = base64_decode($encodedName.str_repeat('=', (4 - strlen($encodedName) % 4) % 4), true);
        abort_if($decodedName === false || $decodedName === '', 404);

        return $query->whereNull('channel_id')->where('channel_name', $decodedName);
    }

    private function channelOptions(): Collection
    {
        return Media::query()
            ->whereNotNull('channel_name')
            ->where('channel_name', '!=', '')
            ->select(['channel_id', 'channel_name'])
            ->selectRaw('COUNT(*) as media_count')
            ->groupBy('channel_id', 'channel_name')
            ->orderBy('channel_name')
            ->get();
    }
}
