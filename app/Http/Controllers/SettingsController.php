<?php

namespace App\Http\Controllers;

use App\Contracts\YoutubeDownloader;
use App\Models\YoutubeCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request, YoutubeDownloader $youtube): View
    {
        return view('settings', [
            'credential' => YoutubeCredential::query()->whereBelongsTo($request->user())->first(),
            'ytDlpVersion' => $youtube->version(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['cookies' => ['required', File::types(['txt'])->max('2mb')]]);
        $contents = $request->file('cookies')->get();
        abort_unless(is_string($contents) && str_contains($contents, 'Netscape HTTP Cookie File'), 422, 'This is not a Netscape cookies.txt file.');
        $request->user()->youtubeCredential()->updateOrCreate([], ['cookies' => $contents, 'status' => 'unable_to_validate', 'status_message' => 'Uploaded; test authentication to verify.']);

        return back()->with('success', 'Cookies encrypted and stored.');
    }

    public function test(Request $request, YoutubeDownloader $youtube): RedirectResponse
    {
        $credential = YoutubeCredential::query()->whereBelongsTo($request->user())->first();
        if (! $credential) {
            return back()->withErrors(['cookies' => 'Upload cookies first.']);
        }
        $result = $youtube->testAuthentication($credential->cookies);
        $credential->update(['status' => $result['status'], 'status_message' => $result['message'], 'tested_at' => now()]);

        return back()->with('success', $result['message']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        YoutubeCredential::query()->whereBelongsTo($request->user())->delete();

        return back()->with('success', 'Cookies removed.');
    }

    public function updateDownloader(Request $request, YoutubeDownloader $youtube): RedirectResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['stable', 'nightly'])]]);
        $result = $youtube->update($data['channel']);

        if (! $result['successful']) {
            return back()->withErrors(['yt_dlp' => $result['message']]);
        }

        return back()->with('success', 'yt-dlp updated to '.$data['channel'].' ('.($result['version'] ?? 'version unavailable').').');
    }
}
