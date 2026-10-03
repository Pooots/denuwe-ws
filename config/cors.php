<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Supports:
    | - Local: Vite on :3000 → php artisan serve
    | - Split: Vercel frontend → Hostinger API
    | - Same-origin: SPA + API on Hostinger (APP_URL)
    |
    | For a split deploy set at least:
    |   FRONTEND_URL=https://your-denuwe-frontend.example
    |   CORS_ALLOWED_ORIGINS=https://your-denuwe-frontend.example
    | then: php artisan config:clear
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    'allowed_origins' => (static function (): array {
        $normalize = static function (?string $origin): ?string {
            $origin = rtrim(trim((string) $origin), '/');

            return $origin !== '' ? $origin : null;
        };

        $origins = [
            $normalize(env('APP_URL', 'http://127.0.0.1:8000')),
            $normalize(env('FRONTEND_URL')),
            // Local Vite (always allowed so local UI can talk to local or remote API)
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:8000',
            'http://127.0.0.1:8000',
        ];

        foreach (explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')) as $extra) {
            $origins[] = $normalize($extra);
        }

        return array_values(array_unique(array_filter($origins)));
    })(),

    // Preview / branch deploys: https://denuwe-xxx.vercel.app
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)*(?:denuwe|viclub)[a-z0-9-]*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    // JWT Bearer auth does not need cookies; false avoids credential CORS edge cases.
    'supports_credentials' => false,

];
