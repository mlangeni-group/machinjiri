<?php

/**
 * Performance Service Provider
 *
 * This service provider is responsible for registering and bootstrapping
 * performance services. It binds interfaces to concrete implementations,
 * registers singleton instances, sets up configuration, and provides aliases
 * for easier access via the service container.
 *
 * @package Mlangeni\Machinjiri\Core\Providers\CoreProviders
 */

namespace Mlangeni\Machinjiri\Core\Providers\CoreProviders;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Performance\{Profiler, MemoryTracker, Span, Timer};
use Mlangeni\Machinjiri\Core\Performance\Collectors\{RequestCollector, QueryCollector, ExceptionCollector};

class PerformanceServiceProvider extends ServiceProvider
{
    /**
     * Register performance monitor services.
     * @return void
     */
    public function register(): void
    {
        // -------------------- HTTP Request/Response --------------------
        // Register the HTTP request as a singleton, created from global PHP superglobals.
        $this->singleton(Profiler::class, function($app) {
            return new Profiler($app->resolve(Timer::class), $app->resolve(MemoryTracker::class));
        });

        $this->singleton(MemoryTracker::class, function($app) {
            return new MemoryTracker();
        });

        $this->singleton(Timer::class, function($app) {
            return new Timer();
        });

        // -------------------- Aliases for Convenience --------------------
        // Provide shorter names for common services to simplify dependency resolution.
        $this->aliasMany([
            'performance.profiler'          => Profiler::class,
            'performance.memory.tracker'    => MemoryTracker::class,
            'performance.timer'             => Timer::class,
        ]);

    }

    /**
     * Bootstrap application services.
     *
     * This method is called after all service providers have been registered.
     * It loads the various configuration files from the config directory and merges
     * them into the application's configuration repository.
     *
     * @return void
     */
    public function boot(): void {}

    /**
     * Get the services provided by this provider.
     *
     * This method returns an array of service names (abstracts or aliases)
     * that this provider registers. It is used by the container to optimize
     * deferred service loading.
     *
     * @return array
     */
    public function provides(): array
    {
        // Combine all bindings, singletons, and aliases into a single list.
        return array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->aliases)
        );
    }
}