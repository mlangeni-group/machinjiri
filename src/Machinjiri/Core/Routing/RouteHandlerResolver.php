<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Routing\Contracts\HandlerResolverInterface;

class RouteHandlerResolver implements HandlerResolverInterface
{
    public function execute(mixed $handler, array $params, HttpRequest $request, HttpResponse $response): mixed
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, array_merge([$request, $response], $params));
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$controller, $method] = explode('@', $handler, 2);

            // Support both fully-qualified class names and short names
            $controller = ltrim($controller, '\\');
            $controllerClass = str_contains($controller, '\\')
                ? $controller
                : "App\\Controllers\\{$controller}";

            if (!class_exists($controllerClass)) {
                // fallback to config namespace
                $controllerClass = "App\\Controllers\\{$controller}";
            }

            if (!class_exists($controllerClass)) {
                throw new MachinjiriException("Controller class '{$controllerClass}' not found");
            }

            $instance = new $controllerClass();
            if (!method_exists($instance, $method)) {
                throw new MachinjiriException("Method '{$method}' not found in controller '{$controllerClass}'");
            }
            return call_user_func_array([$instance, $method], array_merge([$request, $response], $params));
        }

        if (is_array($handler) && count($handler) === 2) {
            [$controller, $method] = $handler;
            if (is_string($controller) && class_exists($controller)) {
                $instance = new $controller();
            } elseif (is_object($controller)) {
                $instance = $controller;
            } else {
                throw new MachinjiriException("Invalid controller in route handler array");
            }
            if (!method_exists($instance, $method)) {
                throw new MachinjiriException("Method '{$method}' not found in " . get_class($instance));
            }
            return call_user_func_array([$instance, $method], array_merge([$request, $response], $params));
        }

        throw new MachinjiriException("Invalid route handler");
    }
}