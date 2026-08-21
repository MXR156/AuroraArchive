@props(['paginator'])

@if($paginator->hasPages())
    @php
        $firstPage = max(1, $paginator->currentPage() - 2);
        $lastPage = min($paginator->lastPage(), $paginator->currentPage() + 2);
        if ($lastPage - $firstPage < 4) {
            $firstPage = max(1, $lastPage - 4);
            $lastPage = min($paginator->lastPage(), $firstPage + 4);
        }
    @endphp
    <nav class="flex flex-wrap items-center justify-center gap-1" aria-label="Pagination">
        @if($paginator->onFirstPage())
            <span class="grid size-9 place-items-center rounded-lg text-zinc-700" aria-label="First page" aria-disabled="true" title="First page">&laquo;</span>
            <span class="grid size-9 place-items-center rounded-lg text-zinc-700" aria-label="Previous page" aria-disabled="true" title="Previous page">&lsaquo;</span>
        @else
            <a href="{{ $paginator->url(1) }}" class="grid size-9 place-items-center rounded-lg text-zinc-400 hover:bg-white/5 hover:text-white" aria-label="First page" title="First page">&laquo;</a>
            <a href="{{ $paginator->previousPageUrl() }}" class="grid size-9 place-items-center rounded-lg text-zinc-400 hover:bg-white/5 hover:text-white" aria-label="Previous page" title="Previous page">&lsaquo;</a>
        @endif

        @foreach(range($firstPage, $lastPage) as $page)
            @if($page === $paginator->currentPage())
                <span class="grid size-9 place-items-center rounded-lg bg-white/10 text-sm font-semibold text-white" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="grid size-9 place-items-center rounded-lg text-sm text-zinc-400 hover:bg-white/5 hover:text-white" aria-label="Page {{ $page }}">{{ $page }}</a>
            @endif
        @endforeach

        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="grid size-9 place-items-center rounded-lg text-zinc-400 hover:bg-white/5 hover:text-white" aria-label="Next page" title="Next page">&rsaquo;</a>
            <a href="{{ $paginator->url($paginator->lastPage()) }}" class="grid size-9 place-items-center rounded-lg text-zinc-400 hover:bg-white/5 hover:text-white" aria-label="Last page" title="Last page">&raquo;</a>
        @else
            <span class="grid size-9 place-items-center rounded-lg text-zinc-700" aria-label="Next page" aria-disabled="true" title="Next page">&rsaquo;</span>
            <span class="grid size-9 place-items-center rounded-lg text-zinc-700" aria-label="Last page" aria-disabled="true" title="Last page">&raquo;</span>
        @endif
    </nav>
@endif
