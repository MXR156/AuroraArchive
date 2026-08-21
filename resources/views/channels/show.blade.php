<x-layouts.app :title="$representative->channel_name">
    <div class="grid gap-7">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <a href="{{ route('channels.index') }}" class="text-sm text-zinc-500 hover:text-zinc-300">Channels</a>
                <h1 class="mt-1 text-3xl font-bold">{{ $representative->channel_name }}</h1>
                <p class="mt-2 text-sm text-zinc-500">{{ $media->total() }} {{ Str::plural('video', $media->total()) }}</p>
            </div>
            @if($representative->youtubeChannelUrl())
                <a href="{{ $representative->youtubeChannelUrl() }}" target="_blank" rel="noopener noreferrer" class="secondary">Open on YouTube</a>
            @endif
        </header>

        <x-media-filters :clear-url="route('channels.show', $representative->archiveChannelKey())" />

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
        @endif
        <x-pagination :paginator="$media" />
    </div>
</x-layouts.app>
