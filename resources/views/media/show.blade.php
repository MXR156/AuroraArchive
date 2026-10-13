<x-layouts.app :title="$medium->title">
    <div class="grid gap-8 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0">
            @if($playlistName)
                <a href="{{ $playlistUrl }}" class="mb-3 inline-block text-sm text-zinc-500 hover:text-zinc-300">{{ $playlistName }}</a>
            @endif
            <div class="aspect-video overflow-hidden rounded-2xl bg-black">
                @if($medium->files->isNotEmpty())
                    <video class="media-player size-full" controls @if($playlistName) autoplay @endif preload="metadata" poster="{{ $medium->thumbnailRoute() }}"
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
                    <form method="POST" action="{{ route('media.thumbnail.refresh', $medium) }}">
                        @csrf
                        <button class="secondary">Refresh from YouTube</button>
                    </form>
                    @if($filmotConfigured)
                        <form method="POST" action="{{ route('media.filmot.lookup', $medium) }}">
                            @csrf
                            <button class="secondary">Check Filmot</button>
                        </form>
                    @endif
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
            @if(data_get($medium->metadata, 'youtube.metadata_refreshed_at'))
                <p class="mt-2 text-xs text-zinc-500">
                    YouTube metadata refresh:
                    {{ data_get($medium->metadata, 'youtube.metadata_refresh_status') === 'updated' ? 'updated' : 'no metadata returned' }}
                    &middot; {{ \Illuminate\Support\Carbon::parse(data_get($medium->metadata, 'youtube.metadata_refreshed_at'))->diffForHumans() }}
                </p>
            @endif
            <details class="mt-8 border-t border-white/10 pt-5">
                <summary class="cursor-pointer text-sm font-semibold text-zinc-200">Paste archived metadata JSON</summary>
                <form method="POST" action="{{ route('media.metadata.preview', $medium) }}" class="mt-4 grid gap-3">
                    @csrf
                    <textarea name="metadata_json" rows="10" required class="field resize-y font-mono text-xs" placeholder='{"id":"{{ $medium->youtube_id }}","title":"Archived title","description":"Line one\n\nLine two","channelname":"Channel name"}'>{{ old('metadata_json') }}</textarea>
                    <button class="secondary w-fit">Convert and preview</button>
                </form>
            </details>
            @if(is_array($filmotPreview))
                @php
                    $filmotFields = [
                        'title' => 'Title',
                        'description' => 'Description',
                        'channel_name' => 'Channel name',
                        'channel_id' => 'Channel ID',
                        'published_at' => 'Published date',
                        'duration_seconds' => 'Duration',
                        'thumbnail_url' => 'Thumbnail URL',
                    ];
                @endphp
                <section class="mt-8 border-t border-white/10 pt-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="font-semibold">Recovered metadata preview</h2>
                            <p class="mt-1 text-sm text-zinc-500">Select the fields to replace. Unselected archive metadata remains unchanged.</p>
                        </div>
                        <span class="badge">{{ $filmotPreview['youtube_id'] ?? '' }}</span>
                    </div>

                    <form method="POST" action="{{ route('media.filmot.apply', $medium) }}" class="mt-4 grid gap-3">
                        @csrf
                        @foreach($filmotFields as $field => $label)
                            @if(filled($filmotPreview[$field] ?? null))
                                <label class="grid cursor-pointer grid-cols-[1.25rem_minmax(0,1fr)] gap-3 border-b border-white/8 py-3 last:border-b-0 sm:grid-cols-[1.25rem_9rem_minmax(0,1fr)]">
                                    <input type="checkbox" name="fields[]" value="{{ $field }}" checked class="mt-1 size-4 accent-cyan-500">
                                    <span class="text-sm font-medium text-zinc-300">{{ $label }}</span>
                                    <span class="min-w-0 break-words text-sm text-zinc-400">
                                        {{ $field === 'description' ? \Illuminate\Support\Str::limit($filmotPreview[$field], 500) : $filmotPreview[$field] }}
                                    </span>
                                </label>
                            @endif
                        @endforeach
                        <button class="primary mt-2 w-fit">Import selected fields</button>
                    </form>
                </section>
            @endif
            @if($medium->description)
                <div class="mt-6 whitespace-pre-line rounded-2xl bg-zinc-900 p-5 text-sm leading-6 text-zinc-300">{{ $medium->description }}</div>
            @endif
            @php($availabilityOverride = data_get($medium->metadata, 'youtube.availability_override'))
            <section class="mt-8 border-t border-white/10 pt-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold">YouTube availability</h2>
                    <form method="POST" action="{{ route('media.availability.update', $medium) }}" class="inline-flex overflow-hidden rounded border border-white/10">
                        @csrf
                        @method('PATCH')
                        @foreach(['' => 'Automatic', 'available' => 'Available', 'unavailable' => 'Removed'] as $value => $label)
                            @php($selected = ($availabilityOverride ?? '') === $value)
                            <button name="availability_override" value="{{ $value }}" class="px-3 py-2 text-xs font-semibold {{ $selected ? 'bg-zinc-600 text-white' : 'bg-zinc-900 text-zinc-400 hover:bg-zinc-800 hover:text-zinc-200' }}" aria-pressed="{{ $selected ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </form>
                </div>
                @if(data_get($medium->metadata, 'youtube.availability_checked_at'))
                    <h3 class="mt-4 text-xs font-semibold uppercase text-zinc-500">Latest automatic check</h3>
                    <p class="mt-2 text-sm text-zinc-400">
                        {{ ucfirst(data_get($medium->metadata, 'youtube.availability_check_status', 'unknown')) }}
                        &middot; {{ \Illuminate\Support\Carbon::parse(data_get($medium->metadata, 'youtube.availability_checked_at'))->diffForHumans() }}
                    </p>
                    @if(data_get($medium->metadata, 'youtube.availability_check_reason'))
                        <p class="mt-2 text-xs text-zinc-500">{{ data_get($medium->metadata, 'youtube.availability_check_reason') }}</p>
                    @endif
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach(data_get($medium->metadata, 'youtube.availability_check_evidence', []) as $probe => $evidence)
                            <div class="border border-white/8 bg-zinc-900 p-3 text-xs">
                                <strong class="text-zinc-200">{{ str($probe)->replace('_', ' ')->headline() }}</strong>
                                <span class="ml-2 text-zinc-400">{{ ucfirst(data_get($evidence, 'status', 'unknown')) }}</span>
                                @if(data_get($evidence, 'reason'))<p class="mt-1 text-zinc-500">{{ data_get($evidence, 'reason') }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
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
