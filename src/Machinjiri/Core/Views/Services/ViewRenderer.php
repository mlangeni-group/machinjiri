<?php

namespace Mlangeni\Machinjiri\Core\Views\Services;

use Mlangeni\Machinjiri\Core\Views\Config\ViewConfig;
use Mlangeni\Machinjiri\Core\Views\Contracts\ViewCompilerInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class ViewRenderer
{
    protected ViewCompilerInterface $compiler;

    protected array $extensionMap = [
        'view'     => '.view.php',
        'layout'   => '.layout.php',
        'fragment' => '.frag.php',
    ];

    public function __construct(ViewCompilerInterface $compiler)
    {
        $this->compiler = $compiler;
    }

    /**
     * Resolve a view name to an absolute source path, guarding
     * against path traversal.
     */
    public function resolveViewPath(string $view, string $type): ?string
    {
        $ext = $this->extensionMap[$type] ?? $this->extensionMap['view'];

        // Namespaced view: vendor::path.to.view
        if (strpos($view, '::') !== false) {
            [$namespace, $name] = explode('::', $view, 2);
            if (!isset(ViewConfig::$namespaces[$namespace])) {
                return null;
            }
            $root = ViewConfig::$namespaces[$namespace];
            $rel  = str_replace('.', DIRECTORY_SEPARATOR, $name) . $ext;
            return $this->safeResolve($root, $rel);
        }

        $root = ViewConfig::getBasePath();
        $rel  = str_replace('.', DIRECTORY_SEPARATOR, $view) . $ext;
        return $this->safeResolve($root, $rel);
    }

    /**
     * Join $root and $rel, then verify the real path stays under $root.
     */
    protected function safeResolve(string $root, string $rel): ?string
    {
        $candidate = $root . $rel;
        if (!is_file($candidate)) {
            return null;
        }

        $realRoot = realpath($root);
        $realFile = realpath($candidate);

        if ($realRoot === false || $realFile === false) {
            return null;
        }

        // Ensure the resolved file is inside the root.
        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($realFile, $realRoot, strlen($realRoot)) !== 0) {
            throw new MachinjiriException("View path escapes base directory: {$candidate}");
        }

        return $realFile;
    }

    public function getCacheFilePath(string $sourcePath): string
    {
        return ViewConfig::getCachePath() . md5($sourcePath) . '.php';
    }

    /**
     * Compile (if needed) and execute a view, returning its output.
     */
    public function compileAndInclude(string $view, string $type, array $data): string
    {
        $sourcePath = $this->resolveViewPath($view, $type);
        if (!$sourcePath) {
            throw new MachinjiriException("View file not found: {$view} (type: {$type})");
        }

        $cachePath = ViewConfig::getCachePath();
        if (!is_dir($cachePath) && !mkdir($cachePath, 0755, true) && !is_dir($cachePath)) {
            throw new MachinjiriException("Unable to create view cache directory: {$cachePath}");
        }

        $cacheFile = $this->getCacheFilePath($sourcePath);

        if ($this->needsCompile($sourcePath, $cacheFile)) {
            $this->compileToCache($sourcePath, $cacheFile);
        }

        return $this->evaluate($cacheFile, $data);
    }

    protected function needsCompile(string $source, string $cache): bool
    {
        if (!is_file($cache)) {
            return true;
        }
        $srcTime   = filemtime($source) ?: 0;
        $cacheTime = filemtime($cache)  ?: 0;
        return $srcTime > $cacheTime;
    }

    /**
     * Compile the source and atomically publish the cache file.
     */
    protected function compileToCache(string $source, string $cache): void
    {
        $content  = file_get_contents($source);
        if ($content === false) {
            throw new MachinjiriException("Unable to read view source: {$source}");
        }

        $compiled = $this->compiler->compile($content, $source);

        $tmp = $cache . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($tmp, $compiled, LOCK_EX) === false) {
            @unlink($tmp);
            throw new MachinjiriException("Unable to write view cache: {$tmp}");
        }

        if (!@rename($tmp, $cache)) {
            @unlink($tmp);
            throw new MachinjiriException("Unable to publish view cache: {$cache}");
        }

        @chmod($cache, 0644);
    }

    /**
     * Evaluate a compiled view file with the given data.
     *
     * Internal variables are prefixed with __ to avoid clashing with
     * template variables. EXTR_OVERWRITE lets templates intentionally
     * shadow extracted values, which matches Blade-style behaviour.
     */
    protected function evaluate(string $__viewCacheFile, array $__viewData): string
    {
        return (function (string $__viewCacheFile, array $__viewData): string {
            extract($__viewData, EXTR_OVERWRITE);

            ob_start();
            try {
                include $__viewCacheFile;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return ob_get_clean();
        })($__viewCacheFile, $__viewData);
    }

    // ---------------------------------------------------------------------
    // Cache management (view:clear / view:cache)
    // ---------------------------------------------------------------------

    public function clearCache(): int
    {
        $dir = ViewConfig::getCachePath();
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        foreach (glob($dir . '*.php') ?: [] as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Recursively compile every view under the configured base path.
     * Returns the number of compiled files.
     */
    public function warmCache(?string $subPath = null): int
    {
        $root = $subPath !== null
            ? rtrim(ViewConfig::getBasePath(), '/\\') . DIRECTORY_SEPARATOR . trim($subPath, '/\\')
            : ViewConfig::getBasePath();

        if (!is_dir($root)) {
            return 0;
        }

        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            if (!preg_match('/\.(view|layout|frag)\.php$/', $file->getFilename())) {
                continue;
            }
            $source = $file->getPathname();
            $cache  = $this->getCacheFilePath($source);
            if ($this->needsCompile($source, $cache)) {
                $this->compileToCache($source, $cache);
                $count++;
            }
        }

        return $count;
    }
}