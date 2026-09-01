<?php

namespace App\Http\Controllers;

use App\Jobs\QueueMediaAvailabilityChecks;
use App\Models\Media;
use App\Services\ApplyMediaFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    public function __invoke(Request $request, ApplyMediaFilters $filters): View
    {
        $media = $filters->handle(Media::query(), $request)->paginate(24)->withQueryString();
        $playlists = $request->user()->playlists()->orderBy('name')->get();

        return view('library', compact('media', 'playlists'));
    }

    public function checkAvailability(): RedirectResponse
    {
        QueueMediaAvailabilityChecks::dispatch();

        return back()->with('success', 'YouTube availability audit queued. Results will appear progressively as videos are checked.');
    }
}
