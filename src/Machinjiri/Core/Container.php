<?php

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\DotEnv;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\ProviderLoader;

/**
 * Container
 *
 * Core container for the application that is responsible for:
 * - Holding base paths to different parts of the application
 * - Loading configuration and environment variables
 * - Providing utility helpers for routing and storage locations
 * - Service container with dependency injection capabilities
 *
 * This class serves as both a path/configuration manager and a full
 * dependency injection container.
 */
class Container
{
    /**
     * Application base path (set during construction)
     * This should point to the `src` or package path — normalized without trailing separator.
     *
     * @var string
     */
    public static $appBasePath;
    
    /**
     * The global container instance
     *
     * @var Container|null
     */
    private static ?Container $instance = null;
    
    /**
     * Various path holders for bootstrap, storage, routing, etc.
     * These will be populated by setupPaths().
     */
    public $bootstrap;
    public $storage;
    public $routing;
    public $routes;
    public $database;
    public $resources;
    public $app;
    public $config;
    public $coreConfig;
    public $unitTesting;
    public $seeders;
    public $factories;
    
    /**
     * Service container properties
     */
    public array $bindings = [];
    public array $aliases = [];
    public array $instances = [];
    public array $configurations = [];
    public array $viewPaths = [];
    public array $migrationPaths = [];
    public array $publishes = [];
    public array $commands = [];
    public array $middleware = [];
    public array $routeBindings = [];
    public array $routeModelBindings = [];
    public array $middlewareGroups = [];
    
    /**
     * Application paths
     */
    protected array $paths = [];
    
    /**
     * Application environment
     */
    protected $appEnvironment;
    /**
     * is Artisan Applicationironment
     */
    protected $isArtisan;
    
    /**
     * Provider loader instance
     */
    protected ?ProviderLoader $providerLoader = null;

    /**
     * Logger instance used by the application (general purpose).
     *
     * @var Logger
     */
    protected Logger $logger;

    /**
     * Event listener used to trigger and handle application events.
     *
     * @var EventListener
     */
    protected EventListener $listener;

    /**
     * Log filename for framework
     */
    public const LOG_FILENAME = "machinjiri-framework";
    
    /**
     * Container constructor.
     *
     * @param string $appBasePath Application base path — trimmed of trailing directory separator.
     * @param bool $appEnvironment Application environment (true = development, false = production)
     */
    public function __construct(string $appBasePath, ?bool $appEnvironment = null)
    {
        // Normalize and store the base path for later use.
        self::$appBasePath = rtrim($appBasePath, DIRECTORY_SEPARATOR);
        $this->appEnvironment = $appEnvironment ?? self::resolveDebugMode($appBasePath);
        // Prepare an event listener with a dedicated logger for event-related messages
        $this->listener = new EventListener(self::systemLogger(self::LOG_FILENAME, true));
        // Create logger instance
        $this->logger = self::systemLogger(self::LOG_FILENAME);
        // Initialize all array properties to empty arrays
        $this->bindings = [];
        $this->aliases = [];
        $this->instances = [];
        $this->configurations = [];
        $this->viewPaths = [];
        $this->migrationPaths = [];
        $this->publishes = [];
        $this->commands = [];
        $this->middleware = [];
        $this->middlewareGroups = [];
        $this->routeBindings = [];
        $this->routeModelBindings = [];
        $this->paths = [];
        // Set the global instance if not already set
        if (self::$instance === null) {
            self::$instance = $this;
        }
        $this->isArtisan = false;
        $this->initialize();

        $this->loadServiceContainer();
    }
    
    /**
     * Get the global container instance
     *
     * @return static 
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new MachinjiriException(
                "Container not initialized. Create an instance first.",
                100
            );
        }
        
        return self::$instance;
    }
    
    /**
     * Set the global container instance
     *
     * @param Container $container
     * @return void
     */
    public static function setInstance(Container $container): void
    {
        self::$instance = $container;    
        // Also set in global scope for helper functions
        $GLOBALS['__machinjiri_container'] = $container;
    }
    
