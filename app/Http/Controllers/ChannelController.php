<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaFile;
use App\Services\RecoverMediaUploaderNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

class ChannelController extends Controller
{
    public function index(RecoverMediaUploaderNames $recoverUploaderNames): View
    {
        $recoverUploaderNames->handle();

        $channels = Media::query()
            ->whereIn('id', MediaFile::query()->select('media_id'))
            ->whereNotNull('channel_name')
            ->where('channel_name', '!=', '')
            ->select(['channel_id', 'channel_name'])
            ->selectRaw('COUNT(*) as media_count, MAX(id) as representative_media_id')
            ->groupBy('channel_id', 'channel_name')
            ->orderBy('channel_name')
            ->paginate(48);

        return view('channels.index', compact('channels'));
    }

    public function show(string $channel): View
    {
        $mediaQuery = $this->mediaQuery($channel);
        $representative = (clone $mediaQuery)->firstOrFail();
        $media = $mediaQuery->latest('published_at')->paginate(48);

        return view('channels.show', compact('representative', 'media'));
    }

    private function mediaQuery(string $channel): Builder
    {
        $query = Media::query()->whereIn('id', MediaFile::query()->select('media_id'));
        if (str_starts_with($channel, 'id-')) {
            return $query->where('channel_id', rawurldecode(substr($channel, 3)));
        }

        abort_unless(str_starts_with($channel, 'name-'), 404);
        $encodedName = strtr(substr($channel, 5), '-_', '+/');
        $decodedName = base64_decode($encodedName.str_repeat('=', (4 - strlen($encodedName) % 4) % 4), true);
        abort_if($decodedName === false || $decodedName === '', 404);

        return $query->whereNull('channel_id')->where('channel_name', $decodedName);
    }
}
