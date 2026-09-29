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

        @if($availabilityAudit)
            <section class="border-y border-white/10 py-4" data-availability-audit data-status-url="{{ route('library.availability-status') }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">YouTube availability audit</h2>
                        <p class="mt-1 text-xs text-zinc-500" data-audit-state>{{ ucfirst($availabilityAudit['status']) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs text-zinc-400">
                        <span><strong class="text-zinc-100" data-audit-progress>{{ $availabilityAudit['processed'] }} / {{ $availabilityAudit['total'] }}</strong> checked</span>
                        <span><strong class="text-emerald-300" data-audit-available>{{ $availabilityAudit['available'] }}</strong> available</span>
                        <span><strong class="text-amber-300" data-audit-unavailable>{{ $availabilityAudit['unavailable'] }}</strong> unavailable</span>
                        <span><strong class="text-zinc-300" data-audit-unknown>{{ $availabilityAudit['unknown'] }}</strong> inconclusive</span>
                    </div>
                </div>
                <div class="mt-3 h-1 overflow-hidden rounded bg-zinc-800"><div class="h-full bg-cyan-400 transition-[width]" data-audit-bar style="width: {{ $availabilityAudit['total'] > 0 ? min(100, ($availabilityAudit['processed'] / $availabilityAudit['total']) * 100) : 0 }}%"></div></div>
            </section>
        @endif

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
