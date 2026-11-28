<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/noop', fn () => response()->noContent());
Route::post('/probe/echo', fn (Request $request) => ['body' => $request->getContent()]);
Route::get('/user', fn (Request $request) => ['name' => $request->user()->name])->middleware('auth:sanctum');
