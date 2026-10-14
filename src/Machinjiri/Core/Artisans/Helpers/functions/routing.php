<?php

use Mlangeni\Machinjiri\Core\Routing\Router;

if (!function_exists('route_get')) {
    /**
     * Register a GET route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_get(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::get($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_post')) {
    /**
     * Register a POST route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_post(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::post($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_put')) {
    /**
     * Register a PUT route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_put(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::put($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_delete')) {
    /**
     * Register a DELETE route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_delete(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::delete($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_any')) {
    /**
     * Register a route that responds to any HTTP method.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_any(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::any($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_ajax')) {
    /**
     * Register an AJAX-only route (responds only to XMLHttpRequest).
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_ajax(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::ajax($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_traditional')) {
    /**
     * Register a traditional (non-AJAX) route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_traditional(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::traditional($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_group')) {
    /**
     * Create a route group with shared attributes (prefix, middleware, etc.).
     *
     * @param array $attributes
     * @param callable $callback
     * @return Router
     */
    function route_group(array $attributes, callable $callback): Router
    {
        return Router::group($attributes, $callback);
    }
}

if (!function_exists('route_middleware')) {
    /**
     * Apply middleware to a route or group.
     *
     * @param mixed $middleware
     * @param callable|null $callback
     * @return Router
     */
    function route_middleware(mixed $middleware, ?callable $callback = null): Router
    {
        return Router::middleware($middleware, $callback);
    }
}

if (!function_exists('route_cors')) {
    /**
     * Apply CORS configuration to a route or group.
     *
     * @param array $config
     * @param callable|null $callback
     * @return Router
     */
    function route_cors(array $config = [], ?callable $callback = null): Router
    {
        if ($callback !== null) {
            return Router::cors($config);
        }
        return Router::cors($config);
    }
}

if (!function_exists('base_url')) {
    /**
     * Get the base URL of the application (protocol + host + base path).
     *
     * @return string
     */
    function base_url(): string
    {
        return Router::baseUrl();
    }
}

if (!function_exists('route_url')) {
    /**
     * Generate a URL for a named route.
     *
     * @param string $name
     * @param array $params
     * @return string
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function route_url(string $name, array $params = []): string
    {
        return Router::route($name, $params);
    }
}

if (!function_exists('route_absolute')) {
    /**
     * Generate an absolute URL (including protocol and host) for a named route.
     *
     * @param string $name
     * @param array $params
     * @return string
     */
    function route_absolute(string $name, array $params = []): string
    {
        return Router::absoluteRoute($name, $params);
    }
}

if (!function_exists('dispatch_routes')) {
    /**
     * Dispatch the current request to the matching route.
     *
     * @return void
     */
    function dispatch_routes(): void
    {
        Router::dispatch();
    }
}