    /**
     * Check if global instance exists
     *
     * @return bool
     */
    public static function instancePresent(): bool
    {
        return self::$instance !== null;
    }
    
    /**
     * Initialize the container:
     * - Validate base path exists
     * - Setup common paths used by the application
     *
     * @return $this
     * @throws MachinjiriException If base path is not a directory
     */
    public function initialize(): void
    {
        $this->validateBasePath();
        $this->setupPaths();
        $this->dbConnect();
    }
    
    /**
     * Ensure the provided application base path is a directory.
     *
     * @throws MachinjiriException
     * @exception
     */
    protected function validateBasePath(): void
    {
        if (!is_dir(self::$appBasePath)) {
            throw new MachinjiriException("Specify Application Base", 101);
        }
    }
    
    /**
     * Setup and populate application-specific paths for quick reference.
     *
     * This method builds paths relative to the "root" which is derived via getRootPath().
     */
    protected function setupPaths(): void
    {
        $root = $this->getRootPath();

        // Populate various folder paths used across the application.
        $this->bootstrap = $root . "bootstrap/";
        $this->routes = $root . "routes/";
        $this->resources = $root . "resources/";
        $this->database = $root . "database/";
        $this->storage = $root . "storage/";
        $this->routing = $root . "public/";
        $this->app = $root . "app/";
        $this->config = $root . "config/";
        $this->coreConfig = $this->config . "core/";
        $this->unitTesting = $root . "tests/Unit";
        $this->seeders = $this->database . "seeders/";
        $this->factories = $this->database . "factories/";
        
        // Also store in paths array for easy access
        $this->paths = [
            'bootstrap' => $this->bootstrap,
            'routes' => $this->routes,
            'resources' => $this->resources,
            'database' => $this->database,
            'storage' => $this->storage,
            'routing' => $this->routing,
            'app' => $this->app,
            'config' => $this->config,
            'unitTesting' => $this->unitTesting,
            'seeders' => $this->seeders,
            'factories' => $this->factories,
            'root' => $root,
        ];
    }
    
    /**
     * Get root path for the application.
     *
     * The container's app base path is expected to be within the repository structure.
     * This method returns a path one level above the app base path to point to repo root.
     *
     * @return string
     */
    public function getRootPath(): string
    {
        return (!$this->isArtisan) ? self::$appBasePath . "/../" : self::$appBasePath . DIRECTORY_SEPARATOR;
    }
    
    /**
     * Load and return configurations for app and database.
     *
     * If config files are missing or unreadable, fallback to environment variables if present.
     *
     * @return array Associative array with keys 'app' and 'database'
     * @throws MachinjiriException If both configuration files and environment are missing
     */
    public function getConfigurations(): array
    {
        $configDir = $this->coreConfig; // Use core config directory for loading configurations
        $appConfig = $configDir . "app.php";
        $databaseConfig = $configDir . "database.php";
        
        $this->validateConfigurationFiles($appConfig, $databaseConfig);
        
        return [
            'app' => $this->loadAppConfiguration($appConfig),
            'database' => $this->loadDatabaseConfiguration($databaseConfig)
        ];
    }
    
    /**
     * Validate configuration files exist and are readable, or ensure environment variables exist.
     *
     * If both a config file and environment variables are missing/unreadable for either
     * app or database configs, throw a MachinjiriException with a meaningful code.
     *
     * @param string $appConfig Full path to app configuration script
     * @param string $databaseConfig Full path to database configuration script
     * @throws MachinjiriException
     */
    protected function validateConfigurationFiles(string $appConfig, string $databaseConfig): void
    {
        // Load environment variables (if any)
        $envVars = self::dotEnv();
        
        // If there's no readable app.php and no environment variables, bail out
        if ((!is_file($appConfig) || !is_readable($appConfig)) && !$envVars) {
            throw new MachinjiriException(
                "App configuration error. Due to empty or unreadable environment file or no app configuration script in config folder.",
                102
            );
        }
        
        // If there's no readable database.php and no environment variables, bail out
        if ((!is_file($databaseConfig) || !is_readable($databaseConfig)) && !$envVars) {
            throw new MachinjiriException(
                "Database configuration error. Due to empty or unreadable environment file or no database configuration script in config folder.",
                103
            );
        }
    }
    
