<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportTubeSyncRequest;
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
        try {
            $summary = $this->importer->import($request->user(), $request->validated('sources'), $request->boolean('queue_missing'));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['tubesync' => $exception->getMessage()]);
        }

        return back()->with('success', sprintf(
            'TubeSync import complete: %d sources, %d media, %d existing files, %d thumbnails, %d queued, %d metadata-only.',
            $summary['sources'],
            $summary['media'],
            $summary['files'],
            $summary['thumbnails'],
            $summary['queued'],
            $summary['metadata_only'],
        ));
    }
}
