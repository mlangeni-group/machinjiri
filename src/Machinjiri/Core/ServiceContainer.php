<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core;

use Closure;
use Mlangeni\Machinjiri\Core\Exceptions\BindingResolutionException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Standalone service container (IoC).
 *
 * Owns the bindings/aliases/instances state and performs resolution
 * including reflection-based auto-wiring of constructor dependencies.
 */
class ServiceContainer
{
    public array $bindings  = [];
    public array $aliases   = [];
    public array $instances = [];

    /** Facade (usually Container) passed to closure bindings. */
    protected ?object $facade = null;

    /**
     * Attach the facade object so closures resolve with the same
     * first argument they always had (the Container).
     */
    public function setFacade(object $facade): void
    {
        $this->facade = $facade;
    }

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?: $abstract,
            'shared'   => $shared,
        ];
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function unbind(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->instances[$abstract]);

        foreach ($this->aliases as $alias => $target) {
            if ($target === $abstract) {
                unset($this->aliases[$alias]);
            }
        }
    }

    public function flush(): void
    {
        $this->bindings  = [];
        $this->instances = [];
        $this->aliases   = [];
    }

    // -----------------------------------------------------------------
    // Introspection
    // -----------------------------------------------------------------

    public function bound(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        return isset($this->bindings[$abstract]);
    }

    public function hasInstance(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }

    /** @return string[] */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }

    // -----------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------

    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Resolve a service, applying alias, singleton cache, closure/class
     * instantiation, and (when no parameters are supplied) auto-wiring.
     *
     * @throws BindingResolutionException
     */
    public function resolve(string $abstract, array $parameters = [])
    {
        // Alias
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        // Explicit binding
        if (isset($this->bindings[$abstract])) {
            $binding = $this->bindings[$abstract];

            if ($binding['shared'] && isset($this->instances[$abstract])) {
                return $this->instances[$abstract];
            }

            $instance = $this->buildConcrete($binding['concrete'], $parameters);

            if ($binding['shared']) {
                $this->instances[$abstract] = $instance;
            }

            return $instance;
        }

        // Auto-resolve concrete class
        if (class_exists($abstract)) {
            return $this->buildClass($abstract, $parameters);
        }

        throw new BindingResolutionException("Unable to resolve service: {$abstract}", 110);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function buildConcrete($concrete, array $parameters)
    {
        if ($concrete instanceof Closure || is_callable($concrete)) {
            // Preserve BC: closures receive the facade (Container) as arg 0.
            $target = $this->facade ?? $this;

            return call_user_func_array($concrete, array_merge([$target], $parameters));
        }

        if (is_string($concrete) && class_exists($concrete)) {
            return $this->buildClass($concrete, $parameters);
        }

        return $concrete;
    }

    /**
     * Instantiate a class. If $parameters are supplied we preserve the
     * legacy `new $class(...$parameters)` behaviour exactly. If not, we
     * auto-wire the constructor via reflection.
     */
    private function buildClass(string $class, array $parameters = [])
    {
        // Preserve legacy behaviour when explicit parameters were passed.
        if (! empty($parameters)) {
            return new $class(...$parameters);
        }

        try {
            $reflector = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new BindingResolutionException("Target class [{$class}] does not exist.", 114, $e);
        }

        if (! $reflector->isInstantiable()) {
            throw new BindingResolutionException("Target [{$class}] is not instantiable.", 115);
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $parameter) {
            $dependencies[] = $this->resolveParameter($parameter, $class);
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve a single constructor parameter via type hint, default value,
     * or nullability — in that order.
     */
    private function resolveParameter(ReflectionParameter $parameter, string $owner)
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return $this->resolve($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        $name = $parameter->getName();

        throw new BindingResolutionException(
            "Unresolvable dependency [\${$name}] while instantiating [{$owner}]",
            116
        );
    }
}