<x-layouts.app title="Playlists">
    <div class="grid gap-7">
        <header>
            <p class="text-sm text-zinc-500">Your library</p>
            <h1 class="text-3xl font-bold">Playlists</h1>
        </header>

        <form method="POST" action="{{ route('playlists.store') }}" class="flex max-w-xl gap-2">
            @csrf
            <input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Playlist name" class="field">
            <button class="primary whitespace-nowrap">Create playlist</button>
        </form>

        <div class="divide-y divide-white/8 border-y border-white/10">
            @forelse($playlists as $playlist)
                <a href="{{ route('playlists.show', $playlist) }}" class="flex items-center justify-between gap-4 px-2 py-4 hover:bg-white/[0.03]">
                    <span class="font-medium">{{ $playlist->name }}</span>
                    <span class="text-sm text-zinc-500">{{ $playlist->media_count }} {{ Str::plural('video', $playlist->media_count) }}</span>
                </a>
            @empty
                <p class="py-12 text-center text-zinc-500">No playlists yet.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
