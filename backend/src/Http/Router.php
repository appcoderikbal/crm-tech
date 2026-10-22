<?php

declare(strict_types=1);

namespace Techzapp\Crm\Http;

use Techzapp\Crm\Core\Config;
use Techzapp\Crm\Core\Logger;
use Throwable;

/**
 * Minimal pattern router. Routes are declared as
 *   $router->get('/api/v1/contacts/{id}', [ContactController::class, 'show']);
 * and handlers receive (Request $request, array $params).
 */
final class Router
{
    /** @var array<string,list<array{pattern:string,regex:string,params:list<string>,handler:callable|array}>> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable|array $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    private function add(string $method, string $pattern, callable|array $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $match) use (&$params): string {
                $params[] = $match[1];
                return '([^/]+)';
            },
            $pattern
        ) ?? $pattern;

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                return Response::noContent();
            }

            foreach ($this->routes[$request->method] ?? [] as $route) {
                if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                    continue;
                }

                array_shift($matches);
                $params = array_combine($route['params'], $matches) ?: [];

                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $method] = $handler;
                    $handler = [new $class(), $method];
                }

                /** @var Response $response */
                $response = $handler($request, $params);
                return $response;
            }

            // Distinguish "wrong method" from "no such route" for clearer debugging.
            foreach ($this->routes as $method => $routes) {
                if ($method === $request->method) {
                    continue;
                }
                foreach ($routes as $route) {
                    if (preg_match($route['regex'], $request->path) === 1) {
                        return Response::error('Method not allowed for this endpoint.', 405, 'method_not_allowed');
                    }
                }
            }

            return Response::error('Endpoint not found.', 404, 'not_found');
        } catch (ApiException $e) {
            return Response::error($e->getMessage(), $e->statusCode(), $e->errorCode(), $e->details());
        } catch (Throwable $e) {
            Logger::exception('api.unhandled_exception', $e, [
                'path'   => $request->path,
                'method' => $request->method,
            ]);

            // Never leak database errors, file paths or stack traces to clients.
            $debug = Config::load()->isDebug();
            return Response::error(
                $debug ? $e->getMessage() : 'An unexpected error occurred. The incident has been logged.',
                500,
                'server_error',
                $debug ? ['exception' => $e::class, 'at' => $e->getFile() . ':' . $e->getLine()] : []
            );
        }
    }
}
