<x-layouts.app title="Subscriptions">
    <div class="mb-7">
        <p class="text-sm text-zinc-500">Channels, playlists, and videos</p>
        <h1 class="text-3xl font-bold">Subscriptions</h1>
    </div>

    <div class="grid gap-6 xl:grid-cols-[22rem_1fr]">
        <form method="POST" action="{{ route('sources.store') }}" class="grid h-fit gap-4 rounded-2xl border border-white/10 bg-zinc-900 p-5">
            @csrf
            <h2 class="font-semibold">Add source</h2>
            <select name="type" class="field">
                <option value="channel">Channel</option>
                <option value="playlist">Playlist</option>
                <option value="video">Individual video</option>
            </select>
            <input name="name" required class="field" placeholder="Display name">
            <input name="url" type="url" required class="field" placeholder="https://youtube.com/...">
            <label class="text-sm text-zinc-400">
                Check interval (minutes)
                <input name="scan_interval_minutes" type="number" value="360" min="15" class="field mt-1">
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="auto_download" value="1" checked>
                Automatically download new videos
            </label>
            <button class="primary">Add and scan</button>
        </form>

        <div class="grid content-start gap-3">
            @forelse($sources as $source)
                <article class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-white/8 bg-zinc-900 p-5">
                    <div>
                        <div class="flex gap-2">
                            <h2 class="font-semibold"><a href="{{ route('sources.show', $source) }}" class="hover:underline">{{ $source->name }}</a></h2>
                            <span class="badge">{{ $source->enabled ? 'Enabled' : 'Paused' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-500">
                            {{ ucfirst($source->type) }} &middot; {{ $source->playlist_media_count }} items &middot; {{ $source->last_scanned_at?->diffForHumans() ?? 'Never scanned' }} &middot; every {{ $source->scan_interval_minutes < 60 ? $source->scan_interval_minutes.' minutes' : ($source->scan_interval_minutes % 1440 === 0 ? ($source->scan_interval_minutes / 1440).' days' : ($source->scan_interval_minutes / 60).' hours') }}
                        </p>
                        @if($source->last_scan_error)
                            <p class="mt-2 text-xs text-red-300">{{ $source->last_scan_error }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('sources.schedule', $source) }}" class="flex items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <label class="sr-only" for="scan-interval-{{ $source->id }}">Scan interval</label>
                            <select id="scan-interval-{{ $source->id }}" name="scan_interval_minutes" class="field w-auto py-2 text-sm">
                                @php($scanIntervals = [15 => '15 min', 30 => '30 min', 60 => '1 hour', 180 => '3 hours', 360 => '6 hours', 720 => '12 hours', 1440 => '24 hours', 2880 => '2 days', 10080 => '7 days'])
                                @if(! array_key_exists($source->scan_interval_minutes, $scanIntervals))
                                    <option value="{{ $source->scan_interval_minutes }}" selected>{{ $source->scan_interval_minutes }} min</option>
                                @endif
                                @foreach($scanIntervals as $minutes => $label)
                                    <option value="{{ $minutes }}" @selected($source->scan_interval_minutes === $minutes)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button class="secondary">Save interval</button>
                        </form>
                        <a href="{{ route('sources.show', $source) }}" class="secondary">View</a>
                        <form method="POST" action="{{ route('sources.scan', $source) }}">
                            @csrf
                            <button class="secondary">Scan</button>
                        </form>
                        <form method="POST" action="{{ route('sources.toggle', $source) }}">
                            @csrf
                            @method('PATCH')
                            <button class="secondary">{{ $source->enabled ? 'Pause' : 'Enable' }}</button>
                        </form>
                        <form method="POST" action="{{ route('sources.destroy', $source) }}">
                            @csrf
                            @method('DELETE')
                            <button class="danger">Remove</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-white/10 p-12 text-center text-zinc-500">No sources yet.</div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
