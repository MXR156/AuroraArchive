<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportTubeSyncRequest;
use App\Jobs\ImportTubeSyncSources;
use App\Services\TubeSyncImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class TubeSyncImportController extends Controller
{
    public function __construct(private TubeSyncImporter $importer) {}

    public function index(Request $request): View
    {
        try {
            return view('imports.tubesync', ['preview' => $this->importer->preview($request->user()), 'connectionError' => null]);
        } catch (Throwable $exception) {
            report($exception);

            return view('imports.tubesync', ['preview' => null, 'connectionError' => $exception->getMessage()]);
        }
    }

    public function store(ImportTubeSyncRequest $request): RedirectResponse
    {
        ImportTubeSyncSources::dispatch($request->user(), $request->validated('sources'), $request->boolean('queue_missing'));

        return back()->with('success', 'TubeSync import queued. Large playlists will continue importing in the background.');
    }
}
