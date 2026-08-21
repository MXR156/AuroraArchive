@props(['medium', 'href' => null, 'selectable' => false])
<article class="group relative min-w-0">
    @if($selectable && $medium->status->value === 'failed')
        <label class="absolute top-2 right-2 z-10 grid size-8 cursor-pointer place-items-center rounded bg-black/85" title="Select for retry">
            <input type="checkbox" name="media_ids[]" value="{{ $medium->id }}" class="size-4 accent-violet-500" aria-label="Select {{ $medium->title }} for retry">
        </label>
    @endif
    <a href="{{ $href ?: route('media.show', $medium) }}" class="relative block aspect-video overflow-hidden rounded-xl bg-zinc-900">
        <img src="{{ route('media.thumbnail', $medium) }}" alt="" class="size-full object-cover transition group-hover:scale-[1.03]" loading="lazy">
        @if($medium->duration_seconds)
            <span class="absolute right-2 bottom-2 rounded bg-black/85 px-1.5 py-0.5 text-xs">{{ sprintf('%d:%02d', intdiv($medium->duration_seconds, 60), $medium->duration_seconds % 60) }}</span>
        @endif
        <span class="absolute top-2 left-2 rounded bg-black/80 px-2 py-1 text-[10px] font-semibold uppercase">{{ $medium->status->value }}</span>
    </a>
    <div class="pt-3">
        <a href="{{ $href ?: route('media.show', $medium) }}" class="line-clamp-2 text-sm font-semibold leading-5">{{ $medium->title }}</a>
        <p class="mt-1 truncate text-xs text-zinc-500">
            @if($medium->channel_name && $medium->status->value === 'downloaded')
                <a href="{{ route('channels.show', $medium->archiveChannelKey()) }}" class="hover:text-zinc-300 hover:underline">{{ $medium->channel_name }}</a>
            @else
                {{ $medium->channel_name ?: 'Unknown channel' }}
            @endif
            @if($medium->published_at) &middot; {{ $medium->published_at->diffForHumans() }} @endif
        </p>
    </div>
</article>
