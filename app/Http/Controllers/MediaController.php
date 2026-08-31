<?php

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Http\Requests\BulkManageMediaRequest;
use App\Http\Requests\BulkRetryMediaRequest;
use App\Http\Requests\UpdateMediaRequest;
use App\Jobs\DownloadMedia;
use App\Jobs\GenerateMediaThumbnail;
use App\Models\Media;
use App\Models\Playlist;
use App\Models\WatchHistory;
use App\Services\DeleteMedia;
use App\Services\MediaThumbnail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function show(Request $request, Media $medium): View
    {
        $medium->load(['files', 'source', 'attempts' => fn ($query) => $query->latest(), 'watchHistory' => fn ($query) => $query->whereBelongsTo($request->user())]);
        $related = Media::query()->whereKeyNot($medium->id)->where('channel_id', $medium->channel_id)->latest('published_at')->limit(8)->get();
        $playlistId = $request->integer('playlist');
        $localPlaylistId = $request->integer('local_playlist');
        abort_if($playlistId > 0 && $localPlaylistId > 0, 404);
        $playlist = $playlistId > 0 ? $request->user()->sources()->find($playlistId) : null;
        $localPlaylist = $localPlaylistId > 0 ? $request->user()->playlists()->find($localPlaylistId) : null;
        abort_if($playlistId > 0 && $playlist === null, 404);
        abort_if($localPlaylistId > 0 && $localPlaylist === null, 404);
        $playlistMedia = $playlist?->playlistMedia() ?? $localPlaylist?->media();
        $membership = $playlistMedia?->whereKey($medium->id)->first();
        abort_if($playlistMedia !== null && $membership === null, 404);
        $nextMedia = $playlist?->playlistMedia() ?? $localPlaylist?->media();
        $next = $membership === null ? null : $nextMedia
            ->wherePivot('position', '>', $membership->pivot->position)
            ->orderByPivot('position')
            ->orderBy('media.id')
            ->first();
        $playlistName = $playlist?->name ?? $localPlaylist?->name;
        $playlistUrl = $playlist !== null ? route('sources.show', $playlist) : ($localPlaylist !== null ? route('playlists.show', $localPlaylist) : null);
        $playlists = $request->user()->playlists()->orderBy('name')->get();
        $nextUrl = $next === null ? null : route('media.show', [
            'medium' => $next,
            ...($playlist !== null ? ['playlist' => $playlist] : ['local_playlist' => $localPlaylist]),
        ]);

        return view('media.show', compact('medium', 'related', 'next', 'playlistName', 'playlistUrl', 'playlists', 'nextUrl'));
    }

    public function queue(Media $medium): RedirectResponse
    {
        if (! in_array($medium->status, [MediaStatus::Queued, MediaStatus::Downloading], true)) {
            $medium->update(['status' => MediaStatus::Queued]);
            DownloadMedia::dispatch($medium);
        }

        return back()->with('success', 'Download queued.');
    }

    public function bulkRetry(BulkRetryMediaRequest $request): RedirectResponse
    {
        $media = Media::query()
            ->whereIn('id', $request->validated('media_ids'))
            ->where('status', MediaStatus::Failed)
            ->whereDoesntHave('files')
            ->get();

        foreach ($media as $medium) {
            $medium->update(['status' => MediaStatus::Queued]);
            DownloadMedia::dispatch($medium);
        }

        return back()->with('success', $media->count().' failed '.str('video')->plural($media->count()).' queued for retry.');
    }

    public function bulkManage(BulkManageMediaRequest $request, DeleteMedia $deleteMedia): RedirectResponse
    {
        $action = $request->validated('action');
        $media = Media::query()
            ->whereIn('id', $request->validated('media_ids'))
            ->with('files')
            ->get();

        if (in_array($action, ['add_to_playlist', 'remove_from_playlist'], true)) {
            $playlist = $request->user()->playlists()->findOrFail($request->validated('playlist_id'));

            return $action === 'add_to_playlist'
                ? $this->addToPlaylist($playlist, $media)
                : $this->removeFromPlaylist($playlist, $media);
        }

        if ($action === 'delete') {
            foreach ($media as $medium) {
                $deleteMedia->handle($medium);
            }

            return back()->with('success', $media->count().' selected '.str('video')->plural($media->count()).' deleted.');
        }

        $queued = $media->filter(fn (Media $medium): bool => $medium->files->isEmpty()
            && ! in_array($medium->status, [MediaStatus::Queued, MediaStatus::Downloading], true));

        foreach ($queued as $medium) {
            $medium->update(['status' => MediaStatus::Queued]);
            DownloadMedia::dispatch($medium);
        }

        return back()->with('success', $queued->count().' selected '.str('video')->plural($queued->count()).' queued for download.');
    }

    /** @param Collection<int, Media> $media */
    private function addToPlaylist(Playlist $playlist, Collection $media): RedirectResponse
    {
        $existingIds = $playlist->media()->whereKey($media->modelKeys())->pluck('media.id');
        $newMedia = $media->whereNotIn('id', $existingIds);
        $position = (int) $playlist->media()->max('media_playlist.position');

        foreach ($newMedia as $medium) {
            $playlist->media()->attach($medium->id, ['position' => ++$position]);
        }

        return back()->with('success', $newMedia->count().' '.str('video')->plural($newMedia->count()).' added to '.$playlist->name.'.');
    }

    /** @param Collection<int, Media> $media */
    private function removeFromPlaylist(Playlist $playlist, Collection $media): RedirectResponse
    {
        $removed = $playlist->media()->detach($media->modelKeys());

        return back()->with('success', $removed.' '.str('video')->plural($removed).' removed from '.$playlist->name.'.');
    }

    public function edit(Media $medium): View
    {
        return view('media.edit', compact('medium'));
    }

    public function update(UpdateMediaRequest $request, Media $medium): RedirectResponse
    {
        $metadata = $medium->metadata ?? [];
        Arr::set($metadata, 'manual.channel_name', true);
        Arr::set($metadata, 'manual.title', true);
        Arr::set($metadata, 'manual.description', true);
        Arr::set($metadata, 'manual.edited_at', now()->toIso8601String());
        $medium->update([...$request->validated(), 'metadata' => $metadata]);

        return redirect()->route('media.show', $medium)->with('success', 'Video metadata updated.');
    }

    public function destroy(Media $medium, DeleteMedia $deleteMedia): RedirectResponse
    {
        $deleteMedia->handle($medium);

        return redirect()->route('library')->with('success', 'Media and its files were deleted.');
    }

    public function stream(Media $medium): BinaryFileResponse|HttpResponse
    {
        $file = $medium->files()->firstOrFail();
        $root = realpath(config('auroraarchive.media_root'));
        $path = realpath(rtrim(config('auroraarchive.media_root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file->path);
        abort_if($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR), 404);

        $headers = [
            'Content-Type' => $file->mime_type ?: 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ];
        if (config('auroraarchive.accelerated_streaming')) {
            $relativePath = str_replace('\\', '/', $file->path);
            $headers['X-Accel-Redirect'] = '/protected-media/'.implode('/', array_map('rawurlencode', explode('/', $relativePath)));

            return response('', 200, $headers);
        }

        return Response::file($path, $headers)->setAutoEtag()->setAutoLastModified();
    }

    public function thumbnail(Media $medium, MediaThumbnail $thumbnail): BinaryFileResponse|RedirectResponse
    {
        $path = $thumbnail->path($medium);
        if ($path === null) {
            GenerateMediaThumbnail::dispatch($medium);

            return redirect()->away('https://i.ytimg.com/vi/'.$medium->youtube_id.'/hqdefault.jpg');
        }

        return Response::file($path, ['Content-Type' => mime_content_type($path) ?: 'image/jpeg']);
    }

    public function progress(Request $request, Media $medium): JsonResponse
    {
        $data = $request->validate(['position_seconds' => ['required', 'integer', 'min:0'], 'watched' => ['required', 'boolean']]);
        WatchHistory::updateOrCreate(['user_id' => $request->user()->id, 'media_id' => $medium->id], [...$data, 'last_watched_at' => now()]);

        return response()->json(['saved' => true]);
    }
}
