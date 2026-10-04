<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Base;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Kernel\Base\Middleware;

/**
 * Base Middleware Class
 *
 * All application middleware must extend this class and implement the handle() method.
 */
abstract class AbstractMiddleware implements Middleware
{
    /**
     * Process the request and optionally call the next middleware.
     *
     * @param HttpRequest  $request
     * @param HttpResponse $response
     * @param callable     $next   Next middleware or final route handler.
     * @param array        $params Route parameters.
     * @return mixed
     * @throws MachinjiriException
     */
    abstract public function handle(
        HttpRequest $request,
        HttpResponse $response,
        callable $next,
        array $params = []
    );

    /**
     * Perform actions after the response has been sent (optional).
     * Override this method in concrete middleware if needed.
     *
     * @param HttpRequest  $request
     * @param HttpResponse $response
     * @return void
     */
    public function terminate(HttpRequest $request, HttpResponse $response): void
    {
        // Optional: log, cleanup, etc.
    }

    // -------------------------------------------------------------------------
    // Helper methods for middleware
    // -------------------------------------------------------------------------

    /**
     * Check if the request is an AJAX request.
     *
     * @return bool
     */
    protected function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * Check if the request expects a JSON response.
     *
     * @return bool
     */
    protected function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Get route parameters from the request (requires router integration).
     *
     * @param HttpRequest $request
     * @return array
     */
    protected function getRouteParams(HttpRequest $request): array
    {
        return $request->getRouteParams() ?? [];
    }

    /**
     * Shortcut to call the next middleware.
     *
     * @param callable $next
     * @param array    $params
     * @return mixed
     */
    protected function next(callable $next, array $params = [])
    {
        return $next($params);
    }
}