<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\ApplyMediaFilters;
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
}