    /**
     * Load the app configuration file if present; otherwise build config from environment.
     *
     * @param string $configPath Path to the app configuration file
     * @return array
     */
    protected function loadAppConfiguration(string $configPath): array
    {
        // If file exists include it, otherwise fallback to empty array
        $config = is_file($configPath) ? include $configPath : [];
        $envVars = self::dotEnv();
        
        // If the config is not an array but we have env vars, construct a minimal app config
        if (!is_array($config) && $envVars) {
            return [
                "app_name" => $envVars["APP_NAME"] ?? '',
                "app_version" => $envVars["APP_VERSION"] ?? '',
                // NOTE: original key appears to be misnamed 'app_key' pulling APP_DEBUG; preserve behavior
                "app_key" => $envVars["APP_DEBUG"] ?? '',
                "app_env" => $envVars["APP_ENV"] ?? '',
                "app_url" => $envVars["APP_URL"] ?? '',
                "app_maintenance" => $envVars["APP_MAINTENANCE"] ?? false,
            ];
        }
        
        return $config;
    }
    
    /**
     * Load the database configuration file if present; otherwise build config from environment.
     *
     * @param string $configPath Path to the database configuration file
     * @return array
     */
    protected function loadDatabaseConfiguration(string $configPath): array
    {
        // If file exists include it, otherwise fallback to empty array
        $config = is_file($configPath) ? include $configPath : [];
        
        if (!is_array($config)) throw new MachinjiriException("Database Error: Configuration 'database.php' not found in config folder", 104);
        if (empty($config['default'])) throw new MachinjiriException("Database Error: Default driver not specified", 105);
        if (empty($config['connections']) || count($config['connections']) == 0) throw new MachinjiriException("Database Error: no connections defined in config", 106);
        if (isset($config['default']) && !isset($config['connections'][$config['default']])) throw new MachinjiriException("Database Error: The configuration for default driver [{$config['default']}] does not match any in connection configuration", 107);
        if (count($config['connections'][$config['default']]) == 0) throw new MachinjiriException("Database Error: The configuration for default driver [{$config['default']}] is not set.", 108);
        
        $configuration = $config['connections'][$config['default']];
        if (isset($config['pool']) && count($config['pool']) > 0) {
            $configuration['pool'] = $config['pool'];
        }

        return $configuration;
    }
    
    /**
     * Load environment (.env) variables using the DotEnv helper.
     *
     * @return array|null Array of variables if any were loaded, otherwise null
     */
    public static function dotEnv(): ?array
    {
        $dotEnv = new DotEnv(self::getInstance(), true);
        $dotEnv->load();

        // Retrieve parsed variables from DotEnv
        $variables = $dotEnv->getVariables();
        
        // Return null when no variables exist for easier checks elsewhere
        return count($variables) > 0 ? $variables : null;
    }
    
    /**
     * Require the routes file to register web routes.
     *
     * Note: this will execute the routes/web.php script in the configured routes folder.
     */
    protected function loadRoutes(): void
    {
        $routes = $this->routes . "web.php";
        if (!is_file($routes)) throw new MachinjiriException("Routing Error: web.php not found in routes/", 109);
        require $routes;
    }
    
    /**
     * Get the system temporary directory.
     *
     * @return string
     */
    public static function getSystemTempDir(): string
    {
        return sys_get_temp_dir();
    }
    
