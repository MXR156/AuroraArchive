<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyFilmotMetadataRequest;
use App\Http\Requests\PreviewPastedMetadataRequest;
use App\Models\FilmotCredential;
use App\Models\Media;
use App\Services\FilmotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use RuntimeException;

class FilmotController extends Controller
{
    public function lookup(Request $request, Media $medium, FilmotService $filmot): RedirectResponse
    {
        $credential = FilmotCredential::query()->whereBelongsTo($request->user())->first();

        if ($credential === null) {
            return back()->withErrors(['filmot' => 'Add a Filmot RapidAPI key in Settings first.']);
        }

        try {
            $candidate = $filmot->lookup($medium->youtube_id, $credential->api_key);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['filmot' => $exception->getMessage()]);
        }

        if ($candidate === null) {
            return back()->withErrors(['filmot' => 'Filmot did not return metadata for this video ID.']);
        }

        $request->session()->put('filmot_previews.'.$medium->id, $candidate);

        return back()->with('success', 'Filmot metadata found. Review the fields before importing them.');
    }

    public function apply(ApplyFilmotMetadataRequest $request, Media $medium): RedirectResponse
    {
        $sessionKey = 'filmot_previews.'.$medium->id;
        $candidate = $request->session()->get($sessionKey);

        if (! is_array($candidate) || Arr::get($candidate, 'youtube_id') !== $medium->youtube_id) {
            return back()->withErrors(['filmot' => 'The Filmot preview has expired. Check Filmot again.']);
        }

        $fields = array_values(array_unique($request->validated('fields')));
        $updates = array_filter(
            Arr::only($candidate, $fields),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($updates === []) {
            return back()->withErrors(['filmot' => 'None of the selected fields contains a value.']);
        }

        $metadata = $medium->metadata ?? [];
        $provenanceKey = Arr::get($candidate, 'source') === 'pasted_json' ? 'pasted_metadata' : 'filmot';
        Arr::set($metadata, $provenanceKey.'.snapshot', Arr::except($candidate, ['retrieved_at', 'source']));
        Arr::set($metadata, $provenanceKey.'.retrieved_at', Arr::get($candidate, 'retrieved_at'));
        Arr::set($metadata, $provenanceKey.'.imported_at', now()->toIso8601String());
        Arr::set($metadata, $provenanceKey.'.imported_fields', array_keys($updates));

        $medium->update([...$updates, 'metadata' => $metadata]);
        $request->session()->forget($sessionKey);

        return back()->with('success', 'Selected metadata imported.');
    }

    public function previewPasted(PreviewPastedMetadataRequest $request, Media $medium, FilmotService $filmot): RedirectResponse
    {
        $payload = json_decode($request->validated('metadata_json'), true);
        $candidate = is_array($payload) ? $filmot->normalisePayload($payload, $medium->youtube_id) : null;

        if ($candidate === null) {
            return back()->withErrors(['metadata_json' => 'The JSON does not contain metadata matching this video ID.']);
        }

        $candidate['source'] = 'pasted_json';
        $request->session()->put('filmot_previews.'.$medium->id, $candidate);

        return back()->with('success', 'Pasted metadata converted. Review the fields before importing them.');
    }
}
