<?php

use Illuminate\Support\Facades\Route;

// The Phase 1 pilot web client: one static page that talks to /api on the same origin (no CORS needed).
Route::get('/', fn () => response()->file(resource_path('app/index.html'), [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-cache',
]));

// The admin tool: one static page (Alpine.js, served from /vendor) on the same origin as the API. Staff sign in with their normal account.
Route::get('/admin', fn () => response()->file(resource_path('admin/index.html'), [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-cache',
]));
