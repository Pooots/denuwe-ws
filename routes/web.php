<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $spa = public_path('index.html');

    if (is_file($spa)) {
        return response()->file($spa);
    }

    return response()->json([
        'type' => 'api',
        'name' => 'denuwe',
        'version' => '1.0.0',
        'description' => 'denuwe platform API',
        'health' => url('/api/health'),
        'documentation' => null,
        'contact' => null,
        'license' => 'MIT',
        'author' => 'denuwe',
    ]);
});

// SPA deep links (Apache) when frontend build is in public/
Route::get('/{any}', function () {
    $spa = public_path('index.html');

    if (is_file($spa)) {
        return response()->file($spa);
    }

    abort(404);
})->where('any', '^(?!api(?:/|$)|up$|sanctum(?:/|$)|storage(?:/|$)).*');
