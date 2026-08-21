<x-layouts.app title="Channels">
    <div class="grid gap-7">
        <header>
            <p class="text-sm text-zinc-500">{{ $channels->total() }} creators in your archive</p>
            <h1 class="text-3xl font-bold">Channels</h1>
        </header>

        @if($channels->isEmpty())
            <div class="border-t border-white/10 py-16 text-center text-zinc-500">No downloaded channels are available.</div>
        @else
            <div class="grid grid-cols-1 gap-x-5 gap-y-8 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach($channels as $channel)
                    <article class="group min-w-0">
                        <a href="{{ route('channels.show', $channel->archiveChannelKey()) }}" class="block overflow-hidden rounded-lg bg-zinc-900">
                            <div class="aspect-video bg-zinc-900">
                                <img src="{{ route('media.thumbnail', $channel->representative_media_id) }}" alt="" class="size-full object-cover transition group-hover:scale-[1.03]" loading="lazy">
                            </div>
                        </a>
                        <div class="pt-3">
                            <a href="{{ route('channels.show', $channel->archiveChannelKey()) }}" class="font-semibold hover:underline">{{ $channel->channel_name }}</a>
                            <p class="mt-1 text-sm text-zinc-500">{{ number_format($channel->media_count) }} {{ Str::plural('video', $channel->media_count) }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
            <div>{{ $channels->links() }}</div>
        @endif
    </div>
</x-layouts.app>
