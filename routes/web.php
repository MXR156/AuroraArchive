<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\SystemHealthController;
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
    Route::post('/subscriptions', [SourceController::class, 'store'])->name('sources.store');
    Route::post('/subscriptions/{source}/scan', [SourceController::class, 'scan'])->name('sources.scan');
    Route::patch('/subscriptions/{source}/toggle', [SourceController::class, 'toggle'])->name('sources.toggle');
    Route::delete('/subscriptions/{source}', [SourceController::class, 'destroy'])->name('sources.destroy');
    Route::get('/library', LibraryController::class)->name('library');
    Route::get('/downloads', fn () => redirect()->route('library', ['status' => 'downloading']))->name('downloads');
    Route::get('/watch/{medium}', [MediaController::class, 'show'])->name('media.show');
    Route::get('/media/{medium}/stream', [MediaController::class, 'stream'])->name('media.stream');
    Route::post('/media/{medium}/download', [MediaController::class, 'queue'])->name('media.queue');
    Route::put('/media/{medium}/progress', [MediaController::class, 'progress'])->name('media.progress');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::put('/settings/cookies', [SettingsController::class, 'store'])->name('settings.cookies.store');
    Route::post('/settings/cookies/test', [SettingsController::class, 'test'])->name('settings.cookies.test');
    Route::delete('/settings/cookies', [SettingsController::class, 'destroy'])->name('settings.cookies.destroy');
    Route::get('/system-health', SystemHealthController::class)->name('system-health');
});
