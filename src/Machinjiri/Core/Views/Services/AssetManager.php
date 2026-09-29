<?php

namespace Mlangeni\Machinjiri\Core\Views\Services;

use Mlangeni\Machinjiri\Core\Views\Contracts\AssetManagerInterface;
use Mlangeni\Machinjiri\Core\Views\Config\ViewConfig;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class AssetManager implements AssetManagerInterface
{
    /** Bounded mtime cache to avoid unbounded memory growth. */
    protected const TIMESTAMP_CACHE_LIMIT = 512;

    protected static array $assetTimestamps = [];

    public function asset(string $path): string
    {
        if (strpbrk($path, "\0") !== false || strpos($path, '..') !== false) {
            throw new MachinjiriException("Invalid asset path: {$path}");
        }

        $fullPath = ViewConfig::getAssetsPath() . ltrim($path, '/\\');
        if (!is_file($fullPath)) {
            throw new MachinjiriException("Asset file not found: {$fullPath}");
        }

        if (!isset(self::$assetTimestamps[$fullPath])) {
            if (count(self::$assetTimestamps) >= self::TIMESTAMP_CACHE_LIMIT) {
                // Keep the newest half.
                self::$assetTimestamps = array_slice(
                    self::$assetTimestamps,
                    -intdiv(self::TIMESTAMP_CACHE_LIMIT, 2),
                    null,
                    true
                );
            }
            clearstatcache(true, $fullPath);
            self::$assetTimestamps[$fullPath] = filemtime($fullPath) ?: time();
        }

        $url = ViewConfig::getAssetsUrl()
             . ltrim(str_replace('\\', '/', $path), '/');

        // The URL is always freshly built, so no query string exists yet.
        return $url . '?v=' . self::$assetTimestamps[$fullPath];
    }

    public function style(string $path, array $attributes = []): void
    {
        $url   = $this->asset($path);
        $attrs = $this->buildAttributes($attributes);
        printf('<link rel="stylesheet" href="%s"%s>', htmlspecialchars($url, ENT_QUOTES), $attrs);
    }

    public function script(string $path, array $attributes = []): void
    {
        $url   = $this->asset($path);
        $attrs = $this->buildAttributes($attributes);
        printf('<script src="%s"%s></script>', htmlspecialchars($url, ENT_QUOTES), $attrs);
    }

    public function setAssetsPath(string $path): void
    {
        ViewConfig::setAssetsPath($path);
        $this->flushTimestampCache();
    }

    public function setAssetsUrl(string $url): void
    {
        ViewConfig::setAssetsUrl($url);
    }

    public function getAssetsPath(): string
    {
        return ViewConfig::getAssetsPath();
    }

    public function getAssetsUrl(): string
    {
        return ViewConfig::getAssetsUrl();
    }

    public function buildAttributes(array $attributes): string
    {
        $attrs = '';
        foreach ($attributes as $key => $value) {
            if (is_int($key)) {
                $attrs .= ' ' . htmlspecialchars((string)$value, ENT_QUOTES);
            } elseif (is_bool($value)) {
                if ($value) {
                    $attrs .= ' ' . htmlspecialchars((string)$key, ENT_QUOTES);
                }
            } else {
                $attrs .= sprintf(
                    ' %s="%s"',
                    htmlspecialchars((string)$key, ENT_QUOTES),
                    htmlspecialchars((string)$value, ENT_QUOTES)
                );
            }
        }
        return $attrs;
    }

    public function flushTimestampCache(): void
    {
        self::$assetTimestamps = [];
    }
}