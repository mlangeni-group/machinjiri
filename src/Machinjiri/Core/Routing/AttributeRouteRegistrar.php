<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Ajax;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Bind;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Cors;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Middleware;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Prefix;
use Mlangeni\Machinjiri\Core\Routing\Attributes\RateLimit;
use Mlangeni\Machinjiri\Core\Routing\Attributes\RouteAttributeInterface;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Traditional;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Where;

class AttributeRouteRegistrar
{
    public function __construct(protected Router $router) {}

    public function registerMany(array $controllers): void
    {
        foreach ($controllers as $controller) {
            $this->register($controller);
        }
    }

    public function register(string $controllerClass): void
    {
        $controllerClass = ltrim($controllerClass, '\\');

        if (!class_exists($controllerClass)) {
            throw new MachinjiriException("Controller class '{$controllerClass}' not found");
        }

        $reflection = new \ReflectionClass($controllerClass);

        // ---- Class-level attributes ----
        $classPrefix     = '';
        $classMiddleware = [];
        $classCors       = null;
        $classRateLimit  = null;
        $classAjaxOnly   = false;
        $classNoAjax     = false;
        $classWheres     = [];
        $classBindings   = [];

        foreach ($reflection->getAttributes() as $attr) {
            $instance = $attr->newInstance();

            match (true) {
                $instance instanceof Prefix      => $classPrefix = $instance->prefix,
                $instance instanceof Middleware  => $classMiddleware = array_merge($classMiddleware, $instance->middleware),
                $instance instanceof Cors        => $classCors = $instance->toConfig(),
                $instance instanceof RateLimit   => $classRateLimit = $instance->limiter,
                $instance instanceof Ajax        => $classAjaxOnly = true,
                $instance instanceof Traditional => $classNoAjax = true,
                $instance instanceof Where       => $classWheres[$instance->param] = $instance->regex,
                $instance instanceof Bind        => $classBindings[$instance->param] = $instance->model,
                default                          => null,
            };
        }

        // ---- Method-level attributes ----
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $routeAttrs      = [];
            $methodMiddleware = [];
            $methodCors       = null;
            $methodRateLimit  = null;
            $methodAjaxOnly   = false;
            $methodNoAjax     = false;
            $methodWheres     = [];
            $methodBindings   = [];

            foreach ($method->getAttributes() as $attr) {
                $instance = $attr->newInstance();

                match (true) {
                    $instance instanceof RouteAttributeInterface => $routeAttrs[] = $instance,
                    $instance instanceof Middleware              => $methodMiddleware = array_merge($methodMiddleware, $instance->middleware),
                    $instance instanceof Cors                    => $methodCors = $instance->toConfig(),
                    $instance instanceof RateLimit               => $methodRateLimit = $instance->limiter,
                    $instance instanceof Ajax                    => $methodAjaxOnly = true,
                    $instance instanceof Traditional             => $methodNoAjax = true,
                    $instance instanceof Where                   => $methodWheres[$instance->param] = $instance->regex,
                    $instance instanceof Bind                    => $methodBindings[$instance->param] = $instance->model,
                    default                                      => null,
                };
            }

            if (empty($routeAttrs)) {
                continue;
            }

            foreach ($routeAttrs as $routeAttr) {
                $options = $routeAttr->getOptions();

                // Merge middleware (class-level first, then method-level)
                $mergedMiddleware = array_merge($classMiddleware, $methodMiddleware);
                if (!empty($mergedMiddleware)) {
                    $options['middleware'] = $mergedMiddleware;
                }

                // CORS: method-level overrides class-level
                $cors = $methodCors ?? $classCors;
                if ($cors !== null) {
                    $options['cors'] = $cors;
                }

                // Rate limit: method overrides class
                $rateLimit = $methodRateLimit ?? $classRateLimit;
                if ($rateLimit !== null) {
                    $options['rate_limit'] = $rateLimit;
                }

                // Ajax / Traditional flags
                if ($classAjaxOnly || $methodAjaxOnly) {
                    $options['ajax_only'] = true;
                }
                if ($classNoAjax || $methodNoAjax) {
                    $options['no_ajax'] = true;
                }

                // Where constraints
                $wheres = array_merge($classWheres, $methodWheres);
                if (!empty($wheres)) {
                    $options['where'] = $wheres;
                }

                // Route model bindings
                $bindings = array_merge($classBindings, $methodBindings);
                if (!empty($bindings)) {
                    $options['bindings'] = $bindings;
                }

                // Prepend class-level prefix to route pattern
                $pattern = $classPrefix . $routeAttr->getPattern();
                if ($pattern === '' || $pattern === null) {
                    $pattern = '/';
                }

                // Full class name handler so any namespace works
                $handler = "{$controllerClass}@{$method->getName()}";

                $this->router->addRoute(
                    $routeAttr->getMethods(),
                    $pattern,
                    $handler,
                    $routeAttr->getName(),
                    $options
                );
            }
        }
    }
}