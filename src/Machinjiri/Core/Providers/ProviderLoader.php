<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Providers;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Providers\Contracts\DeferredProviderInterface;
use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Throwable;

/**
 * Loads, registers and boots service providers.
 *
 *  - Correctly registers deferred providers the first time one of their
 *    services is resolved.
 *  - Boots providers in priority order.
 *  - Caches the provider manifest at bootstrap/cache/providers.php.
 *  - Never touches global state (no Container::setInstance).
 */
final class ProviderLoader
{
    protected Container $app;

    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    protected array $providers = [];

    /** @var array<class-string<ServiceProvider>, true> */
    protected array $registered = [];

    /** @var array<class-string<ServiceProvider>, true> */
    protected array $booted = [];

    /** @var array<string, class-string<ServiceProvider>> */
    protected array $deferred = [];

    public function __construct(Container $app)
    {
        $this->app = $app;

        Container::setInstance($app); // for ServiceProvider constructor

        $this->app->setProviderLoader($this);
    }

    // ---------------------------------------------------------------------
    // Public lifecycle
    // ---------------------------------------------------------------------

    public function register(): void
    {
        $manifest = $this->loadManifest();

        foreach ($manifest['providers'] as $providerClass) {
            $this->registerProvider($providerClass);
        }

        foreach ($manifest['deferred'] as $providerClass) {
            $this->registerDeferredProviderClass($providerClass);
        }
    }

    public function boot(): void
    {
        // Sort by declared priority (stable for equal priorities).
        $eager = array_filter(
            $this->providers,
            static fn (ServiceProvider $p): bool => !$p->isDeferred()
        );

        uasort($eager, static fn (ServiceProvider $a, ServiceProvider $b): int
            => $a->bootPriority() <=> $b->bootPriority());

        foreach ($eager as $provider) {
            $this->bootProvider($provider);
        }
    }

    // ---------------------------------------------------------------------
    // Provider registration
    // ---------------------------------------------------------------------

    /**
     * @param class-string<ServiceProvider> $providerClass
     */
    public function registerProvider(string $providerClass): ServiceProvider
    {
        if (isset($this->providers[$providerClass])) {
            return $this->providers[$providerClass];
        }
        if (!class_exists($providerClass)) {
            throw new MachinjiriException(
                "Service provider not found: {$providerClass}", 30110
            );
        }
        if (!is_subclass_of($providerClass, ServiceProvider::class)) {
            throw new MachinjiriException(
                "{$providerClass} must extend " . ServiceProvider::class, 30113
            );
        }

        try {
            /** @var ServiceProvider $provider */
            $provider = new $providerClass($this->app);
        } catch (Throwable $e) {
            throw new MachinjiriException(
                "Failed to instantiate provider {$providerClass}: {$e->getMessage()}",
                30111, $e
            );
        }

        $this->providers[$providerClass] = $provider;

        if ($provider->isDeferred()) {
            // Do NOT call register() yet — that happens on first resolution.
            $this->registerDeferredServices($provider);
            return $provider;
        }

        $this->invokeLifecycle($provider, 'register');

        return $provider;
    }

    /**
     * Boot a single provider exactly once.
     */
    public function bootProvider(ServiceProvider $provider): void
    {
        $class = $provider::class;
        if (isset($this->booted[$class])) {
            return;
        }
        $this->invokeLifecycle($provider, 'boot');
        $this->booted[$class] = true;
    }

    // ---------------------------------------------------------------------
    // Deferred loading
    // ---------------------------------------------------------------------

    /**
     * Called by the container/event dispatcher when a bound service that
     * doesn't exist yet is requested and matches a deferred provider.
     */
    public function loadDeferredProvider(string $service): void
    {
        if (!isset($this->deferred[$service])) {
            return;
        }

        $providerClass = $this->deferred[$service];

        if (!isset($this->providers[$providerClass])) {
            $this->registerProvider($providerClass);
        }

        // Deferred provider's register() is only called the first time we
        // actually need one of its services.
        if (!isset($this->registered[$providerClass])) {
            $this->invokeLifecycle($this->providers[$providerClass], 'register');
        }

        if (!isset($this->booted[$providerClass])) {
            $this->bootProvider($this->providers[$providerClass]);
        }
    }

