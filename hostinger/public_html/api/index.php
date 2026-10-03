<?php

/**
 * Hostinger bridge: public_html/api → sibling Laravel `backend` app.
 *
 * Upload this file to:
 *   public_html/api/index.php
 *
 * Expected layout:
 *   domains/your-site/
 *     public_html/          ← SPA (document root)
 *       api/index.php       ← this file
 *     backend/              ← full denuwe-ws Laravel project
 */

declare(strict_types=1);

define('LARAVEL_START', microtime(true));

// public_html/api → public_html → domain root → backend
$backend = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'backend';

if (! is_dir($backend)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Laravel backend folder not found.',
        'expected' => $backend,
        'hint' => 'Place denuwe-ws as a sibling folder named "backend" next to public_html.',
    ]);
    exit(1);
}

// Keep request path as /api/... so Laravel api routes match (not /v1/...).
$_SERVER['SCRIPT_FILENAME'] = $backend.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

if (file_exists($maintenance = $backend.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'maintenance.php')) {
    require $maintenance;
}

require $backend.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $backend.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';

$app->handleRequest(\Illuminate\Http\Request::capture());
