<x-layouts.app :title="$source->name">
    <div class="grid gap-7">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <a href="{{ route('sources.index') }}" class="text-sm text-zinc-500 hover:text-zinc-300">Subscriptions</a>
                <h1 class="mt-1 text-3xl font-bold">{{ $source->name }}</h1>
                <p class="mt-2 text-sm text-zinc-500">{{ $media->total() }} videos &middot; {{ ucfirst($source->type) }}</p>
            </div>
            @if($media->isNotEmpty())
                <a href="{{ route('media.show', ['medium' => $media->first(), 'playlist' => $source]) }}" class="primary">Play all</a>
            @endif
        </header>

        <x-media-filters :clear-url="route('sources.show', $source)" :playlist-order="true" />

        @if($media->isEmpty())
            <div class="border-t border-white/10 py-16 text-center text-zinc-500">No media has been associated with this playlist yet.</div>
        @else
            <form method="POST" action="{{ route('media.bulk-manage') }}" class="grid gap-5" data-bulk-media-form>
                @csrf
                <x-bulk-media-toolbar />
                <div class="video-grid">
                    @foreach($media as $medium)
                        <x-video-card :medium="$medium" :href="route('media.show', ['medium' => $medium, 'playlist' => $source])" :selectable="true" />
                    @endforeach
                </div>
            </form>
            <x-pagination :paginator="$media" />
        @endif
    </div>
</x-layouts.app>
