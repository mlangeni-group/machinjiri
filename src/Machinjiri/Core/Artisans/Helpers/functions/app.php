<?php

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

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

if (!function_exists('app_configurations')) {
    /**
     * Get the application configurations from the container
     *
     * @param string $key
     * @return array
     */
    function app_configurations(string $key = '')
    {
        $container = app();
        
        if ($container === null) {
            throw new MachinjiriException(
                "Application container not initialized.",
                30104
            );
        }
        
        if (property_exists($container, 'configurations')) {
            $configurations = $container->configurations;
            if ($key === '') {
                return $configurations;
            }
            return $configurations[$key] ?? [];
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

// Environment Variable loader
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

// Path Functions
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

if (!function_exists('database_path')) {
    /**
     * Get the full path to the database directory or a subdirectory
     *
     * @param string $path Subdirectory or file path within database
     * @return string Full path
     */
    function database_path(string $path = ''): string
    {
        $databaseDir = base_path('database');
        
        if (empty($path)) {
            return $databaseDir;
        }
        
        // Ensure path doesn't start with directory separator
        $path = ltrim($path, DIRECTORY_SEPARATOR);
        
        return $databaseDir . DIRECTORY_SEPARATOR . $path;
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