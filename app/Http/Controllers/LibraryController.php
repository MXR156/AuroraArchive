<?php

namespace App\Http\Controllers;

use App\Jobs\QueueMediaAvailabilityChecks;
use App\Models\Media;
use App\Services\ApplyMediaFilters;
use App\Services\AvailabilityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    public function __invoke(Request $request, ApplyMediaFilters $filters, AvailabilityAudit $audit): View
    {
        $media = $filters->handle(Media::query(), $request)->paginate(24)->withQueryString();
        $playlists = $request->user()->playlists()->orderBy('name')->get();
        $availabilityAudit = $audit->latest($request->user()->id);

        return view('library', compact('media', 'playlists', 'availabilityAudit'));
    }

    public function checkAvailability(Request $request, AvailabilityAudit $audit): RedirectResponse
    {
        $currentAudit = $audit->latest($request->user()->id);
        if (in_array($currentAudit['status'] ?? null, ['queued', 'running'], true)) {
            return back()->with('success', 'A YouTube availability audit is already in progress.');
        }

        $auditId = $audit->queue($request->user()->id);
        QueueMediaAvailabilityChecks::dispatch($auditId);

        return back()->with('success', 'YouTube availability and thumbnail audit queued. Results will appear progressively as videos are checked.');
    }

    public function availabilityStatus(Request $request, AvailabilityAudit $audit): JsonResponse
    {
        return response()->json($audit->latest($request->user()->id));
    }
}
