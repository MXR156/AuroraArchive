@props(['clearUrl', 'playlistOrder' => false])
<form method="GET" class="grid gap-2 sm:grid-cols-[minmax(12rem,1fr)_12rem_12rem_auto]" data-media-filters>
    <input name="q" value="{{ request('q') }}" placeholder="Search videos or creators" class="field">
    <select name="filter" class="field" aria-label="Media filter">
        @foreach([
            '' => 'All media',
            'downloaded' => 'Downloaded files',
            'youtube_unavailable' => 'Removed from YouTube (archived)',
            'not_downloaded' => 'Not downloaded',
            'skipped' => 'Skipped',
            'failed' => 'Failed',
            'active_downloads' => 'Queued or downloading',
            'queued' => 'Queued',
            'downloading' => 'Downloading',
            'discovered' => 'Discovered',
            'watched' => 'Watched',
            'unwatched' => 'Unwatched',
        ] as $value => $label)
            <option value="{{ $value }}" @selected(request('filter', '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="sort" class="field" aria-label="Media sort order">
        @if($playlistOrder)<option value="playlist" @selected(request('sort', 'playlist') === 'playlist')>Playlist order</option>@endif
        @if($playlistOrder)<option value="playlist_reverse" @selected(request('sort') === 'playlist_reverse')>Reverse playlist order</option>@endif
        <option value="newest" @selected(request('sort', $playlistOrder ? 'playlist' : 'newest') === 'newest')>Newest published</option>
        <option value="oldest" @selected(request('sort') === 'oldest')>Oldest published</option>
        <option value="recently_downloaded" @selected(request('sort') === 'recently_downloaded')>Recently downloaded</option>
        <option value="recently_added" @selected(request('sort') === 'recently_added')>Recently added</option>
        <option value="title" @selected(request('sort') === 'title')>Title A-Z</option>
        <option value="duration_longest" @selected(request('sort') === 'duration_longest')>Longest duration</option>
        <option value="duration_shortest" @selected(request('sort') === 'duration_shortest')>Shortest duration</option>
    </select>
    <div class="flex gap-2">
        <button class="primary">Apply</button>
        @if(request()->hasAny(['q', 'filter', 'sort']))<a href="{{ $clearUrl }}" class="secondary">Clear</a>@endif
    </div>
</form>
