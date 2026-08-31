<x-layouts.app :title="$medium->title">
    <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0">
            @if($playlistName)
                <a href="{{ $playlistUrl }}" class="mb-3 inline-block text-sm text-zinc-500 hover:text-zinc-300">{{ $playlistName }}</a>
            @endif
            <div class="aspect-video overflow-hidden rounded-2xl bg-black">
                @if($medium->files->isNotEmpty())
                    <video class="media-player size-full" controls @if($playlistName) autoplay @endif preload="metadata"
                        data-progress-url="{{ route('media.progress', $medium) }}"
                        data-resume="{{ $medium->watchHistory->first()?->position_seconds ?? 0 }}"
                        @if($nextUrl) data-next-url="{{ $nextUrl }}" @endif>
                        <source src="{{ route('media.stream', $medium) }}" type="{{ $medium->files->first()->mime_type ?: 'video/mp4' }}">
                    </video>
                @elseif($medium->thumbnail_url)
                    <img src="{{ $medium->thumbnail_url }}" alt="" class="size-full object-contain">
                @endif
            </div>
            <div class="mt-5 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold">{{ $medium->title }}</h1>
                    @if($medium->isUnavailableOnYoutube() && $medium->files->isNotEmpty())
                        <p class="mt-2 inline-flex rounded border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-xs font-semibold text-amber-200">Removed from YouTube &middot; archived copy preserved</p>
                    @endif
                    <p class="mt-2 text-sm text-zinc-400">
                        @if($medium->channel_name)
                            <a href="{{ route('channels.show', $medium->archiveChannelKey()) }}" class="hover:text-white hover:underline">Archive Channel</a>
                            &middot;
                        @endif
                        <a href="{{ $medium->youtubeVideoUrl() }}" target="_blank" rel="noopener noreferrer" class="hover:text-white hover:underline">Original Video</a>
                        @if($medium->youtubeChannelUrl())
                            &middot; <a href="{{ $medium->youtubeChannelUrl() }}" target="_blank" rel="noopener noreferrer" class="hover:text-white hover:underline">Original Channel</a>
                        @endif
                        @if($medium->published_at) &middot; {{ $medium->published_at->toFormattedDateString() }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($playlists->isNotEmpty())
                        <form method="POST" action="{{ route('media.bulk-manage') }}" class="flex">
                            @csrf
                            <input type="hidden" name="action" value="add_to_playlist">
                            <input type="hidden" name="media_ids[]" value="{{ $medium->id }}">
                            <select name="playlist_id" required class="field rounded-r-none py-2" aria-label="Choose playlist">
                                <option value="">Playlist</option>
                                @foreach($playlists as $savedPlaylist)
                                    <option value="{{ $savedPlaylist->id }}">{{ $savedPlaylist->name }}</option>
                                @endforeach
                            </select>
                            <button class="secondary grid size-10 place-items-center rounded-l-none px-0 text-lg" title="Add to playlist" aria-label="Add to playlist">+</button>
                        </form>
                    @else
                        <a href="{{ route('playlists.index') }}" class="secondary grid size-10 place-items-center px-0 text-lg" title="Create a playlist" aria-label="Create a playlist">+</a>
                    @endif
                    <a href="{{ route('media.edit', $medium) }}" class="secondary">Edit metadata</a>
                    @if($next)
                        <a href="{{ $nextUrl }}" class="secondary">Next video</a>
                    @endif
                    @if($medium->status->value !== 'downloaded')
                        <form method="POST" action="{{ route('media.queue', $medium) }}">@csrf<button class="primary">Download</button></form>
                    @endif
                    <form method="POST" action="{{ route('media.destroy', $medium) }}" onsubmit="return confirm('ARE YOU SURE? This permanently deletes the video file, thumbnail, metadata, and removes it from every playlist.');">
                        @csrf
                        @method('DELETE')
                        <button class="danger">Delete</button>
                    </form>
                </div>
            </div>
            @if($medium->description)
                <div class="mt-6 whitespace-pre-line rounded-2xl bg-zinc-900 p-5 text-sm leading-6 text-zinc-300">{{ $medium->description }}</div>
            @endif
            @if($medium->attempts->isNotEmpty())
                <section class="mt-8">
                    <h2 class="mb-3 text-lg font-semibold">Download diagnostics</h2>
                    @foreach($medium->attempts as $attempt)
                        <details class="mb-2 rounded-xl border border-white/8 bg-zinc-900 p-4">
                            <summary class="cursor-pointer text-sm">Attempt {{ $attempt->attempt_number }} &middot; {{ ucfirst($attempt->status) }} @if($attempt->error_category) &middot; <span class="text-red-300">{{ $attempt->error_category }}</span>@endif</summary>
                            @if($attempt->stderr)<pre class="mt-4 max-h-64 overflow-auto whitespace-pre-wrap text-xs text-zinc-500">{{ $attempt->stderr }}</pre>@endif
                        </details>
                    @endforeach
                </section>
            @endif
        </div>
        <aside>
            <h2 class="mb-4 font-semibold">{{ $playlistName ? 'Up next in '.$playlistName : 'More from this channel' }}</h2>
            <div class="grid gap-6">
                @if($playlistName && $next)
                    <x-video-card :medium="$next" :href="$nextUrl" />
                @else
                    @foreach($related as $item)<x-video-card :medium="$item" />@endforeach
                @endif
            </div>
        </aside>
    </div>
</x-layouts.app>
