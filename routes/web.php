<?php

use Illuminate\Support\Facades\Route;

// The Phase 1 pilot web client: one static page that talks to /api on the same origin (no CORS needed).
Route::get('/', fn () => response()->file(resource_path('app/index.html'), [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-cache',
]));
