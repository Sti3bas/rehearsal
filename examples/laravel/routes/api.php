<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/noop', fn () => response()->noContent());
Route::get('/user', fn (Request $request) => ['name' => $request->user()->name])->middleware('auth:sanctum');
