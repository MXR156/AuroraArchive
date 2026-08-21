<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlaylistRequest;
use App\Http\Requests\UpdatePlaylistRequest;
use App\Models\Playlist;
use App\Services\ApplyMediaFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlaylistController extends Controller
{
    public function index(Request $request): View
    {
        $playlists = $request->user()->playlists()->withCount('media')->latest()->get();

        return view('playlists.index', compact('playlists'));
    }

    public function store(StorePlaylistRequest $request): RedirectResponse
    {
        $playlist = $request->user()->playlists()->create($request->validated());

        return redirect()->route('playlists.show', $playlist)->with('success', 'Playlist created.');
    }

    public function show(Request $request, Playlist $playlist, ApplyMediaFilters $filters): View
    {
        $this->owned($request, $playlist);
        $media = $filters->handle($playlist->media(), $request, playlistOrder: true)->paginate(48)->withQueryString();

        return view('playlists.show', compact('playlist', 'media'));
    }

    public function update(UpdatePlaylistRequest $request, Playlist $playlist): RedirectResponse
    {
        $this->owned($request, $playlist);
        $playlist->update($request->validated());

        return back()->with('success', 'Playlist renamed.');
    }

    public function destroy(Request $request, Playlist $playlist): RedirectResponse
    {
        $this->owned($request, $playlist);
        $playlist->delete();

        return redirect()->route('playlists.index')->with('success', 'Playlist deleted; archived media was preserved.');
    }

    private function owned(Request $request, Playlist $playlist): void
    {
        abort_unless($playlist->user_id === $request->user()->id, 403);
    }
}
