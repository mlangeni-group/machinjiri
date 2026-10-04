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
use Mlangeni\Machinjiri\Core\Providers\ProviderLoader;
use Throwable;

/**
 * Container
 *
 * Facade over the framework's service container, path registry and
 * configuration loader.
 *
 *  - Path discovery & .env/config loading
 *  - Delegated dependency injection (ServiceContainer)
 *  - Provider registry (view paths, migrations, publishes, commands, middleware)
 *  - Deferred provider resolution hook
 *  - Freeze / scoped-instance management for long-running runtimes
 */
class Container
{
    public const LOG_FILENAME = 'machinjiri';

    /** Application base path (no trailing separator). */
    public static ?string $appBasePath = null;

    private static ?Container $instance = null;

    protected static HttpRequest $httpRequest;

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
    // Mirrors of the underlying ServiceContainer state, bound by
    // reference so reads/writes on either side stay in sync.
    // -----------------------------------------------------------------
    public array $bindings  = [];
    public array $aliases   = [];
    public array $instances = [];

    // -----------------------------------------------------------------
    // Provider-registry state (populated by ServiceProvider subclasses)
    // -----------------------------------------------------------------
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
    /** @var array<string,string> */
    protected array $paths = [];

    /** Backing store for __get/__set of undeclared properties. */
    protected array $dynamicProps = [];

    /** @var array<string,true> */
    protected array $scopedBindings = [];

    protected bool $appEnvironment;
    protected bool $isArtisan = false;
    protected bool $frozen = false;

    protected ?ProviderLoader $providerLoader = null;
    protected Logger $logger;
    protected EventListener $listener;

    protected ServiceContainer $serviceContainer;
    protected ConfigurationLoader $configurationLoader;

    protected ?array $configurationCache = null;

