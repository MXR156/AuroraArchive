<?php

namespace App\Http\Controllers;

use App\Jobs\ScanSource;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(Request $request): View
    {
        return view('sources.index', ['sources' => $request->user()->sources()->withCount('media')->latest()->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['channel', 'playlist', 'video'])], 'name' => ['required', 'string', 'max:255'], 'external_id' => ['required', 'string', 'max:255'], 'url' => ['required', 'url', 'starts_with:https://www.youtube.com/,https://youtube.com/,https://youtu.be/'], 'scan_interval_minutes' => ['required', 'integer', 'min:15', 'max:10080'], 'auto_download' => ['nullable', 'boolean']]);
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
