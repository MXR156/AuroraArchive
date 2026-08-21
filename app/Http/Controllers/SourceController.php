<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceRequest;
use App\Jobs\ScanSource;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(Request $request): View
    {
        return view('sources.index', ['sources' => $request->user()->sources()->withCount('media')->latest()->get()]);
    }

    public function store(StoreSourceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['external_id'] = $request->youtubeId();
        $data['auto_download'] = $request->boolean('auto_download');
        $source = $request->user()->sources()->create($data);
        ScanSource::dispatch($source);

        return back()->with('success', 'Source added and its first scan was queued.');
    }

    public function scan(Request $request, Source $source): RedirectResponse
    {
        $this->owned($request, $source);
        ScanSource::dispatch($source);

        return back()->with('success', 'Scan queued.');
    }

    public function toggle(Request $request, Source $source): RedirectResponse
    {
        $this->owned($request, $source);
        $source->update(['enabled' => ! $source->enabled]);

        return back()->with('success', 'Source updated.');
    }

    public function destroy(Request $request, Source $source): RedirectResponse
    {
        $this->owned($request, $source);
        $source->delete();

        return back()->with('success', 'Source removed; discovered media was preserved.');
    }

    private function owned(Request $request, Source $source): void
    {
        abort_unless($source->user_id === $request->user()->id, 403);
    }
}
