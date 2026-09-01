<?php

use App\Jobs\QueueMediaAvailabilityChecks;
use App\Jobs\ScanSource;
use App\Models\Source;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Source::query()->where('enabled', true)->where(fn ($query) => $query->whereNull('next_scan_at')->orWhere('next_scan_at', '<=', now()))->orderBy('id')->limit(25)->get()->each(fn (Source $source) => ScanSource::dispatch($source));
})->name('scan-due-youtube-sources')->everyFiveMinutes()->withoutOverlapping();

Schedule::call(fn () => QueueMediaAvailabilityChecks::dispatch())
    ->name('check-archived-media-youtube-availability')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping();
