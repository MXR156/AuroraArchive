<?php

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    public function __invoke(Request $request): View
    {
        $media = Media::query()->when($request->filled('q'), fn ($query) => $query->where(fn ($nested) => $nested->where('title', 'like', '%'.$request->string('q').'%')->orWhere('channel_name', 'like', '%'.$request->string('q').'%')))->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))->latest('published_at')->paginate(24)->withQueryString();

        return view('library', ['media' => $media, 'statuses' => MediaStatus::cases()]);
    }
}
