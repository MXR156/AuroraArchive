<?php

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Jobs\DownloadMedia;
use App\Models\Media;
use App\Models\WatchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function show(Request $request, Media $medium): View
    {
        $medium->load(['files', 'source', 'attempts' => fn ($query) => $query->latest(), 'watchHistory' => fn ($query) => $query->whereBelongsTo($request->user())]);
        $related = Media::query()->whereKeyNot($medium->id)->where('channel_id', $medium->channel_id)->latest('published_at')->limit(8)->get();

        return view('media.show', compact('medium', 'related'));
    }

    public function queue(Media $medium): RedirectResponse
    {
        if (! in_array($medium->status, [MediaStatus::Queued, MediaStatus::Downloading], true)) {
            $medium->update(['status' => MediaStatus::Queued]);
            DownloadMedia::dispatch($medium);
        }

return back()->with('success', 'Download queued.');
    }

    public function stream(Media $medium): BinaryFileResponse
    {
        $file = $medium->files()->firstOrFail();
        $root = realpath(config('auroraarchive.media_root'));
        $path = realpath(rtrim(config('auroraarchive.media_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file->path);
        abort_if($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR), 404);

        return Response::file($path, ['Content-Type' => $file->mime_type ?: 'video/mp4', 'Accept-Ranges' => 'bytes']);
    }

    public function progress(Request $request, Media $medium): JsonResponse
    {
        $data = $request->validate(['position_seconds' => ['required', 'integer', 'min:0'], 'watched' => ['required', 'boolean']]);
        WatchHistory::updateOrCreate(['user_id' => $request->user()->id, 'media_id' => $medium->id], [...$data, 'last_watched_at' => now()]);

        return response()->json(['saved' => true]);
    }
}
