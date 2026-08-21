<x-layouts.app :title="$playlist->name">
    <div class="grid gap-7">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <a href="{{ route('playlists.index') }}" class="text-sm text-zinc-500 hover:text-zinc-300">Playlists</a>
                <h1 class="mt-1 text-3xl font-bold">{{ $playlist->name }}</h1>
                <p class="mt-2 text-sm text-zinc-500">{{ $media->total() }} {{ Str::plural('video', $media->total()) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($media->isNotEmpty())
                    <a href="{{ route('media.show', ['medium' => $media->first(), 'local_playlist' => $playlist]) }}" class="primary">Play all</a>
                @endif
                <form method="POST" action="{{ route('playlists.destroy', $playlist) }}" onsubmit="return confirm('ARE YOU SURE? This deletes the playlist but preserves every archived video.');">
                    @csrf
                    @method('DELETE')
                    <button class="danger">Delete playlist</button>
                </form>
            </div>
        </header>

        <form method="POST" action="{{ route('playlists.update', $playlist) }}" class="flex max-w-xl gap-2">
            @csrf
            @method('PUT')
            <input name="name" value="{{ old('name', $playlist->name) }}" required maxlength="255" class="field" aria-label="Playlist name">
            <button class="secondary whitespace-nowrap">Rename</button>
        </form>

        <x-media-filters :clear-url="route('playlists.show', $playlist)" :playlist-order="true" />

        @if($media->isEmpty())
            <div class="border-t border-white/10 py-16 text-center text-zinc-500">No media has been added to this playlist yet.</div>
        @else
            <form method="POST" action="{{ route('media.bulk-manage') }}" class="grid gap-5" data-bulk-media-form>
                @csrf
                <x-bulk-media-toolbar :current-playlist="$playlist" />
                <div class="video-grid">
                    @foreach($media as $medium)
                        <x-video-card :medium="$medium" :href="route('media.show', ['medium' => $medium, 'local_playlist' => $playlist])" :selectable="true" />
                    @endforeach
                </div>
            </form>
            <x-pagination :paginator="$media" />
        @endif
    </div>
</x-layouts.app>
