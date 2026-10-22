<?php

declare(strict_types=1);

/**
 * Locates the private backend directory from a public entry point.
 *
 * The backend (source, vendor, config, storage) must live OUTSIDE public_html.
 * On Hostinger the usual layout is:
 *
 *   /home/uXXXXXXX/domains/example.com/public_html/     <- this directory tree
 *   /home/uXXXXXXX/domains/example.com/private/backend/ <- backend
 *
 * If your layout differs, set CRM_BACKEND_PATH in public_html/.htaccess:
 *   SetEnv CRM_BACKEND_PATH /home/uXXXXXXX/domains/example.com/private/backend
 */

$crmBackendPath = null;

$candidates = array_filter([
    getenv('CRM_BACKEND_PATH') ?: null,
    $_SERVER['CRM_BACKEND_PATH'] ?? null,
    dirname(__DIR__) . '/backend',                       // repository layout
    dirname(__DIR__, 2) . '/private/backend',            // public_html sibling
    dirname(__DIR__, 3) . '/private/backend',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../private/backend',
]);

foreach ($candidates as $candidate) {
    $path = rtrim((string) $candidate, '/\\');
    if ($path !== '' && is_file($path . '/src/Bootstrap.php')) {
        $crmBackendPath = $path;
        break;
    }
}

if ($crmBackendPath === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => [
            'code'    => 'backend_not_found',
            'message' => 'Application backend not found. Set CRM_BACKEND_PATH in public_html/.htaccess.',
        ],
    ]);
    exit;
}

require_once $crmBackendPath . '/vendor/autoload.php';

return $crmBackendPath;
