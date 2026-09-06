<?php

/**
 * Global Helper Functions for Machinjiri Framework
 * 
 * This file provides global helper functions for easy access to application services.
 */

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Date\DateTimeHandler;
use Mlangeni\Machinjiri\Core\Debug\Dumper;
use Mlangeni\Machinjiri\Core\Debug\Debugger;
use Mlangeni\Machinjiri\Core\Views\{View, Services\AssetManager};
use Mlangeni\Machinjiri\Core\Routing\Router;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;

if (!function_exists('app')) {
    /**
     * Get the application container instance or resolve a service
     *
     * @param string|null $abstract Service name to resolve
     * @return Container|mixed
     */
    function app(?string $abstract = null)
    {
        static $container = null;
        
        // If container is not set, try to get it from global registry
        if ($container === null) {
            // Try to get container from global registry if available
            if (isset($GLOBALS['__machinjiri_container'])) {
                $container = $GLOBALS['__machinjiri_container'];
            }
        }
        
        // If we have a container and asked for a specific service, resolve it
        if ($container !== null && $abstract !== null) {
            return $container->make($abstract);
        }
        
        return $container;
    }
}

if (!function_exists('resolve')) {
    /**
     * Resolve a service from the container
     *
     * @param string $abstract Service name to resolve
     * @param array $parameters Parameters to pass
     * @return mixed
     */
    function resolve(string $abstract, array $parameters = [])
    {
        $container = app();
        
        if ($container === null) {
            throw new MachinjiriException(
                "Application container not initialized. Call app() first.",
                30104
            );
        }
        
        if (method_exists($container, 'make')) {
            return $container->make($abstract, $parameters);
        }
        
        if (method_exists($container, 'resolve')) {
            return $container->resolve($abstract, $parameters);
        }
        
        throw new MachinjiriException(
            "Container does not have a resolve method for: {$abstract}",
            30105
        );
    }
}

if (!function_exists('singleton')) {
    /**
     * Register a singleton in the container
     *
     * @param string $abstract
     * @param mixed $concrete
     * @return void
     */
    function singleton(string $abstract, $concrete = null)
    {
        $container = app();
        
        if ($container === null) {
            throw new MachinjiriException(
                "Application container not initialized.",
                30104
            );
        }
        
        if (method_exists($container, 'singleton')) {
            $container->singleton($abstract, $concrete);
        } elseif (method_exists($container, 'bind')) {
            $container->bind($abstract, $concrete, true);
        }
    }
}

if (!function_exists('bind')) {
    /**
     * Register a binding in the container
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $shared
     * @return void
     */
    function bind(string $abstract, $concrete = null, bool $shared = false)
    {
        $container = app();
        
        if ($container === null) {
            throw new MachinjiriException(
                "Application container not initialized.",
                30104
            );
        }
        
        if (method_exists($container, 'bind')) {
            $container->bind($abstract, $concrete, $shared);
        }
    }
}

if (!function_exists('alias')) {
    /**
     * Register an alias in the container
     *
     * @param string $abstract
     * @param string $alias
     * @return void
     */
    function alias(string $abstract, string $alias)
    {
        $container = app();
        
        if ($container === null) {
            throw new MachinjiriException(
                "Application container not initialized.",
                30104
            );
        }
        
        if (method_exists($container, 'alias')) {
            $container->alias($abstract, $alias);
        }
    }
}

if (!function_exists('service')) {
    /**
     * Get a service provider instance
     *
     * @param string $providerClass
     * @return \Mlangeni\Machinjiri\Core\Providers\ServiceProvider|null
     */
    function service(string $providerClass)
    {
        $container = app();
        
        if ($container === null) {
            return null;
        }
        
        // Try to resolve provider from container
        try {
            return $container->make($providerClass);
        } catch (Exception $e) {
            // Provider not in container, create new instance
            return new $providerClass($container);
        }
    }
}

