<?php

namespace App\Http\Controllers;

use App\Enums\MediaStatus;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $recent = Media::query()->where('status', MediaStatus::Downloaded)->latest()->limit(12)->get();
        $discovered = Media::query()->latest()->limit(12)->get();
        $continue = Media::query()->whereHas('watchHistory', fn ($query) => $query->whereBelongsTo($request->user())->where('watched', false)->where('position_seconds', '>', 0))->latest()->limit(6)->get();

        return view('home', compact('recent', 'discovered', 'continue'));
    }
}
