<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core;

use Closure;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Exceptions\BindingResolutionException;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;

/**
 * Container
 *
 * Facade over the framework's service container, path registry, and
 * configuration loading. Acts as a shared gateway for:
 *  - Application path discovery
 *  - .env & config file loading
 *  - Dependency injection (delegated to ServiceContainer)
 */
#[\AllowDynamicProperties]
class Container
{
    public const LOG_FILENAME = 'machinjiri';

    /** @var string|null Application base path (no trailing separator). */
    public static ?string $appBasePath = null;

    private static ?Container $instance = null;

    // -----------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------
    public ?string $bootstrap   = null;
    public ?string $storage     = null;
    public ?string $routing     = null;
    public ?string $routes      = null;
    public ?string $database    = null;
    public ?string $resources   = null;
    public ?string $app         = null;
    public ?string $config      = null;
    public ?string $coreConfig  = null;
    public ?string $unitTesting = null;
    public ?string $seeders     = null;
    public ?string $factories   = null;

    // -----------------------------------------------------------------
    // Public mirrors of the service container's state.
    //
    // These are bound *by reference* to the corresponding arrays on
    // $serviceContainer, so reads/writes on either side stay in sync.
    // -----------------------------------------------------------------
    public array $bindings  = [];
    public array $aliases   = [];
    public array $instances = [];

    public array $configurations     = [];
    public array $viewPaths          = [];
    public array $migrationPaths     = [];
    public array $publishes          = [];
    public array $commands           = [];
    public array $middleware         = [];
    public array $routeBindings      = [];
    public array $routeModelBindings = [];
    public array $middlewareGroups   = [];

    // -----------------------------------------------------------------
    // Internal state
    // -----------------------------------------------------------------
    protected array $paths = [];
    protected bool $appEnvironment;
    protected bool $isArtisan = false;

    protected ?ProviderLoader $providerLoader = null;
    protected Logger $logger;
    protected EventListener $listener;

    protected ServiceContainer $serviceContainer;
    protected ConfigurationLoader $configurationLoader;

    /** Memoised configuration (loaded lazily on first getConfigurations()). */
    protected ?array $configurationCache = null;

    protected static HttpRequest $httpRequest;

    /**
     * @param string    $appBasePath    Application base path.
     * @param bool|null $appEnvironment True = development, false = production.
     */
    public function __construct(string $appBasePath, ?bool $appEnvironment = null)
    {
        self::$appBasePath = rtrim($appBasePath, DIRECTORY_SEPARATOR);

        // Set up the DI engine first and mirror its arrays into our public
        // properties. From this point on, reads/writes on either side are
        // visible to the other.
        $this->serviceContainer = new ServiceContainer();
        $this->serviceContainer->setFacade($this);
        $this->bindings  = &$this->serviceContainer->bindings;
        $this->aliases   = &$this->serviceContainer->aliases;
        $this->instances = &$this->serviceContainer->instances;

        $this->appEnvironment = $appEnvironment ?? self::resolveDebugMode($appBasePath);

        $this->listener = new EventListener(self::systemLogger(self::LOG_FILENAME, true));
        $this->logger   = self::systemLogger(self::LOG_FILENAME);

        if (self::$instance === null) {
            self::$instance = $this;
        }

        self::$httpRequest = HttpRequest::createFromGlobals();

        $this->initialize();

        // Config loader requires $this->coreConfig, populated by initialize().
        $this->configurationLoader = new ConfigurationLoader($this->coreConfig);

        $this->dbConnect();
        $this->loadServiceContainer();
    }

    // =================================================================
    // Static instance helpers
    // =================================================================

