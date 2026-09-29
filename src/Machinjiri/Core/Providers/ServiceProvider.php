<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Providers;

use Closure;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

/**
 * Base ServiceProvider.
 *
 * Delegates all container mutation to Container's public API — providers no
 * longer touch Container internals or create dynamic properties.
 */
abstract class ServiceProvider
{
    protected Container $app;
    protected EventListener $events;

    protected bool $defer = false;

    /** @var array<string,true> */
    protected array $bindings = [];

    /** @var array<string,true> */
    protected array $singletons = [];

    /** @var array<string,true> */
    protected array $aliases = [];

    public function __construct(Container $app)
    {
        $this->app = $app;

        // Shared event bus. Prefer the container singleton; fall back to a
        // local instance if the app hasn't bound one (keeps BC for small apps).
        $this->events = $app->bound(EventListener::class)
            ? $app->make(EventListener::class)
            : new EventListener(
                LoggerFactory::system('service-provider', 'service_provider', false)
            );
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    abstract public function register(): void;

    public function boot(): void
    {
        // override as needed
    }

    /**
     * Names of services this provider supplies. Used by the loader to build
     * the deferred-service index.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->aliases),
        )));
    }

    /**
     * Optional boot ordering (higher = later). The loader sorts by this.
     */
    public function bootPriority(): int
    {
        return 0;
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }

    /**
     * Optional hint list. Subclasses may override to declare services they
     * will supply before register() runs.
     *
     * @return list<string>
     */
    public function when(): array
    {
        return [];
    }

    // ---------------------------------------------------------------------
    // Binding
    // ---------------------------------------------------------------------

    protected function bind(
        string $abstract,
        string|Closure|null $concrete = null,
        bool $shared = false
    ): void {
        $this->app->bind($abstract, $concrete ?? $abstract, $shared);
        $this->bindings[$abstract] = true;
    }

    protected function singleton(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->app->singleton($abstract, $concrete ?? $abstract);
        $this->singletons[$abstract] = true;
    }

    protected function scoped(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->app->scoped($abstract, $concrete ?? $abstract);
        $this->singletons[$abstract] = true;
    }

    protected function instance(string $abstract, mixed $instance): void
    {
        $this->app->instance($abstract, $instance);
        $this->singletons[$abstract] = true;
    }

    protected function alias(string $abstract, string $alias): void
    {
        $this->app->alias($abstract, $alias);
        $this->aliases[$alias] = true;
    }

    protected function bindMany(array $bindings): void
    {
        foreach ($bindings as $abstract => $concrete) {
            is_int($abstract)
                ? $this->bind((string) $concrete)
                : $this->bind((string) $abstract, $concrete);
        }
    }

    protected function singletonMany(array $singletons): void
    {
        foreach ($singletons as $abstract => $concrete) {
            is_int($abstract)
                ? $this->singleton((string) $concrete)
                : $this->singleton((string) $abstract, $concrete);
        }
    }

    protected function aliasMany(array $aliases): void
    {
        foreach ($aliases as $alias => $abstract) {
            $this->alias((string) $abstract, (string) $alias);
        }
    }

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    protected function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->events->on($event, $listener, $priority);
    }

    /**
     * Register multiple listeners.
     *
     * Accepts:
     *   ['event.name' => callable]
     *   ['event.name' => [callable, callable, ...]]
     *   ['event.name' => [callable, priority]]
     *   ['event.name' => [[callable, priority], ...]]
     */
    protected function listenMany(array $listeners): void
    {
        foreach ($listeners as $event => $entries) {
            foreach ($this->normaliseListeners($entries) as [$listener, $priority]) {
                $this->listen((string) $event, $listener, $priority);
            }
        }
    }

    /** @return list<array{0:callable,1:int}> */
    private function normaliseListeners(mixed $entries): array
    {
        if (is_callable($entries)) {
            return [[$entries, 0]];
        }
        if (!is_array($entries)) {
            return [];
        }
        // ['event' => [callable, priority]]
        if (count($entries) === 2
            && is_callable($entries[0] ?? null)
            && is_int($entries[1] ?? null)
        ) {
            return [[$entries[0], $entries[1]]];
        }
        $out = [];
        foreach ($entries as $entry) {
            if (is_callable($entry)) {
                $out[] = [$entry, 0];
            } elseif (is_array($entry) && is_callable($entry[0] ?? null)) {
                $out[] = [$entry[0], (int) ($entry[1] ?? 0)];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Config / routes / views / migrations / publishing / commands
    // ---------------------------------------------------------------------

    protected function mergeConfigFrom(string $path, string $key): void
    {
        $this->app->mergeConfigFrom($path, $key);
    }

    protected function loadRoutesFrom(string $path): void
    {
        if (!is_file($path)) {
            throw new MachinjiriException("Routes file not found: {$path}", 30107);
        }
        require $path;
    }

    protected function loadViewsFrom(string $path, ?string $namespace = null): void
    {
        $this->app->addViewPath($path, $namespace);
    }

    protected function loadMigrationsFrom(string $path): void
    {
        $this->app->addMigrationPath($path);
    }

    protected function publishes(array $assets, string $group = 'default'): void
    {
        $this->app->addPublishGroup($group, $assets);
    }

    protected function commands(array $commands): void
    {
        $this->app->addCommands($commands);
    }

    protected function registerMiddleware(string|array $middleware, ?string $name = null): void
    {
        $this->app->addMiddleware($middleware, $name);
    }

    // ---------------------------------------------------------------------
    // Container access
    // ---------------------------------------------------------------------

    protected function get(string $id): mixed
    {
        return $this->app->get($id);
    }

    protected function resolve(string $abstract, array $parameters = []): mixed
    {
        return $this->app->make($abstract, $parameters);
    }
}