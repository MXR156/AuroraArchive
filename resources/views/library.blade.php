<x-layouts.app title="Library">
    <div class="grid gap-7">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-zinc-500">{{ $media->total() }} items</p>
                <h1 class="text-3xl font-bold">Library</h1>
            </div>
            <form method="POST" action="{{ route('library.check-availability') }}">
                @csrf
                <button class="secondary">Check YouTube availability</button>
            </form>
        </header>

        <x-media-filters :clear-url="route('library')" />

        @if($media->isEmpty())
            <div class="border-t border-white/10 py-16 text-center text-zinc-500">No media matches these filters.</div>
        @else
            <form method="POST" action="{{ route('media.bulk-manage') }}" class="grid gap-5" data-bulk-media-form>
                @csrf
                <x-bulk-media-toolbar :playlists="$playlists" />
                <div class="video-grid">
                    @foreach($media as $medium)<x-video-card :medium="$medium" :selectable="true" />@endforeach
                </div>
            </form>
            <x-pagination :paginator="$media" />
        @endif
    </div>
</x-layouts.app>
