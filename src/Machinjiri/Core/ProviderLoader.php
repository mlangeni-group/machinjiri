<?php


namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;

/**
 * Service Provider Loader
 *
 * Loads and manages service providers for the application.
 */
class ProviderLoader
{
    /**
     * The application instance
     *
     * @var Container
     */
    public Container $app;

    /**
     * Registered service providers
     *
     * @var array
     */
    protected array $providers = [];

    /**
     * Booted service providers
     *
     * @var array
     */
    protected array $booted = [];

    /**
     * Deferred service providers
     *
     * @var array
     */
    protected array $deferred = [];

    /**
     * Create a new service provider loader
     *
     * @param Container $app
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
        
        // Set the container instance in the global scope
        Container::setInstance($app);
        
        // Also set the provider loader in the container for easy access
        $this->app->providerLoader = $this;
    }

    /**
     * Register all application service providers
     *
     * @return void
     */
    public function register(): void
    {
        $providers = $this->getProviders();

        foreach ($providers as $providerClass) {
            $this->registerProvider($providerClass);
        }
    }

    /**
     * Boot all registered service providers
     *
     * @return void
     */
    public function boot(): void
    {
        foreach ($this->providers as $provider) {
            if (!$provider->isDeferred()) {
                $this->bootProvider($provider);
            }
        }
    }

    /**
     * Register a single service provider
     *
     * @param string $providerClass
     * @return \Mlangeni\Machinjiri\App\Providers\ServiceProvider
     */
    public function registerProvider(string $providerClass): ServiceProvider
    {
        $provider = new $providerClass($this->app);
        
        $this->providers[$providerClass] = $provider;
        
        // Register deferred services
        if ($provider->isDeferred()) {
            $this->registerDeferredServices($provider);
        } else {
            $provider->register();
        }

        return $provider;
    }

    /**
     * Boot a single service provider
     *
     * @param \Mlangeni\Machinjiri\App\Providers\ServiceProvider $provider
     * @return void
     */
    public function bootProvider(ServiceProvider $provider): void
    {
        $providerClass = get_class($provider);
        
        if (!in_array($providerClass, $this->booted)) {
            $provider->boot();
            $this->booted[] = $providerClass;
        }
    }

    /**
     * Load a deferred service provider
     *
     * @param string $service
     * @return void
     */
    public function loadDeferredProvider(string $service): void
    {
        if (isset($this->deferred[$service])) {
            $providerClass = $this->deferred[$service];
            
            if (!isset($this->providers[$providerClass])) {
                $this->registerProvider($providerClass);
            }
            
            if ($this->providers[$providerClass]->isDeferred()) {
                $this->bootProvider($this->providers[$providerClass]);
            }
        }
    }

    /**
     * Register deferred services from a provider
     *
     * @param \Mlangeni\Machinjiri\App\Providers\ServiceProvider $provider
     * @return void
     */
    protected function registerDeferredServices(ServiceProvider $provider): void
    {
        foreach ($provider->provides() as $service) {
            $this->deferred[$service] = get_class($provider);
        }
    }

    /**
     * Get all service providers
     *
     * @return array
     */
    protected function getProviders(): array
    {
        $providerConfig = $this->app->config . 'services/providers.php';
        
        if (file_exists($providerConfig)) {
            $config = require $providerConfig;
            return array_merge(self::getCoreProviders()['providers'], $config['providers'] ?? []);
        }
        
        return [];
    }

    /**
     * Get registered providers
     *
     * @return array
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get booted providers
     *
     * @return array
     */
    public function getBootedProviders(): array
    {
        return $this->booted;
    }

    /**
     * Get deferred services
     *
     * @return array
     */
    public function getDeferredServices(): array
    {
        return $this->deferred;
    }

    /**
     * Clear all providers
     *
     * @return void
     */
    public function clear(): void
    {
        $this->providers = [];
        $this->booted = [];
        $this->deferred = [];
    }

    /**
     * Register Core App providers
     * 
     * @return array
     */
    private static function getCoreProviders(): array 
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Core Providers
            |--------------------------------------------------------------------------
            */

            'providers' => [
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\AppServiceProvider::class,
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\DatabaseServiceProvider::class,
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\QueueServiceProvider::class,
                \Mlangeni\Machinjiri\Core\Providers\CoreProviders\NotificationServiceProvider::class,
            ],
            
            /**
             * Deferred Core Service Providers that are loaded only when needed to
             * improve app performance
             */
            'deffered' => [
                
            ],
        ];
    }
}