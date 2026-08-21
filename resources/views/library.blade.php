<x-layouts.app title="Library">
    <div class="grid gap-7">
        <header>
            <p class="text-sm text-zinc-500">{{ $media->total() }} items</p>
            <h1 class="text-3xl font-bold">Library</h1>
        </header>

        <x-media-filters :clear-url="route('library')" />

        @if($media->isEmpty())
            <div class="border-t border-white/10 py-16 text-center text-zinc-500">No media matches these filters.</div>
        @else
            <form method="POST" action="{{ route('media.bulk-manage') }}" class="grid gap-5" data-bulk-media-form>
                @csrf
                <x-bulk-media-toolbar />
                <div class="video-grid">
                    @foreach($media as $medium)<x-video-card :medium="$medium" :selectable="true" />@endforeach
                </div>
            </form>
            <div>{{ $media->links() }}</div>
        @endif
    </div>
</x-layouts.app>
