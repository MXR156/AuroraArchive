<?php

namespace App\Providers;

use App\Contracts\YoutubeDownloader;
use App\Models\YoutubeCredential;
use App\Services\YtDlpService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(YoutubeDownloader::class, YtDlpService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        View::composer('components.layouts.app', function ($view): void {
            $view->with('youtubeCredential', auth()->check() ? YoutubeCredential::query()->whereBelongsTo(auth()->user())->first() : null);
        });
    }
}