    /**
     * Get routing base path for the application.
     *
     * This method attempts to determine the application's base URL path by:
     * - Respecting an explicit APP_BASE_PATH environment variable if present
     * - Comparing the script's filesystem path to the server's document root
     * - Falling back to the dirname of SCRIPT_NAME when necessary
     *
     * It returns a normalized path without trailing slash (except for root which is '').
     *
     * @return string Base path portion of the URL (e.g. '/project' or '')
     */
    public static function getRoutingBase(): string
    {
        // Check if we have a custom base path in environment
        $instance = self::getInstance();
        $envVars = $instance->dotEnv();
        if ($envVars && isset($envVars['APP_BASE_PATH'])) {
            return rtrim($envVars['APP_BASE_PATH'], '/');
        }
        
        // Normalize server variables used for detection
        $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        // If required server vars are missing, return empty string (no base)
        if (!$scriptFilename || !$scriptName) {
            return '';
        }

        // Get the directory of the current script on the filesystem
        $scriptDir = dirname($scriptFilename);
        
        // Calculate the base path by finding the difference between script directory and document root
        if (strpos($scriptDir, $documentRoot) === 0) {
            // Script is inside document root — derive the relative path portion
            $relativePath = substr($scriptDir, strlen($documentRoot));
            $basePath = rtrim($relativePath, '/');
            
            // Consider the script name for cases where the entry file is not index.php
            // e.g., when using subfolders and script_name points to a specific file.
            if (basename($scriptName) !== 'index.php') {
                $basePath = dirname($scriptName);
                // Normalize root directory to empty string
                $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
            }
            
            return $basePath;
        }
        
        // Fallback: use the directory portion of SCRIPT_NAME
        $basePath = dirname($scriptName);
        return $basePath === '/' ? '' : rtrim($basePath, '/');
    }
    
    /**
     * Get the full base URL including protocol and host.
     *
     * Uses server globals to determine protocol and host, and appends the routing base.
     *
     * @return string Full base URL (e.g. "https://example.com/project")
     */
    public static function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = self::getRoutingBase();
        
