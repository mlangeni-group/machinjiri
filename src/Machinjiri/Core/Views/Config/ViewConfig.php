<?php

namespace Mlangeni\Machinjiri\Core\Views\Config;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class ViewConfig
{
    public static array $shared = [];
    public static array $composers = [];
    public static array $namespaces = [];
    public static array $stacks = [];

    public static ?string $basePath = null;
    public static ?string $cachePath = null;
    public static ?string $assetsPath = null;
    public static ?string $assetsUrl = null;

    // ---------------------------------------------------------------------
    // Shared data & composers
    // ---------------------------------------------------------------------

    public static function share(array|string $key, mixed $value = null): void
    {
        if (is_array($key)) {
            self::$shared = array_merge(self::$shared, $key);
        } else {
            self::$shared[$key] = $value;
        }
    }

    public static function composer(string $view, callable $callback): void
    {
        self::$composers[$view] = $callback;
    }

    // ---------------------------------------------------------------------
    // Namespaces
    // ---------------------------------------------------------------------

    public static function addNamespace(string $namespace, string $path): void
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new MachinjiriException("Invalid view namespace path: {$path}");
        }
        self::$namespaces[$namespace] = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }

    // ---------------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------------

    public static function setAssetsPath(string $path): void
    {
        self::$assetsPath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function setAssetsUrl(string $url): void
    {
        self::$assetsUrl = rtrim($url, '/') . '/';
    }

    public static function getAssetsPath(): string
    {
        if (self::$assetsPath === null) {
            $base = rtrim(Container::$appBasePath . '/../', '/\\');
            self::$assetsPath = $base . DIRECTORY_SEPARATOR . 'public'
                              . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        }
        return self::$assetsPath;
    }

    public static function getAssetsUrl(): string
    {
        if (self::$assetsUrl !== null) {
            return self::$assetsUrl;
        }

        $appUrl = function_exists('env') ? (env('ASSET_URL') ?? env('APP_URL')) : null;
        if ($appUrl) {
            return self::$assetsUrl = rtrim($appUrl, '/') . '/src/';
        }

        // CLI / queue workers: no HTTP context.
        if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
            return self::$assetsUrl = '/src/';
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host     = $_SERVER['HTTP_HOST'];
        $script   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir      = rtrim(dirname($script), '/\\');
        $baseDir  = ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;

        return self::$assetsUrl = $protocol . $host . $baseDir . '/src/';
    }

    // ---------------------------------------------------------------------
    // Base path (views)
    // ---------------------------------------------------------------------

    public static function setBasePath(string $path): void
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new MachinjiriException("Invalid view base path: {$path}");
        }
        self::$basePath = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            self::$basePath = Container::$appBasePath . '/../resources/views/';
        }
        return self::$basePath;
    }

    // ---------------------------------------------------------------------
    // Cache path
    // ---------------------------------------------------------------------

    public static function setCachePath(string $path): void
    {
        self::$cachePath = rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function getCachePath(): string
    {
        if (self::$cachePath === null) {
            self::$cachePath = Container::$appBasePath . '/../storage/cache/views/';
        }
        return self::$cachePath;
    }

    // ---------------------------------------------------------------------
    // Stacks (@push / @prepend / @stack)
    // ---------------------------------------------------------------------

    public static function ensureStack(string $name): void
    {
        if (!isset(self::$stacks[$name])) {
            self::$stacks[$name] = ['push' => [], 'prepend' => []];
        }
    }

    public static function flushStacks(): void
    {
        self::$stacks = [];
    }
}