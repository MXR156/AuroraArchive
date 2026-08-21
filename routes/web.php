<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TubeSyncImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login')->name('register');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', HomeController::class)->name('home');
    Route::get('/subscriptions', [SourceController::class, 'index'])->name('sources.index');
    Route::get('/subscriptions/{source}', [SourceController::class, 'show'])->name('sources.show');
    Route::post('/subscriptions', [SourceController::class, 'store'])->name('sources.store');
    Route::post('/subscriptions/{source}/scan', [SourceController::class, 'scan'])->name('sources.scan');
    Route::patch('/subscriptions/{source}/toggle', [SourceController::class, 'toggle'])->name('sources.toggle');
    Route::delete('/subscriptions/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
    Route::get('/library', LibraryController::class)->name('library');
    Route::get('/playlists', [PlaylistController::class, 'index'])->name('playlists.index');
    Route::post('/playlists', [PlaylistController::class, 'store'])->name('playlists.store');
    Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('playlists.show');
    Route::put('/playlists/{playlist}', [PlaylistController::class, 'update'])->name('playlists.update');
    Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])->name('playlists.destroy');
    Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
    Route::get('/channels/{channel}', [ChannelController::class, 'show'])->name('channels.show');
    Route::get('/downloads', fn () => redirect()->route('library', ['filter' => 'downloading']))->name('downloads');
    Route::get('/watch/{medium}', [MediaController::class, 'show'])->name('media.show');
    Route::get('/media/{medium}/edit', [MediaController::class, 'edit'])->name('media.edit');
    Route::post('/media/bulk', [MediaController::class, 'bulkManage'])->name('media.bulk-manage');
    Route::post('/media/retry', [MediaController::class, 'bulkRetry'])->name('media.bulk-retry');
    Route::put('/media/{medium}', [MediaController::class, 'update'])->name('media.update');
    Route::get('/media/{medium}/stream', [MediaController::class, 'stream'])->name('media.stream');
    Route::get('/media/{medium}/thumbnail', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::post('/media/{medium}/download', [MediaController::class, 'queue'])->name('media.queue');
    Route::delete('/media/{medium}', [MediaController::class, 'destroy'])->name('media.destroy');
    Route::put('/media/{medium}/progress', [MediaController::class, 'progress'])->name('media.progress');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings/cookies', [SettingsController::class, 'store'])->name('settings.cookies.store');
    Route::post('/settings/cookies/test', [SettingsController::class, 'test'])->name('settings.cookies.test');
    Route::delete('/settings/cookies', [SettingsController::class, 'destroy'])->name('settings.cookies.destroy');
    Route::post('/settings/yt-dlp/update', [SettingsController::class, 'updateDownloader'])->name('settings.yt-dlp.update');
    Route::get('/imports/tubesync', [TubeSyncImportController::class, 'index'])->name('imports.tubesync');
    Route::post('/imports/tubesync', [TubeSyncImportController::class, 'store'])->name('imports.tubesync.store');
    Route::get('/system-health', SystemHealthController::class)->name('system-health');
});
