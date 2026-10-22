<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in web server, used for local development only.
 *
 * It reproduces what public_html/.htaccess does on Hostinger: route /api/v1/*
 * to the API front controller, serve real files directly, and fall back to the
 * SPA entry point for client-side routes.
 *
 *   php -S localhost:8788 -t public backend/bin/dev_server.php
 *
 * Never expose this to the internet; use Apache/LiteSpeed in production.
 */

$root = dirname(__DIR__, 2) . '/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim(rawurldecode($path), '/');

// Block traversal before touching the filesystem.
if (str_contains($path, '..')) {
    http_response_code(400);
    exit('Bad request');
}

// --- API -------------------------------------------------------------------
if (str_starts_with($path, '/api/v1')) {
    require $root . '/api/index.php';
    return true;
}

$target = $root . $path;

// --- directory index -------------------------------------------------------
if (is_dir($target)) {
    $index = rtrim($target, '/') . '/index.php';
    if (is_file($index)) {
        require $index;
        return true;
    }
}

// --- real files ------------------------------------------------------------
if (is_file($target)) {
    if (str_ends_with($target, '.php')) {
        require $target;
        return true;
    }
    return false; // let the built-in server stream it
}

// --- SPA fallback ----------------------------------------------------------
$spa = $root . '/index.html';
if (is_file($spa)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile($spa);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain');
echo "Not found. Build the frontend first:\n  cd frontend && npm run build\n";
return true;
