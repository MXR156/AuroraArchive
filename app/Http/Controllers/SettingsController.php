<?php

namespace App\Http\Controllers;

use App\Contracts\YoutubeDownloader;
use App\Models\YoutubeCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings', ['credential' => YoutubeCredential::query()->whereBelongsTo($request->user())->first()]);
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
}
