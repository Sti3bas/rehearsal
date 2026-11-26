<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => response('Rehearsal'));
Route::get('/probe/redirect', fn () => redirect('/state'));
Route::get('/probe/outside-db', fn () => ['configured' => config('database.connections.outside') !== null]);
Route::get('/probe/events', fn () => response()->eventStream(function () {
    yield new \Illuminate\Http\StreamedEvent(event: 'probe', data: 'ready');
    sleep(4);
}));
Route::get('/state', fn () => [
    'users' => User::query()->orderBy('id')->pluck('name'),
    'user' => Auth::user()?->name,
    'cache' => Cache::get('probe'),
    'disk' => Storage::disk('local')->exists('probe.txt') ? Storage::disk('local')->get('probe.txt') : null,
    'time' => now()->format('Y-m-d'),
]);