    /** @throws MachinjiriException */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new MachinjiriException(
                'Container not initialized. Create an instance first.',
                100
            );
        }

        return self::$instance;
    }

    public static function setInstance(Container $container): void
    {
        self::$instance = $container;
        $GLOBALS['__machinjiri_container'] = $container;
    }

    public static function instancePresent(): bool
    {
        return self::$instance !== null;
    }

    // =================================================================
    // Bootstrapping
    // =================================================================

    /** @throws MachinjiriException */
    public function initialize(): void
    {
        $this->validateBasePath();
        $this->setupPaths();
    }

    /** @throws MachinjiriException */
    protected function validateBasePath(): void
    {
        if (! is_dir(self::$appBasePath)) {
            throw new MachinjiriException('Specify Application Base', 101);
        }
    }

    protected function setupPaths(): void
    {
        $root = $this->getRootPath();

        $this->bootstrap   = $root . 'bootstrap/';
        $this->routes      = $root . 'routes/';
        $this->resources   = $root . 'resources/';
        $this->database    = $root . 'database/';
        $this->storage     = $root . 'storage/';
        $this->routing     = $root . 'public/';
        $this->app         = $root . 'app/';
        $this->config      = $root . 'config/';
        $this->coreConfig  = $this->config . 'core/';
        $this->unitTesting = $root . 'tests/Unit';
        $this->seeders     = $this->database . 'seeders/';
        $this->factories   = $this->database . 'factories/';

        $this->paths = [
            'bootstrap'   => $this->bootstrap,
            'routes'      => $this->routes,
            'resources'   => $this->resources,
            'database'    => $this->database,
            'storage'     => $this->storage,
            'routing'     => $this->routing,
            'app'         => $this->app,
            'config'      => $this->config,
            'coreConfig'  => $this->coreConfig,
            'unitTesting' => $this->unitTesting,
            'seeders'     => $this->seeders,
            'factories'   => $this->factories,
            'root'        => $root,
        ];
    }

    public function getRootPath(): string
    {
        return $this->isArtisan
            ? self::$appBasePath . DIRECTORY_SEPARATOR
            : self::$appBasePath . '/../';
    }

    /**
     * Flag the container as running under an Artisan (CLI) context.
     * Affects getRootPath() resolution.
     */
    public function markAsArtisan(bool $isArtisan = true): void
    {
        $this->isArtisan = $isArtisan;
    }

    // =================================================================
    // Configuration (memoised)
    // =================================================================

    /**
     * @return array{app: array, database: array}
     * @throws MachinjiriException
     */
    public function getConfigurations(): array
    {
        if ($this->configurationCache !== null) {
            return $this->configurationCache;
        }

        return $this->configurationCache = $this->configurationLoader->load($this);
    }

    /**
     * Static, memoised access to parsed .env values.
     *
     * @return array<string,mixed>|null
     */
    public static function dotEnv(): ?array
    {
        return ConfigurationLoader::loadEnv(self::getInstance());
    }

    // =================================================================
    // Routing / URL helpers
    // =================================================================

    /** @throws MachinjiriException */
    protected function loadRoutes(): void
    {
        $routes = $this->routes . 'web.php';

        if (! is_file($routes)) {
            throw new MachinjiriException('Routing Error: web.php not found in routes/', 109);
        }

        require $routes;
    }

    public static function getSystemTempDir(): string
    {
        return sys_get_temp_dir();
    }

    public static function getRoutingBase(): string
    {
        // Explicit override via APP_BASE_PATH env variable.
        $envVars = self::dotEnv();
        if ($envVars && isset($envVars['APP_BASE_PATH'])) {
            return rtrim($envVars['APP_BASE_PATH'], '/');
        }

        $documentRoot   = rtrim(self::$httpRequest->getServerParam('DOCUMENT_ROOT') ?? '', '/');
        $scriptFilename = self::$httpRequest->getServerParam('SCRIPT_FILENAME') ?? '';
        $scriptName     = self::$httpRequest->getServerParam('SCRIPT_NAME') ?? '';

        if ($scriptFilename === '' || $scriptName === '') {
            return '';
        }

        $scriptDir = dirname($scriptFilename);

        if (str_starts_with($scriptDir, $documentRoot)) {
            $relativePath = substr($scriptDir, strlen($documentRoot));
            $basePath     = rtrim($relativePath, '/');

            if (basename($scriptName) !== 'index.php') {
                $basePath = dirname($scriptName);
                $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
            }

            return $basePath;
        }

        $basePath = dirname($scriptName);
        return $basePath === '/' ? '' : rtrim($basePath, '/');
    }

    public static function getBaseUrl(): string
    {
        $isHttps  = !empty(self::$httpRequest->getServerParam('HTTPS')) && self::$httpRequest->getServerParam('HTTPS') !== 'off';
        $protocol = $isHttps ? 'https' : 'http';
        $host     = self::$httpRequest->getServerParam('HTTP_HOST') ?? 'localhost';

        return $protocol . '://' . $host . self::getRoutingBase();
    }

    public static function getDocumentRoot(): string
    {
        return rtrim(self::$httpRequest->getServerParam('DOCUMENT_ROOT') ?? '', '/');
    }

    // =================================================================
    // Storage helpers
    // =================================================================

    public function getLoggingBase(): string
    {
        return $this->storage . 'logs/';
    }

    public function getStoragePath(): string|null
    {
        return $this->storage;
    }

    public function getCachePath(): string
    {
        return $this->storage . 'cache/';
    }

    public function getRoutesCachePath(): string
    {
        return $this->storage . 'cache/routes/';
    }

    // =================================================================
    // Environment
    // =================================================================

    public function isDevelopment(): bool
    {
        return $this->appEnvironment;
    }

    public function getEnvironment(): string
    {
        return $this->isDevelopment() ? 'development' : 'production';
    }

    public function isDownForMaintenance(): bool
    {
        $appConfig = $this->getConfigurations()['app'];

        $value = $appConfig['app_maintenance']
            ?? $appConfig['maintenance']
            ?? getenv('APP_MAINTENANCE')
            ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // =================================================================
    // Dependency injection — delegates to ServiceContainer
    // =================================================================

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->serviceContainer->bind($abstract, $concrete, $shared);
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->serviceContainer->singleton($abstract, $concrete);
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->serviceContainer->alias($abstract, $alias);
    }

    public function make(string $abstract, array $parameters = [])
    {
        return $this->serviceContainer->make($abstract, $parameters);
    }

    /**
     * @throws BindingResolutionException
     */
    public function resolve(string $abstract, array $parameters = [])
    {
        return $this->serviceContainer->resolve($abstract, $parameters);
    }

    public function bound(string $abstract): bool
    {
        return $this->serviceContainer->bound($abstract);
    }

    public function hasInstance(string $abstract): bool
    {
        return $this->serviceContainer->hasInstance($abstract);
    }

    /** @return string[] */
    public function getBindings(): array
    {
        return $this->serviceContainer->getBindings();
    }

    public function unbind(string $abstract): void
    {
        $this->serviceContainer->unbind($abstract);
    }

    public function flush(): void
    {
        $this->serviceContainer->flush();

        $this->configurations     = [];
        $this->viewPaths          = [];
        $this->migrationPaths     = [];
        $this->publishes          = [];
        $this->commands           = [];
        $this->middleware         = [];
        $this->middlewareGroups   = [];
        $this->routeBindings      = [];
        $this->routeModelBindings = [];

        $this->configurationCache = null;
        ConfigurationLoader::clearEnvCache();
    }

    // =================================================================
    // Paths
    // =================================================================

    public function getPath(string $key): ?string
    {
        return $this->paths[$key] ?? null;
    }

    public function setPath(string $key, string $path): void
    {
        $normalized        = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->paths[$key] = $normalized;

        match ($key) {
            'bootstrap'   => $this->bootstrap   = $normalized,
            'storage'     => $this->storage     = $normalized,
            'routing'     => $this->routing     = $normalized,
            'routes'      => $this->routes      = $normalized,
            'database'    => $this->database    = $normalized,
            'resources'   => $this->resources   = $normalized,
            'app'         => $this->app         = $normalized,
            'config'      => $this->config      = $normalized,
            'coreConfig'  => $this->coreConfig  = $normalized,
            'unitTesting' => $this->unitTesting = $normalized,
            'seeders'     => $this->seeders     = $normalized,
            'factories'   => $this->factories   = $normalized,
            default       => null, // unknown key — still added to $paths above
        };
    }

    /** @return array<string,string> */
    public function getPaths(): array
    {
        return $this->paths;
    }

    // =================================================================
    // Middleware / Route model bindings
    // =================================================================

    public function middlewareGroup(string $name, array $middleware): void
    {
        $this->middlewareGroups[$name] = $middleware;
    }

    public function getMiddlewareGroup(string $name): ?array
    {
        return $this->middlewareGroups[$name] ?? null;
    }

    public function model(string $key, string $model, ?Closure $callback = null): void
    {
        $this->routeModelBindings[$key] = [
            'model'    => $model,
            'callback' => $callback,
        ];
    }

    public function getRouteModelBinding(string $key): ?array
    {
        return $this->routeModelBindings[$key] ?? null;
    }

    // =================================================================
    // Providers
    // =================================================================

    public function registerProvider(string $providerClass): void
    {
        $this->providerLoader?->registerProvider($providerClass);
    }

    public function setProviderLoader(ProviderLoader $loader): void
    {
        $this->providerLoader = $loader;
    }

    // =================================================================
    // Magic methods
    // =================================================================

    public function __get(string $name)
    {
        if ($this->bound($name)) {
            return $this->resolve($name);
        }

        if (isset($this->paths[$name])) {
            return $this->paths[$name];
        }

        if (isset($this->configurations[$name])) {
            return $this->configurations[$name];
        }

        throw new MachinjiriException("Property {$name} not found on container.", 111);
    }

    /** @param mixed $value */
    public function __set(string $name, $value): void
    {
        if (str_starts_with($name, 'config.')) {
            $this->configurations[substr($name, 7)] = $value;
            return;
        }

        if (str_starts_with($name, 'path.')) {
            $this->setPath(substr($name, 5), $value);
            return;
        }

        $this->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return $this->bound($name)
            || isset($this->paths[$name])
            || isset($this->configurations[$name])
            || property_exists($this, $name);
    }

    public function __call(string $method, array $parameters)
    {
        if (str_starts_with($method, 'get')) {
            $serviceName = lcfirst(substr($method, 3));

            if ($this->bound($serviceName)) {
                return $this->resolve($serviceName);
            }
        }

        throw new MachinjiriException("Method {$method} not found on container.", 112);
    }

    // =================================================================
    // Internal services
    // =================================================================

    protected function dbConnect(): void
    {
        $dbLogger = self::systemLogger('database');

        try {
            DatabaseConnection::setPath($this->database);

            $dbConfig = $this->getConfigurations()['database'] ?? [];

            if (empty($dbConfig)) {
                throw new MachinjiriException('Database configuration not found', 113);
            }

            DatabaseConnection::setConfig($dbConfig);
        } catch (MachinjiriException $e) {
            $dbLogger->critical(
                "Connection failed \ndriver => {driver}\nerror => {message}",
                [
                    'driver'  => DatabaseConnection::getDriver(),
                    'message' => $e->getMessage(),
                ]
            );

            $e->show();
        }
    }

    protected static function systemLogger(string $logFile, bool $event = false): Logger
    {
        return LoggerFactory::system($logFile, 'framework', $event);
    }

    private function loadServiceContainer(): void
    {
        $loader = new ProviderLoader($this);
        $loader->register();
        $loader->boot();
    }

    public static function resolveDebugMode(string $path): bool
    {
        $variables = ConfigurationLoader::loadEnvArray(null, $path);
        $debug     = $variables['APP_DEBUG'] ?? true;

        return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
    }
}