        return $protocol . '://' . $host . $basePath;
    }
    
    /**
     * Return the configured document root from server variables.
     *
     * @return string
     */
    public static function getDocumentRoot(): string
    {
        return rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    }
    
    /**
     * Get the logging base directory (logs folder under storage)
     *
     * @return string
     */
    public function getLoggingBase(): string
    {
        return $this->storage . 'logs/';
    }
    
    /**
     * Get the storage base path configured by the container.
     *
     * @return string
     */
    public function getStoragePath(): string|null
    {
        return $this->storage;
    }
    
    /**
     * Get cache directory under storage.
     *
     * @return string
     */
    public function getCachePath(): string
    {
        return $this->storage . 'cache/';
    }
    
    /**
     * Get path to store cached routes.
     *
     * @return string
     */
    public function getRoutesCachePath(): string
    {
        return $this->storage . 'cache/routes/';
    }
    
    /**
     * Check if application is in development mode
     *
     * @return bool
     */
    public function isDevelopment(): bool
    {
        return $this->appEnvironment;
    }
    
    /**
     * Get application environment
     *
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->isDevelopment() ? 'development' : 'production';
    }
    
    /**
     * Bind a service to the container
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $shared
     * @return void
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $concrete = $concrete ?: $abstract;
        
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
    }
    
    /**
     * Bind a singleton
     *
     * @param string $abstract
     * @param mixed $concrete
     * @return void
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }
    
    /**
     * Register an alias
     *
     * @param string $abstract
     * @param string $alias
     * @return void
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }
    
    /**
     * Make (resolve) a service from the container
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     * @throws MachinjiriException
     */
    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }
    
    /**
     * Resolve a service from the container
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     * @throws MachinjiriException
     */
    public function resolve(string $abstract, array $parameters = [])
    {
        // Check for aliases
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }
        
        // Check if binding exists
        if (isset($this->bindings[$abstract])) {
            $binding = $this->bindings[$abstract];
            
            // If it's a singleton and already instantiated, return the instance
            if ($binding['shared'] && isset($this->instances[$abstract])) {
                return $this->instances[$abstract];
            }
            
            // Resolve the concrete implementation
            $concrete = $binding['concrete'];
            
            if ($concrete instanceof \Closure || is_callable($concrete)) {
                $instance = call_user_func_array($concrete, array_merge([$this], $parameters));
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $instance = new $concrete(...$parameters);
            } else {
                $instance = $concrete;
            }
            
            // Store singleton instance
            if ($binding['shared']) {
                $this->instances[$abstract] = $instance;
            }
            
            return $instance;
        }
        
        // Try to auto-resolve class
        if (class_exists($abstract)) {
            return new $abstract(...$parameters);
        }
        
        throw new MachinjiriException("Unable to resolve service: {$abstract}", 110);
    }
    
    /**
     * Check if a service is bound
     *
     * @param string $abstract
     * @return bool
     */
    public function bound(string $abstract): bool
    {
        // Check aliases first
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }
        
        return isset($this->bindings[$abstract]);
    }
    
    /**
     * Check if a singleton instance exists
     *
     * @param string $abstract
     * @return bool
     */
    public function hasInstance(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }
    
    /**
     * Get all bound services
     *
     * @return array
     */
    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }
    
    /**
     * Remove a binding
     *
     * @param string $abstract
     * @return void
     */
    public function unbind(string $abstract): void
    {
        unset($this->bindings[$abstract]);
        unset($this->instances[$abstract]);
        
        // Remove aliases pointing to this abstract
        foreach ($this->aliases as $alias => $target) {
            if ($target === $abstract) {
                unset($this->aliases[$alias]);
            }
        }
    }
    
    /**
     * Flush all bindings and instances
     *
     * @return void
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->configurations = [];
        $this->viewPaths = [];
        $this->migrationPaths = [];
        $this->publishes = [];
        $this->commands = [];
        $this->middleware = [];
        $this->middlewareGroups = [];
        $this->routeBindings = [];
        $this->routeModelBindings = [];
    }
    
    /**
     * Get a path by key
     *
     * @param string $key
     * @return string|null
     */
    public function getPath(string $key): ?string
    {
        return $this->paths[$key] ?? null;
    }
    
    /**
     * Set a path
     *
     * @param string $key
     * @param string $path
     * @return void
     */
    public function setPath(string $key, string $path): void
    {
        $this->paths[$key] = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        
        // Also set the individual property for backward compatibility
        if (property_exists($this, $key)) {
            $this->{$key} = $this->paths[$key];
        }
    }
    
    /**
     * Get all paths
     *
     * @return array
     */
    public function getPaths(): array
    {
        return $this->paths;
    }
    
    /**
     * Register middleware group
     *
     * @param string $name
     * @param array $middleware
     * @return void
     */
    public function middlewareGroup(string $name, array $middleware): void
    {
        $this->middlewareGroups[$name] = $middleware;
    }
    
    /**
     * Get middleware group
     *
     * @param string $name
     * @return array|null
     */
    public function getMiddlewareGroup(string $name): ?array
    {
        return $this->middlewareGroups[$name] ?? null;
    }
    
    /**
     * Register route model binding
     *
     * @param string $key
     * @param string $model
     * @param \Closure|null $callback
     * @return void
     */
    public function model(string $key, string $model, ?\Closure $callback = null): void
    {
        $this->routeModelBindings[$key] = [
            'model' => $model,
            'callback' => $callback
        ];
    }
    
    /**
     * Get route model binding
     *
     * @param string $key
     * @return array|null
     */
    public function getRouteModelBinding(string $key): ?array
    {
        return $this->routeModelBindings[$key] ?? null;
    }
    
    /**
     * Register a service provider
     *
     * @param string $providerClass
     * @return void
     */
    public function registerProvider(string $providerClass): void
    {
        if ($this->providerLoader) {
            $this->providerLoader->registerProvider($providerClass);
        }
    }
    
    /**
     * Set the provider loader
     *
     * @param ProviderLoader $loader
     * @return void
     */
    public function setProviderLoader(ProviderLoader $loader): void
    {
        $this->providerLoader = $loader;
    }
    
    /**
     * Magic method for dynamic property access
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        // Try to resolve service
        if ($this->bound($name)) {
            return $this->resolve($name);
        }
        
        // Check if it's a path
        if (isset($this->paths[$name])) {
            return $this->paths[$name];
        }
        
        // Check if it's a configuration
        if (isset($this->configurations[$name])) {
            return $this->configurations[$name];
        }
        
        throw new MachinjiriException("Property {$name} not found on container.", 111);
    }
    
    /**
     * Magic method for dynamic property setting
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value): void
    {
        // Allow setting configurations
        if (strpos($name, 'config.') === 0) {
            $configKey = substr($name, 7);
            $this->configurations[$configKey] = $value;
            return;
        }
        
        // Allow setting paths
        if (strpos($name, 'path.') === 0) {
            $pathKey = substr($name, 5);
            $this->setPath($pathKey, $value);
            return;
        }
        
        // Store as property
        $this->{$name} = $value;
    }
    
    /**
     * Magic method for checking property existence
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->bound($name) || 
               isset($this->paths[$name]) || 
               isset($this->configurations[$name]) || 
               property_exists($this, $name);
    }
    
    /**
     * Magic method for method calls
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        // Try to resolve service if method starts with "get"
        if (strpos($method, 'get') === 0) {
            $serviceName = lcfirst(substr($method, 3));
            
            if ($this->bound($serviceName)) {
                return $this->resolve($serviceName);
            }
        }
        
        throw new MachinjiriException("Method {$method} not found on container.", 112);
    }

    /**
     * Establish database connection using framework DatabaseConnection wrapper.
     *
     * This method sets the path and configuration for the DatabaseConnection and triggers
     * an event indicating which driver was connected. On failure, an error is logged and presented.
     *
     * @return void
     */
    protected function dbConnect(): void
    {
        $dbLogger = self::systemLogger('database');
        try {
            DatabaseConnection::setPath($this->database);
            
            // Get database configuration
            $dbConfig = $this->getConfigurations()['database'] ?? [];
            
            if (empty($dbConfig)) {
                throw new MachinjiriException("Database configuration not found", 113);
            }
            
            DatabaseConnection::setConfig($dbConfig);

            // Notify listeners which DB driver is in use
            $this->listener->trigger('db.connected.driver.' . DatabaseConnection::getDriver());
        } catch (MachinjiriException $e) {
            // Log a critical error with context and show the error
            $dbLogger->critical("Connection failed \ndriver => {driver}\nerror => {message}", [
                'driver' => DatabaseConnection::getDriver(),
                'message' => $e->getMessage()
            ]);
            $e->show();
        }
    }

    protected static function systemLogger(string $logFile, bool $event = false): Logger 
    {
        return LoggerFactory::system($logFile, "framework", $event);
    }

    public function isDownForMaintenance(): bool 
    {
        $configuration = $this->getConfigurations()['app'];
        $maintenanceMode = $configuration['app_maintenance'] ?? $configuration['maintenance'] ?? getenv('APP_MAINTENANCE') ?? false;
        return filter_var($maintenanceMode, FILTER_VALIDATE_BOOLEAN);
    }

    private function loadServiceContainer(): void 
    {
        $providerLoader = new ProviderLoader($this);
        $providerLoader->register();
        $providerLoader->boot();
    }

    public static function resolveDebugMode(string $path): bool
    {
        $dotEnv = new DotEnv(null, true, $path)->load()->getVariables();
        $envDebug = $dotEnv['APP_DEBUG'] ?? true;
        return filter_var($envDebug, FILTER_VALIDATE_BOOLEAN);
    }
    
}