if (!function_exists('config')) {
    /**
     * Get or set configuration values
     *
     * @param string|array|null $key
     * @param mixed $default
     * @return mixed
     */
    function config($key = null, $default = null)
    {
        $container = app();
        
        if ($container === null) {
            return $default;
        }
        
        // Check if configurations property exists
        if (!property_exists($container, 'configurations') || !isset($container->configurations)) {
            return $default;
        }
        
        if ($key === null) {
            return $container->configurations;
        }
        
        if (is_array($key)) {
            // Set configuration
            foreach ($key as $configKey => $value) {
                $container->configurations[$configKey] = $value;
            }
            return true;
        }
        
        // Get configuration using dot notation
        $keys = explode('.', $key);
        $config = $container->configurations;

        if (count($keys) === 0) {
            return $config[$key] ?? $default;
        }
        
        foreach ($keys as $segment) {
            if (is_array($config) && isset($config[$segment])) {
                $config = $config[$segment];
            } else {
                return $default;
            }
        }
        
        return $config;
    }
}

if (!function_exists('config_path')) {
    /**
     * Get the full path to a configuration file
     *
     * @param string $filename Configuration filename (with or without .php extension)
     * @return string Full path to the configuration file
     */
    function config_path(string $filename = ''): string
    {   
        // Ensure filename has .php extension if not present
        if (!empty($filename) && !str_ends_with($filename, '.php')) {
            $filename .= '.php';
        }

        $configDir = base_path('config');
        
        return empty($filename) ? $configDir : $configDir . DIRECTORY_SEPARATOR . $filename;
    }
}

if (!function_exists('event')) {
    /**
     * Trigger an event or register an event listener
     *
     * @param string $event
     * @param mixed $data
     * @return void|mixed
     */
    function event(string $event, $data = null)
    {
        $container = app();
        
        if ($container === null) {
            return;
        }
        
        static $eventListener = null;
        static $servicesRegistered = false;
        
        // Auto-register event services if not already registered
        if (!$servicesRegistered) {
            // Register Logger if not already bound
            if (!$container->bound(Logger::class)) {
                $container->singleton(Logger::class, function($c) {
                    return new Logger();
                });
            }
            
            // Register EventListener if not already bound
            if (!$container->bound(\Mlangeni\Machinjiri\Core\Artisans\Events\EventListener::class)) {
                $container->singleton(\Mlangeni\Machinjiri\Core\Artisans\Events\EventListener::class, function($c) {
                    $logger = $c->make(Logger::class);
                    return new \Mlangeni\Machinjiri\Core\Artisans\Events\EventListener($logger);
                });
            }
            
            $servicesRegistered = true;
        }
        
        if ($eventListener === null) {
            try {
                $eventListener = $container->make(\Mlangeni\Machinjiri\Core\Artisans\Events\EventListener::class);
            } catch (Exception $e) {
                error_log("Failed to create EventListener: " . $e->getMessage());
                return;
            }
        }
        
        $numArgs = func_num_args();
        
        // If only one parameter (just $event), trigger the event without data
        if ($numArgs === 1) {
            return $eventListener->trigger($event);
        }
        
        // If two parameters
        if ($numArgs === 2) {
            // If second parameter is callable, register listener
            if (is_callable($data)) {
                return $eventListener->on($event, $data);
            }
            
            // Otherwise, trigger event with data
            return $eventListener->trigger($event, $data);
        }
        
        return;
    }
}

if (!function_exists('service_provider')) {
    /**
     * Check if a service provider is registered
     *
     * @param string $providerClass
     * @return bool
     */
    function service_provider(string $providerClass): bool
    {
        $container = app();
        
        if ($container === null) {
            return false;
        }
        
        // Check if provider loader exists
        if (!property_exists($container, 'providerLoader') || !isset($container->providerLoader)) {
            return false;
        }
        
        $loader = $container->providerLoader;
        
        if (method_exists($loader, 'getRegisteredProviders')) {
            $providers = $loader->getRegisteredProviders();
            return in_array($providerClass, $providers);
        }
        
        return false;
    }
}

