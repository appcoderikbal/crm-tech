<?php

declare(strict_types=1);

/**
 * API front controller. Everything under /api/v1 is rewritten here by
 * public_html/.htaccess.
 */

$backendPath = require dirname(__DIR__) . '/_bootstrap.php';

use Techzapp\Crm\Bootstrap;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Logger;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;

$config = Bootstrap::init('api');

try {
    $request = Request::capture();
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->statusCode(), $e->errorCode(), $e->details())->send();
    exit;
}

// --- CORS -------------------------------------------------------------------
// Same-origin deployments need none of this. It exists so the Vite dev server
// can talk to a staging API; the allow-list is explicit and credentials are
// only ever granted to a listed origin.
$origin = $request->origin();
$allowedOrigins = array_map('trim', $config->array('security.cors_allowed_origins', []));

if ($origin !== null && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
} elseif ($origin !== null && $request->method === 'OPTIONS') {
    http_response_code(403);
    exit;
}

if ($request->method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- HTTPS ------------------------------------------------------------------
if (Config::load()->isProduction() && !$request->isHttps()) {
    Response::error('HTTPS is required.', 403, 'https_required')->send();
    exit;
}

header('X-Frame-Options: DENY');

try {
    Auth::startSession();
    Auth::verifyCsrf($request);

    /** @var \Techzapp\Crm\Http\Router $router */
    $router = require $backendPath . '/src/routes.php';
    $router->dispatch($request)->send();
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->statusCode(), $e->errorCode(), $e->details())->send();
} catch (Throwable $e) {
    Logger::exception('api.bootstrap_failure', $e, ['path' => $request->path]);
    Response::error(
        'The service is temporarily unavailable.',
        503,
        'service_unavailable'
    )->send();
}