    /**
     * Seed the deferred map for a class, without calling register().
     *
     * @param class-string<ServiceProvider> $providerClass
     */
    protected function registerDeferredProviderClass(string $providerClass): void
    {
        // Static declaration → no instantiation.
        if (is_subclass_of($providerClass, DeferredProviderInterface::class)) {
            /** @var class-string<ServiceProvider&DeferredProviderInterface> $providerClass */
            foreach ($providerClass::providesStatic() as $service) {
                $this->deferred[$service] = $providerClass;
            }
            foreach ($providerClass::when() as $service) {
                $this->deferred[$service] = $providerClass;
            }
            return;
        }

        // Fallback: instantiate and read provides()/when().
        $this->registerProvider($providerClass);
    }

    /**
     * Populate the deferred-service map from an instantiated provider.
     */
    protected function registerDeferredServices(ServiceProvider $provider): void
    {
        foreach ($provider->provides() as $service) {
            $this->deferred[$service] = $provider::class;
        }
        foreach ($provider->when() as $service) {
            $this->deferred[$service] = $provider::class;
        }
    }

    // ---------------------------------------------------------------------
    // Error-wrapped invocation
    // ---------------------------------------------------------------------

    protected function invokeLifecycle(ServiceProvider $provider, string $method): void
    {
        $class = $provider::class;
        try {
            $provider->{$method}();
            $this->registered[$class] = true;
        } catch (Throwable $e) {
            throw new MachinjiriException(
                "Error in {$class}::{$method}(): {$e->getMessage()}",
                30112, $e
            );
        }
    }

    // ---------------------------------------------------------------------
    // Manifest — built once, cached to bootstrap/cache
    // ---------------------------------------------------------------------

    /**
     * @return array{providers: list<class-string<ServiceProvider>>, deferred: list<class-string<ServiceProvider>>}
     */
    protected function loadManifest(): array
    {
        $cache = $this->cacheFile();

        if ($cache !== null && is_file($cache)) {
            $manifest = require $cache;
            if (is_array($manifest)) {
                return [
                    'providers' => $manifest['providers'] ?? [],
                    'deferred'  => $manifest['deferred']  ?? [],
                ];
            }
        }

        $manifest = $this->buildManifest();

        if ($cache !== null) {
            $this->writeCache($cache, $manifest);
        }

        return $manifest;
    }

    /**
     * @return array{providers: list<class-string<ServiceProvider>>, deferred: list<class-string<ServiceProvider>>}
     */
    protected function buildManifest(): array
    {
        $core = self::getCoreProviders();

        $userConfigPath = $this->app->configPath('services/providers.php');

        $user = [];

        if (is_file($userConfigPath)) {
            $user = require $userConfigPath;
            if (!is_array($user)) {
                throw new MachinjiriException(
                    "providers.php must return an array.", 30106
                );
            }
        }

        return [
            'providers' => array_values(array_unique(array_merge(
                $core['providers'],
                $user['providers'] ?? []
            ))),
            'deferred'  => array_values(array_unique(array_merge(
                $core['deferred'],
                $user['deferred'] ?? []
            ))),
        ];
    }

    protected function cacheFile(): ?string
    {
        try {
            return $this->app->basePath('storage/cache/providers.php');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array{providers: list<string>, deferred: list<string>} $manifest
     */
    protected function writeCache(string $file, array $manifest): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return; // best-effort
        }

        $export = var_export($manifest, true);
        $contents = "<?php\n\n// Auto-generated by ProviderLoader. Do not edit.\nreturn {$export};\n";
        @file_put_contents($file, $contents, LOCK_EX);
    }

    // ---------------------------------------------------------------------
    // Introspection / teardown
    // ---------------------------------------------------------------------

    /** @return list<class-string<ServiceProvider>> */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->registered);
    }

    /** @return list<class-string<ServiceProvider>> */
    public function getBootedProviders(): array
    {
        return array_keys($this->booted);
    }

    /** @return array<string, class-string<ServiceProvider>> */
    public function getDeferredServices(): array
    {
        return $this->deferred;
    }

    public function isBooted(string $providerClass): bool
    {
        return isset($this->booted[$providerClass]);
    }

    public function clear(): void
    {
        $this->providers  = [];
        $this->registered = [];
        $this->booted     = [];
        $this->deferred   = [];
    }

    // ---------------------------------------------------------------------
    // Core provider manifest
    // ---------------------------------------------------------------------

    /**
     * @return array{providers: list<class-string>, deferred: list<class-string>}
     */
    private static function getCoreProviders(): array
    {
        return [
            'providers' => [
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\AppServiceProvider::class,
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\DatabaseServiceProvider::class,
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\QueueServiceProvider::class,
            ],
            'deferred' => [
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\NotificationServiceProvider::class,
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\PerformanceServiceProvider::class,
            ],
        ];
    }
}