<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => response('Rehearsal'));
Route::post('/login', function (Request $request) {
    abort_unless(Auth::attempt($request->validate(['email' => 'required|email', 'password' => 'required|string'])), 401);
    $request->session()->regenerate();

    return response()->noContent();
});
Route::get('/probe/redirect', fn () => redirect('/state'));
Route::get('/probe/outside-db', fn () => ['configured' => config('database.connections.outside') !== null]);
Route::get('/probe/moderation', function () {
    Http::preventStrayRequests();
    try {
        $response = Http::post('https://api.openai.com/v1/moderations', ['input' => 'Synthetic example']);
    } catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\StrayRequestException) {
        return response()->json(['unavailable' => true], 503);
    }

    return response($response->body(), $response->status(), ['Content-Type' => $response->header('Content-Type')]);
});
Route::get('/probe/public-file', function () {
    $public = Storage::disk('public');
    $public->put('probe.txt', 'public probe');

    return ['url' => $public->url('probe.txt')];
});
Route::get('/probe/temporary-file', function () {
    $private = Storage::disk('local');
    $private->put('probe.txt', 'private probe');

    return ['url' => $private->temporaryUrl('probe.txt', now()->addMinutes(5))];
});
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