if (!function_exists('boot_service_provider')) {
    /**
     * Boot a specific service provider
     *
     * @param string $providerClass
     * @return bool
     */
    function boot_service_provider(string $providerClass): bool
    {
        $container = app();
        
        if ($container === null) {
            return false;
        }
        
        // Check if provider loader exists
        if (!property_exists($container, 'providerLoader') || !isset($container->providerLoader)) {
            return false;
        }
        
        $loader = $container->providerLoader;
        
        if (method_exists($loader, 'bootProvider')) {
            try {
                $provider = $container->make($providerClass);
                $loader->bootProvider($provider);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }
}

if (!function_exists('env')) {
    /**
     * Get environment variable value
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the full path to the storage directory or a subdirectory
     *
     * @param string $path Subdirectory or file path within storage
     * @return string Full path
     */
    function storage_path(string $path = ''): string
    {
        $storageDir = base_path('storage');
        
        if (empty($path)) {
            return $storageDir;
        }
        
        // Ensure path doesn't start with directory separator
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        
        return $storageDir . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('datetime_handler')) {
    /**
     * Get current DateTimeHandler instance
     *
     * @param string|null $timezone Timezone string (default: UTC or from config)
     * @return DateTimeHandler
     */
    function datetime_handler(?string $timezone = null): DateTimeHandler
    {
        // Get timezone from parameter, config, or default to UTC
        $tz = $timezone ?? config('app.timezone', 'UTC');
        
        return new DateTimeHandler('now', $tz);
    }
}

if (!function_exists('now')) {
    /**
     * Get current date and time
     *
     * @param string $format
     * @return string
     */
    function now(string $format = ''): string
    {
        if (empty($format)) {
            $format = 'Y-m-d h:i:s';
        }
        return datetime_handler()->format($format);
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the base path of the application
     *
     * @param string $path Subdirectory or file path within base
     * @return string Full path
     */
    function base_path(string $path = ''): string
    {
        $basePath = defined('BASE') ? BASE : getcwd();
        
        if (empty($path)) {
            return $basePath;
        }
        
        // Ensure path doesn't start with directory separator
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        
        return $basePath  . $path;
    }
}

if (!function_exists('app_path')) {
    /**
     * Get the path to the application directory
     *
     * @param string $path Subdirectory or file path within app
     * @return string Full path
     */
    function app_path(string $path = ''): string
    {
        $appDir = base_path('app');
        
        if (empty($path)) {
            return $appDir;
        }
        
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        
        return $appDir . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('public_path')) {
    /**
     * Get the path to the public directory
     *
     * @param string $path Subdirectory or file path within public
     * @return string Full path
     */
    function public_path(string $path = ''): string
    {
        $publicDir = base_path('public');
        
        if (empty($path)) {
            return $publicDir;
        }
        
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        
        return $publicDir . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('resource_path')) {
    /**
     * Get the path to the resources directory
     *
     * @param string $path Subdirectory or file path within resources
     * @return string Full path
     */
    function resource_path(string $path = ''): string
    {
        $resourceDir = base_path('resources');
        
        if (empty($path)) {
            return $resourceDir;
        }
        
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        
        return $resourceDir . DIRECTORY_SEPARATOR . $path;
    }
}

if (!function_exists('dump')) {
    /**
     * Dump the given variables and continue execution.
     *
     * @param mixed ...$args
     * @return void
     */
    function dump(...$args): void
    {
        Dumper::dump(...$args);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump the given variables and end the script.
     *
     * @param mixed ...$args
     * @return never
     */
    function dd(...$args): never
    {
        Dumper::dd(...$args);
    }
}

if (!function_exists('debugger')) {
    /**
     * Get the Debugger instance.
     *
     * @return Debugger
     */
    function debugger(): Debugger
    {
        return Debugger::getInstance();
    }
}

if (!function_exists('debug')) {
    /**
     * Log a debug message.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    function debug($message, array $context = []): void
    {
        debugger()->log($message, 'debug', $context);
    }
}

if (!function_exists('measure')) {
    /**
     * Measure execution time of a callable.
     *
     * @param callable $callback
     * @param string $label
     * @return mixed
     */
    function measure(callable $callback, string $label = 'anonymous')
    {
        return debugger()->measure($callback, $label);
    }
}
if (!function_exists('view')) {
    /**
     * Render a view and return its content.
     *
     * @param string $view
     * @param array $data
     * @return string
     */
    function view(string $view, array $data = []): void
    {
        View::make($view, $data)->display();
    }
}

if (!function_exists('display_view')) {
    /**
     * Render and directly output a view.
     *
     * @param string $view
     * @param array $data
     */
    function display_view(string $view, array $data = []): void
    {
        View::make($view, $data)->display();
    }
}

if (!function_exists('asset')) {
    /**
     * Generate a versioned asset URL.
     *
     * @param string $path
     * @return string
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function asset(string $path): string
    {
        return View::asset($path);
    }
}

if (!function_exists('style')) {
    /**
     * Generate a <link> tag for a CSS asset.
     *
     * @param string $path
     * @param array $attributes
     * @return void
     */
    function style(string $path, array $attributes = []): void
    {
        (new AssetManager())->style($path, $attributes);
    }
}

if (!function_exists('script')) {
    /**
     * Generate a <script> tag for a JavaScript asset.
     *
     * @param string $path
     * @param array $attributes
     * @return string
     */
    function script(string $path, array $attributes = []): void
    {
        (new AssetManager())->script($path, $attributes);
    }
}


if (!function_exists('share')) {
    /**
     * Share data across all views.
     *
     * @param string|array $key
     * @param mixed|null $value
     */
    function share($key, $value = null): void
    {
        View::share($key, $value);
    }
}

// ---- Template tag helpers (for use inside view files) ----

if (!function_exists('section')) {
    /**
     * Start or set a named section.
     *
     * @param string $name
     * @param string|null $content
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function section(string $name, ?string $content = null): void
    {
        View::section($name, $content);
    }
}

if (!function_exists('endSection')) {
    /**
     * End the currently open section.
     *
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function endSection(): void
    {
        View::endSection();
    }
}

if (!function_exists('view_yield')) {
    /**
     * Output the content of a section.
     *
     * @param string $name
     */
    function view_yield(string $name): void
    {
        View::yield($name);
    }
}

if (!function_exists('extend')) {
    /**
     * Specify a layout to extend.
     *
     * @param string $layout
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function extend(string $layout): void
    {
        View::extend($layout);
    }
}

if (!function_exists('include_view')) {
    /**
     * Include a partial view (shortcut for View::include).
     *
     * @param string $view
     * @param array $data
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function include_view(string $view, array $data = []): void
    {
        View::include($view, $data);
    }
}

if (!function_exists('hasSection')) {
    /**
     * Check if a section exists.
     *
     * @param string $name
     * @return bool
     */
    function hasSection(string $name): bool
    {
        return View::hasSection($name);
    }
}

if (!function_exists('getSection')) {
    /**
     * Get the content of a section (without outputting).
     *
     * @param string $name
     * @return string
     */
    function getSection(string $name): string
    {
        return View::getSection($name);
    }
}

if (!function_exists('parent')) {
    /**
     * Output the parent section content (when using inheritance).
     */
    function parent(): void
    {
        View::parent();
    }
}
// Router API Functions
if (!function_exists('route_get')) {
    /**
     * Register a GET route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_get(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::get($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_post')) {
    /**
     * Register a POST route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_post(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::post($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_put')) {
    /**
     * Register a PUT route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_put(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::put($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_delete')) {
    /**
     * Register a DELETE route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_delete(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::delete($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_any')) {
    /**
     * Register a route that responds to any HTTP method.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_any(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::any($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_ajax')) {
    /**
     * Register an AJAX-only route (responds only to XMLHttpRequest).
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_ajax(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::ajax($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_traditional')) {
    /**
     * Register a traditional (non-AJAX) route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @param string|null $name
     * @param array $options
     * @return Router
     */
    function route_traditional(string $pattern, mixed $handler, ?string $name = null, array $options = []): Router
    {
        return Router::traditional($pattern, $handler, $name, $options);
    }
}

if (!function_exists('route_group')) {
    /**
     * Create a route group with shared attributes (prefix, middleware, etc.).
     *
     * @param array $attributes
     * @param callable $callback
     * @return Router
     */
    function route_group(array $attributes, callable $callback): Router
    {
        return Router::group($attributes, $callback);
    }
}

if (!function_exists('route_middleware')) {
    /**
     * Apply middleware to a route or group.
     *
     * @param mixed $middleware
     * @param callable|null $callback
     * @return Router
     */
    function route_middleware(mixed $middleware, ?callable $callback = null): Router
    {
        return Router::middleware($middleware, $callback);
    }
}

if (!function_exists('route_cors')) {
    /**
     * Apply CORS configuration to a route or group.
     *
     * @param array $config
     * @param callable|null $callback
     * @return Router
     */
    function route_cors(array $config = [], ?callable $callback = null): Router
    {
        if ($callback !== null) {
            return Router::cors($config);
        }
        return Router::cors($config);
    }
}

if (!function_exists('base_url')) {
    /**
     * Get the base URL of the application (protocol + host + base path).
     *
     * @return string
     */
    function base_url(): string
    {
        return Router::baseUrl();
    }
}

if (!function_exists('route_url')) {
    /**
     * Generate a URL for a named route.
     *
     * @param string $name
     * @param array $params
     * @return string
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function route_url(string $name, array $params = []): string
    {
        return Router::route($name, $params);
    }
}

if (!function_exists('route_absolute')) {
    /**
     * Generate an absolute URL (including protocol and host) for a named route.
     *
     * @param string $name
     * @param array $params
     * @return string
     */
    function route_absolute(string $name, array $params = []): string
    {
        return Router::absoluteRoute($name, $params);
    }
}

if (!function_exists('dispatch_routes')) {
    /**
     * Dispatch the current request to the matching route.
     *
     * @return void
     */
    function dispatch_routes(): void
    {
        Router::dispatch();
    }
}

if (!function_exists('http_client')) {
    /**
     * Create a new HttpClient instance.
     *
     * @param string $baseUrl
     * @param Session|null $session
     * @param Cookie|null $cookie
     * @return HttpClient
     */
    function http_client(string $baseUrl = '', ?Session $session = null, ?Cookie $cookie = null): HttpClient
    {
        return new HttpClient($baseUrl, $session, $cookie);
    }
}

if (!function_exists('http_get')) {
    /**
     * Execute a GET request.
     *
     * @param string $url
     * @param array $queryParams
     * @param array $options Additional cURL options (e.g., headers, timeout)
     * @return array Response data (see HttpClient::execute)
     */
    function http_get(string $url, array $queryParams = [], array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->get($url, $queryParams);
    }
}

if (!function_exists('http_post')) {
    /**
     * Execute a POST request.
     *
     * @param string $url
     * @param array $data
     * @param bool $isJson Whether to send as JSON (default true)
     * @param array $options Additional cURL options
     * @return array
     */
    function http_post(string $url, array $data = [], bool $isJson = true, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->post($url, $data, $isJson);
    }
}

if (!function_exists('http_put')) {
    /**
     * Execute a PUT request.
     *
     * @param string $url
     * @param array $data
     * @param bool $isJson
     * @param array $options
     * @return array
     */
    function http_put(string $url, array $data = [], bool $isJson = true, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->put($url, $data, $isJson);
    }
}

if (!function_exists('http_patch')) {
    /**
     * Execute a PATCH request.
     *
     * @param string $url
     * @param array $data
     * @param bool $isJson
     * @param array $options
     * @return array
     */
    function http_patch(string $url, array $data = [], bool $isJson = true, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->patch($url, $data, $isJson);
    }
}

if (!function_exists('http_delete')) {
    /**
     * Execute a DELETE request.
     *
     * @param string $url
     * @param array $data Optional JSON body
     * @param array $options
     * @return array
     */
    function http_delete(string $url, array $data = [], array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->delete($url, $data);
    }
}

if (!function_exists('http_head')) {
    /**
     * Execute a HEAD request.
     *
     * @param string $url
     * @param array $options
     * @return array
     */
    function http_head(string $url, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->head($url);
    }
}

if (!function_exists('http_options')) {
    /**
     * Execute an OPTIONS request.
     *
     * @param string $url
     * @param array $options
     * @return array
     */
    function http_options(string $url, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->options($url);
    }
}

if (!function_exists('http_upload_file')) {
    /**
     * Upload a file via multipart/form-data.
     *
     * @param string $url
     * @param string $fieldName
     * @param string $filePath
     * @param array $additionalData
     * @param array $options
     * @return array
     * @throws MachinjiriException
     */
    function http_upload_file(string $url, string $fieldName, string $filePath, array $additionalData = [], array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->uploadFile($url, $fieldName, $filePath, $additionalData);
    }
}

if (!function_exists('http_download_file')) {
    /**
     * Download a file from a URL and save it locally.
     *
     * @param string $url
     * @param string $savePath
     * @param array $options
     * @return array
     * @throws MachinjiriException
     */
    function http_download_file(string $url, string $savePath, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->downloadFile($url, $savePath);
    }
}

if (!function_exists('http_multi_request')) {
    /**
     * Execute multiple requests concurrently.
     *
     * @param array $requests Array where each element has 'url' and optionally 'options' (cURL options for that request)
     * @param array $globalOptions Options to apply to all requests (e.g., timeout)
     * @return array Results keyed by original request keys
     */
    function http_multi_request(array $requests, array $globalOptions = []): array
    {
        $client = http_client();
        apply_options($client, $globalOptions);
        
        // Transform simplified requests into the format expected by multiRequest
        $multiRequests = [];
        foreach ($requests as $key => $req) {
            $multiRequests[$key] = [
                'options' => array_merge(
                    $globalOptions,
                    $req['options'] ?? [],
                    [CURLOPT_URL => $req['url']]
                )
            ];
        }
        return $client->multiRequest($multiRequests);
    }
}

if (!function_exists('apply_options')) {
    /**
     * Apply common configuration options to an HttpClient instance.
     *
     * @param HttpClient $client
     * @param array $options Supported keys:
     *   - headers: array
     *   - timeout: int
     *   - max_redirects: int
     *   - user_agent: string
     *   - referer: string
     *   - basic_auth: [username, password]
     *   - bearer_token: string
     *   - proxy: [proxy, port?, username?, password?]
     *   - ssl: [verify_peer, verify_host, cert_path, key_path]
     *   - retry: [max_retries, retry_delay]
     *   - compress: bool
     *   - cookies: bool|string (true = use memory, string = file path)
     *   - cookie_pairs: array (name => value)
     *   - custom_method: string (GET, POST, etc.)
     *   - capture_headers: bool
     */
    function apply_options(HttpClient $client, array $options): void
    {
        if (isset($options['headers'])) {
            $client->setHeaders($options['headers']);
        }
        if (isset($options['timeout'])) {
            $client->setTimeout($options['timeout']);
        }
        if (isset($options['max_redirects'])) {
            $client->setMaxRedirects($options['max_redirects']);
        }
        if (isset($options['user_agent'])) {
            $client->setUserAgent($options['user_agent']);
        }
        if (isset($options['referer'])) {
            $client->setReferer($options['referer']);
        }
        if (isset($options['basic_auth']) && is_array($options['basic_auth']) && count($options['basic_auth']) >= 2) {
            $client->setBasicAuth($options['basic_auth'][0], $options['basic_auth'][1]);
        }
        if (isset($options['bearer_token'])) {
            $client->setBearerToken($options['bearer_token']);
        }
        if (isset($options['proxy'])) {
            $proxy = $options['proxy'];
            if (is_array($proxy)) {
                $client->setProxy($proxy[0], $proxy[1] ?? null, $proxy[2] ?? null, $proxy[3] ?? null);
            } else {
                $client->setProxy($proxy);
            }
        }
        if (isset($options['ssl']) && is_array($options['ssl'])) {
            $ssl = $options['ssl'];
            $client->setSslOptions(
                $ssl['verify_peer'] ?? true,
                $ssl['verify_host'] ?? 2,
                $ssl['cert_path'] ?? null,
                $ssl['key_path'] ?? null
            );
        }
        if (isset($options['retry']) && is_array($options['retry'])) {
            $client->setRetryOptions($options['retry']['max_retries'] ?? 3, $options['retry']['retry_delay'] ?? 1000);
        }
        if (isset($options['compress']) && $options['compress'] === true) {
            $client->enableCompression();
        }
        if (isset($options['cookies'])) {
            if (is_string($options['cookies'])) {
                $client->enableCookies($options['cookies']);
            } elseif ($options['cookies'] === true) {
                $client->enableCookies();
            }
        }
        if (isset($options['cookie_pairs']) && is_array($options['cookie_pairs'])) {
            foreach ($options['cookie_pairs'] as $name => $value) {
                $client->setCookie($name, $value);
            }
        }
        if (isset($options['custom_method'])) {
            $client->setCustomRequest($options['custom_method']);
        }
        if (isset($options['capture_headers']) && $options['capture_headers'] === true) {
            $client->withHeaderCapture();
        }
    }
}

if (!function_exists('http_api')) {
    /**
     * Execute a POST/GET/PUT/PATCH/DELETE request in one function.
     *
     * @param string $url
     * @param string $method
     * @param array $data
     * @param array $headers
     * @return HttpResponse
     */
    function http_api(string $url, ?string $method = null, $data = null, array $headers = []): HttpResponse
    {
        $request = HttpRequest::createFromGlobals();
        return $request->api($url, $method, $data, $headers);
    }
}