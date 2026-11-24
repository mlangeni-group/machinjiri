<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Routing\Contracts\MiddlewareDispatcherInterface;

class MiddlewareDispatcher implements MiddlewareDispatcherInterface
{
    private const MIDDLEWARE_NAMESPACE = "App\\Http\\Middleware\\";

    public function __construct(
        protected HttpRequest $request,
        protected HttpResponse $response
    ) {}

    public function dispatch(array $middlewares, callable $core, array $params): void
    {
        $handler = $core;

        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $current = $middlewares[$i];
            if (is_string($current)) {
                $current = $this->resolveMiddleware($current);
            }
            $handler = function ($params) use ($current, $handler) {
                return $current($this->request, $this->response, $handler, $params);
            };
        }

        $handler($params);
    }

    protected function resolveMiddleware(string $name): callable
    {
        $class = self::MIDDLEWARE_NAMESPACE . $name;
        if (!class_exists($class)) {
            throw new MachinjiriException("Middleware class '{$class}' not found");
        }
        $instance = new $class();
        if (!method_exists($instance, 'handle')) {
            throw new MachinjiriException("Middleware '{$class}' must implement handle() method");
        }
        return [$instance, 'handle'];
    }
}