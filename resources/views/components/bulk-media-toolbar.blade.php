@props(['playlists' => collect(), 'currentPlaylist' => null])

<div class="sticky top-16 z-10 hidden flex-wrap items-center justify-between gap-3 border-y border-white/10 bg-zinc-900/95 px-3 py-3 backdrop-blur" data-bulk-toolbar>
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-medium"><span data-selected-count>0</span> selected</span>
        <button type="button" class="text-sm text-zinc-400 hover:text-zinc-100" data-select-all>Select all visible</button>
        <button type="button" class="text-sm text-zinc-400 hover:text-zinc-100" data-clear-selection>Clear</button>
    </div>
    <div class="flex flex-wrap items-center justify-end gap-2">
        @if($currentPlaylist)
            <input type="hidden" name="playlist_id" value="{{ $currentPlaylist->id }}">
            <button name="action" value="remove_from_playlist" class="secondary">Remove from playlist</button>
        @elseif($playlists->isNotEmpty())
            <select name="playlist_id" class="field w-auto min-w-40 py-2" aria-label="Choose playlist">
                <option value="">Choose playlist</option>
                @foreach($playlists as $playlist)
                    <option value="{{ $playlist->id }}">{{ $playlist->name }}</option>
                @endforeach
            </select>
            <button name="action" value="add_to_playlist" class="secondary">Add to playlist</button>
        @endif
        <button name="action" value="download" class="primary">Download / retry</button>
        <button name="action" value="delete" class="danger" data-confirm="ARE YOU SURE? This permanently deletes every selected video file, thumbnail, metadata, and removes it from every playlist.">Delete</button>
    </div>
</div>