    /**
     * @param string    $appBasePath    Application base path.
     * @param bool|null $appEnvironment True = development, false = production.
     */
    public function __construct(string $appBasePath, ?bool $appEnvironment = null)
    {
        self::$appBasePath = rtrim($appBasePath, DIRECTORY_SEPARATOR);

        // Wire the DI engine first; mirror its arrays by reference so the
        // facade and ServiceContainer stay in lock-step.
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

        // Requires $this->coreConfig (populated by initialize()).
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

    public static function forgetInstance(): void
    {
        self::$instance = null;
        unset($GLOBALS['__machinjiri_container']);
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
        if (!is_dir(self::$appBasePath)) {
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

    public function markAsArtisan(bool $isArtisan = true): void
    {
        $this->isArtisan = $isArtisan;
    }

    // =================================================================
    // Base-path helpers (used by ProviderLoader for caching)
    // =================================================================

    /**
     * Absolute path relative to the application base path.
     */
    public function basePath(string $path = ''): string
    {

        if (function_exists('base_path')) {
            return base_path($path);
        }

        $base = self::$appBasePath ?? $this->getRootPath();
        return $path === ''
            ? $base
            : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    /**
     * Absolute path relative to the config directory.
     */
    public function configPath(string $path = ''): string
    {

        if (function_exists('config_path')) {
            return config_path($path);
        }

        $base = $this->config ?? ($this->basePath('config') . DIRECTORY_SEPARATOR);
        return $path === ''
            ? rtrim($base, '/\\')
            : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    public function bootstrapPath(string $path = ''): string
    {
        $base = $this->bootstrap ?? ($this->basePath('bootstrap') . DIRECTORY_SEPARATOR);
        return $path === ''
            ? rtrim($base, '/\\')
            : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    public function storagePath(string $path = ''): string
    {
        $base = $this->storage ?? ($this->basePath('storage') . DIRECTORY_SEPARATOR);
        return $path === ''
            ? rtrim($base, '/\\')
            : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    // =================================================================
    // Configuration
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
     * Retrieve a loaded configuration section (dot notation optional).
     */
    public function config(string $key, mixed $default = null): mixed
    {
        // Fast path: top-level section already cached by getConfigurations().
        if (array_key_exists($key, $this->configurations)) {
            return $this->configurations[$key];
        }

        // Lazy-load everything, then retry.
        $configs = $this->getConfigurations();

        if (array_key_exists($key, $configs)) {
            return $configs[$key];
        }

        // Dot notation traversal.
        if (str_contains($key, '.')) {
            $value = $configs;
            foreach (explode('.', $key) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }
            return $value;
        }

        return $default;
    }

    /**
     * Merge a config file into the runtime registry.
     *
     * The runtime registry always wins over the file defaults; this makes
     * merges idempotent and preserves values injected by tests or boot
     *
     * @throws MachinjiriException
     */
    public function mergeConfigFrom(string $path, string $key, bool $throw = false): void
    {

        if (!is_file($path)) {
            if ($throw) {
                throw new MachinjiriException("Configuration file for {$key} not found in: {$path}", 30104);
            }
            return;
        }

        $fromFile = require $path;

        if (!is_array($fromFile)) {
            throw new MachinjiriException(
                "Configuration file for {$key} must return an array: {$path}",
                30105
            );
        }

        $existing = $this->configurations[$key] ?? [];

        // File defaults are merged UNDER existing runtime values.
        $this->configurations[$key] = array_replace_recursive($fromFile, $existing);

        // Invalidate the memoised bundle so callers see the new values.
        $this->configurationCache = null;
    }

    public static function dotEnv(): ?array
    {
        return ConfigurationLoader::loadEnv(self::getInstance());
    }

    public static function resolveDebugMode(string $path): bool
    {
        $variables = ConfigurationLoader::loadEnvArray(null, $path);
        $debug     = $variables['APP_DEBUG'] ?? true;

        return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
    }

    // =================================================================
    // Routing / URL helpers
    // =================================================================

    /** @throws MachinjiriException */
    protected function loadRoutes(): void
    {
        $routes = $this->routes . 'web.php';

        if (!is_file($routes)) {
            throw new MachinjiriException('Routing Error: web.php not found in routes/', 109);
        }

        require $routes;
    }

    public static function getSystemTempDir(): string
    {
        $tmpDir = sys_get_temp_dir();
        return rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public static function getRoutingBase(): string
    {
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
        $isHttps  = !empty(self::$httpRequest->getServerParam('HTTPS'))
            && self::$httpRequest->getServerParam('HTTPS') !== 'off';
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

    public function getStoragePath(): ?string
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
        $appConfig = $this->getConfigurations()['app'] ?? [];

        $value = $appConfig['app_maintenance']
            ?? $appConfig['maintenance']
            ?? getenv('APP_MAINTENANCE')
            ?? false;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // =================================================================
    // Dependency injection — delegated
    // =================================================================

    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $this->assertNotFrozen('bind', $abstract);
        $this->serviceContainer->bind($abstract, $concrete, $shared);
    }

    public function singleton(string $abstract, $concrete = null): void
    {
        $this->assertNotFrozen('singleton', $abstract);
        $this->serviceContainer->singleton($abstract, $concrete);
    }

    /**
     * Scoped binding: a singleton within the current request / worker
     * cycle. Cleared by forgetScopedInstances().
     */
    public function scoped(string $abstract, $concrete = null): void
    {
        $this->assertNotFrozen('scoped', $abstract);

        if (method_exists($this->serviceContainer, 'scoped')) {
            $this->serviceContainer->scoped($abstract, $concrete);
        } else {
            // Fall back to a singleton + facade-level tracking.
            $this->serviceContainer->singleton($abstract, $concrete);
        }

        $this->scopedBindings[$abstract] = true;
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $this->assertNotFrozen('instance', $abstract);

        if (method_exists($this->serviceContainer, 'instance')) {
            $this->serviceContainer->instance($abstract, $instance);
        } else {
            $this->instances[$abstract] = $instance;
        }
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->assertNotFrozen('alias', $alias);
        $this->serviceContainer->alias($abstract, $alias);
    }

    /**
     * Resolve a service.
     *
     * Before delegating to the ServiceContainer, gives any deferred
     * provider a chance to register its bindings.
     */
    public function make(string $abstract, array $parameters = [])
    {
        if ($this->providerLoader !== null
            && !$this->serviceContainer->bound($abstract)
            && !isset($this->instances[$abstract])
            && !isset($this->aliases[$abstract])
        ) {
            try {
                $this->providerLoader->loadDeferredProvider($abstract);
            } catch (Throwable $e) {
                // Swallow: fall through so ServiceContainer can still try
                // autowiring; the real error will surface if it fails too.
                $this->logger->debug(
                    'Deferred provider lookup failed for {service}: {error}',
                    ['service' => $abstract, 'error' => $e->getMessage()]
                );
            }
        }

        return $this->serviceContainer->make($abstract, $parameters);
    }

    /**
     * PSR-11-style accessor.
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * PSR-11-style existence check.
     */
    public function has(string $id): bool
    {
        return $this->bound($id) || class_exists($id);
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
        $this->scopedBindings     = [];
        $this->dynamicProps       = [];

        $this->configurationCache = null;
        ConfigurationLoader::clearEnvCache();
    }

    // =================================================================
    // Lifecycle: freeze & scoped instances
    // =================================================================

    /**
     * Prevent further binding mutations after boot.
     *
     * Deferred providers must declare their services via
     * DeferredProviderInterface and be registered before freeze(), or
     * they will not be able to bind at resolve time. Call this from the
     * front controller after ProviderLoader::boot().
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Drop all scoped instances. Call once per request in long-running
     * runtimes (RoadRunner / Swoole / FrankenPHP / Octane-style).
     */
    public function forgetScopedInstances(): void
    {
        foreach (array_keys($this->scopedBindings) as $abstract) {
            unset($this->instances[$abstract]);
        }

        if (method_exists($this->serviceContainer, 'forgetScopedInstances')) {
            $this->serviceContainer->forgetScopedInstances();
        }
    }

    protected function assertNotFrozen(string $op, string $abstract): void
    {
        if (!$this->frozen) {
            return;
        }

        // Deferred providers legitimately bind at resolve time; allow
        // bindings from a currently-registering provider. External code
        // hits the hard stop.
        if ($this->providerLoader !== null) {
            $current = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            foreach ($current as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, __NAMESPACE__ . '\\Providers')) {
                    return;
                }
            }
        }

        throw new MachinjiriException(
            "Container is frozen; cannot {$op} '{$abstract}'.",
            30120
        );
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
            default       => null,
        };
    }

    /** @return array<string,string> */
    public function getPaths(): array
    {
        return $this->paths;
    }

    // =================================================================
    // Provider-registry helpers (called by ServiceProvider subclasses)
    // =================================================================

    public function addViewPath(string $path, ?string $namespace = null): void
    {
        if ($namespace !== null) {
            $this->viewPaths[$namespace] = $path;
        } else {
            if (!in_array($path, $this->viewPaths, true)) {
                $this->viewPaths[] = $path;
            }
        }
    }

    /** @return array<int|string,string> */
    public function getViewPaths(): array
    {
        return $this->viewPaths;
    }

    public function addMigrationPath(string $path): void
    {
        if (!in_array($path, $this->migrationPaths, true)) {
            $this->migrationPaths[] = $path;
        }
    }

    /** @return list<string> */
    public function getMigrationPaths(): array
    {
        return $this->migrationPaths;
    }

    /**
     * @param array<string,string> $assets
     */
    public function addPublishGroup(string $group, array $assets): void
    {
        $this->publishes[$group] = array_merge(
            $this->publishes[$group] ?? [],
            $assets
        );
    }

    /** @return array<string,array<string,string>> */
    public function getPublishes(): array
    {
        return $this->publishes;
    }

    public function addCommands(array $commands): void
    {
        foreach ($commands as $command) {
            if (!in_array($command, $this->commands, true)) {
                $this->commands[] = $command;
            }
        }
    }

    /** @return list<string> */
    public function getCommands(): array
    {
        return $this->commands;
    }

    public function addMiddleware(string|array $middleware, ?string $name = null): void
    {
        if (is_array($middleware)) {
            foreach ($middleware as $key => $value) {
                is_int($key)
                    ? $this->middleware[] = $value
                    : $this->middleware[$key] = $value;
            }
            return;
        }

        if ($name !== null) {
            $this->middleware[$name] = $middleware;
        } else {
            $this->middleware[] = $middleware;
        }
    }

    /** @return array<int|string,string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    // =================================================================
    // Middleware groups / Route model bindings
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

    public function getProviderLoader(): ?ProviderLoader
    {
        return $this->providerLoader;
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

        if (array_key_exists($name, $this->configurations)) {
            return $this->configurations[$name];
        }

        if (array_key_exists($name, $this->dynamicProps)) {
            return $this->dynamicProps[$name];
        }

        throw new MachinjiriException("Property {$name} not found on container.", 111);
    }

    /** @param mixed $value */
    public function __set(string $name, $value): void
    {
        if (str_starts_with($name, 'config.')) {
            $this->configurations[substr($name, 7)] = $value;
            $this->configurationCache = null;
            return;
        }

        if (str_starts_with($name, 'path.')) {
            $this->setPath(substr($name, 5), (string) $value);
            return;
        }

        // No dynamic properties — store in the internal bag.
        $this->dynamicProps[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return $this->bound($name)
            || isset($this->paths[$name])
            || array_key_exists($name, $this->configurations)
            || array_key_exists($name, $this->dynamicProps)
            || property_exists($this, $name);
    }

    public function __unset(string $name): void
    {
        unset($this->dynamicProps[$name]);
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

    /**
     * Instantiate the provider loader, keep a reference for deferred
     * lookups, and run the register + boot cycle.
     */
    private function loadServiceContainer(): void
    {
        $loader = new ProviderLoader($this);
        $this->providerLoader = $loader;

        // The loader's constructor already binds itself via instance();
        if (!$this->bound(ProviderLoader::class)) {
            $this->instance(ProviderLoader::class, $loader);
        }
        if (!$this->bound('providers')) {
            $this->instance('providers', $loader);
        }

        $loader->register();
        $loader->boot();
    }
}