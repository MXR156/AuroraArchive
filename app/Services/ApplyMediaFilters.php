<?php

namespace App\Services;

use App\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

class ApplyMediaFilters
{
    public function handle(Builder|BelongsToMany $query, Request $request, bool $playlistOrder = false): Builder|BelongsToMany
    {
        $search = $request->string('q')->trim()->toString();
        if ($search !== '') {
            $query->where(fn ($nested) => $nested
                ->where('title', 'like', '%'.$search.'%')
                ->orWhere('channel_name', 'like', '%'.$search.'%'));
        }

        $filter = $request->string('filter')->toString() ?: $request->string('status')->toString();
        match ($filter) {
            'downloaded' => $query->whereHas('files'),
            'youtube_unavailable' => $query
                ->whereHas('files')
                ->where(fn ($availability) => $availability
                    ->where(fn ($checked) => $checked
                        ->where('metadata->youtube->unavailable', true)
                        ->where(fn ($status) => $status
                            ->whereNull('metadata->youtube->availability_check_status')
                            ->orWhere('metadata->youtube->availability_check_status', '!=', 'available')))
                    ->orWhere(fn ($legacy) => $legacy
                        ->where(fn ($check) => $check
                            ->whereNull('metadata->youtube->availability_check_status')
                            ->orWhere('metadata->youtube->availability_check_status', 'unknown'))
                        ->where(fn ($state) => $state
                            ->whereIn('metadata->availability', ['private', 'unavailable', 'needs_auth', 'subscriber_only', 'premium_only'])
                            ->orWhere('metadata', 'like', '%"availability"%"private"%')
                            ->orWhere('metadata', 'like', '%"availability"%"unavailable"%')
                            ->orWhere('metadata', 'like', '%"availability"%"needs_auth"%')
                            ->orWhere('metadata', 'like', '%"availability"%"subscriber_only"%')
                            ->orWhere('metadata', 'like', '%"availability"%"premium_only"%')))),
            'not_downloaded' => $query->whereDoesntHave('files'),
            'watched' => $query->whereHas('watchHistory', fn ($history) => $history->whereBelongsTo($request->user())->where('watched', true)),
            'unwatched' => $query->whereDoesntHave('watchHistory', fn ($history) => $history->whereBelongsTo($request->user())->where('watched', true)),
            'discovered' => $query->where('status', MediaStatus::Discovered),
            'active_downloads' => $query->whereIn('status', [MediaStatus::Queued, MediaStatus::Downloading]),
            'queued' => $query->where('status', MediaStatus::Queued),
            'downloading' => $query->where('status', MediaStatus::Downloading),
            'failed' => $query->where('status', MediaStatus::Failed),
            'skipped' => $query->where('status', MediaStatus::Skipped),
            default => $query,
        };

        $sort = $request->string('sort')->toString();
        if ($playlistOrder && ($sort === '' || $sort === 'playlist')) {
            return $query->orderByPivot('position')->orderBy('media.id');
        }
        if ($playlistOrder && $sort === 'playlist_reverse') {
            return $query->orderByPivot('position', 'desc')->orderByDesc('media.id');
        }

        return match ($sort) {
            'oldest' => $query->orderBy('media.published_at')->orderBy('media.id'),
            'title' => $query->orderBy('media.title')->orderBy('media.id'),
            'duration_longest' => $query->orderByDesc('media.duration_seconds')->orderBy('media.id'),
            'duration_shortest' => $query->orderBy('media.duration_seconds')->orderBy('media.id'),
            'recently_added' => $query->latest('media.created_at'),
            'recently_downloaded' => $query->withMax('files', 'created_at')->orderByDesc('files_max_created_at')->orderByDesc('media.id'),
            default => $query->latest('media.published_at')->latest('media.id'),
        };
    }
}
