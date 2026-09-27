<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Cloud;

use Mlangeni\Machinjiri\Core\FileSystem\Cloud\HttpCloudClient;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\CloudClientInterface;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\FileSystem;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

abstract class CloudAdapter implements FileSystem
{
    public const VISIBILITY_PUBLIC  = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    public const CONFLICT_REPLACE = 'replace';
    public const CONFLICT_RENAME  = 'rename';
    public const CONFLICT_FAIL    = 'fail';

    protected CloudClientInterface $client;
    protected string $root;
    protected ?object $cache;
    protected ?object $logger;

    public function __construct(
        CloudClientInterface $client,
        string $root = '',
        ?object $cache = null,
        ?object $logger = null
    ) {
        $this->client = $client;
        $this->root   = trim(str_replace('\\', '/', $root), '/');
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    public function setCache(?object $cache): static { $this->cache = $cache; return $this; }
    public function setLogger(?object $logger): static { $this->logger = $logger; return $this; }

    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->{$level}($message, $context);
        }
    }

    /* ---------------------------------------------------------------------
     |  Path helpers
     * ------------------------------------------------------------------- */

    /**
     * Normalise a path, collapsing `.` and `..` segments to prevent
     * traversal outside the configured root.
     */
    protected function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        // Collapse segments.
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }

        $clean = implode('/', $segments);

        if ($this->root === '') return $clean;
        return $clean === '' ? $this->root : $this->root . '/' . $clean;
    }

    protected function parent(string $path): string
    {
        $path = rtrim($path, '/');
        $pos  = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    protected function basename(string $path): string
    {
        $path = rtrim($path, '/');
        $pos  = strrpos($path, '/');
        return $pos === false ? $path : substr($path, $pos + 1);
    }

    /* ---------------------------------------------------------------------
     |  FileSystem interface — implemented in terms of primitives
     * ------------------------------------------------------------------- */

    public function read(string $path): string
    {
        return $this->fetchContents($this->normalize($path));
    }

    public function readStream(string $path)
    {
        return $this->fetchStream($this->normalize($path));
    }

    public function write(string $path, string $contents, array $config = []): bool
    {
        $this->uploadContents($this->normalize($path), $contents, $config);
        if (isset($config['visibility'])) {
            $this->setVisibility($path, $config['visibility']);
        }
        return true;
    }

    public function writeStream(string $path, $resource, array $config = []): bool
    {
        $this->uploadStream($this->normalize($path), $resource, $config);
        if (isset($config['visibility'])) {
            $this->setVisibility($path, $config['visibility']);
        }
        return true;
    }

    public function exists(string $path): bool
    {
        return $this->fetchMetadata($this->normalize($path)) !== null;
    }

    public function delete(string $path): bool
    {
        return $this->removePath($this->normalize($path));
    }

    public function move(string $source, string $destination): bool
    {
        return $this->movePath($this->normalize($source), $this->normalize($destination));
    }

    public function copy(string $source, string $destination): bool
    {
        return $this->copyPath($this->normalize($source), $this->normalize($destination));
    }

    public function listContents(string $directory = '', bool $recursive = false): array
    {
        return $this->listPath($this->normalize($directory), $recursive);
    }

    public function size(string $path): int
    {
        $meta = $this->fetchMetadata($this->normalize($path));
        if ($meta === null) $this->fail("File does not exist: {$path}", 404);
        return (int) ($meta['size'] ?? 0);
    }

    public function lastModified(string $path): int
    {
        $meta = $this->fetchMetadata($this->normalize($path));
        if ($meta === null) $this->fail("File does not exist: {$path}", 404);
        return (int) ($meta['lastModified'] ?? 0);
    }

    public function getVisibility(string $path): string
    {
        $meta = $this->fetchMetadata($this->normalize($path));
        if ($meta === null) $this->fail("File does not exist: {$path}", 404);
        return $meta['visibility'] ?? self::VISIBILITY_PRIVATE;
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        if (!in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true)) {
            $this->fail("Invalid visibility: {$visibility}", 400);
        }
        return $this->applyVisibility($this->normalize($path), $visibility);
    }

    /* ---------------------------------------------------------------------
     |  Provider primitives
     * ------------------------------------------------------------------- */

    /** @return array{path:string,type:string,size:int,lastModified:int,visibility:string}|null */
    abstract protected function fetchMetadata(string $path): ?array;

    abstract protected function fetchContents(string $path): string;

    /** @return resource */
    abstract protected function fetchStream(string $path);

    abstract protected function uploadContents(string $path, string $contents, array $config = []): void;

    /** @param resource $resource */
    abstract protected function uploadStream(string $path, $resource, array $config = []): void;

    abstract protected function removePath(string $path): bool;

    abstract protected function movePath(string $source, string $destination): bool;

    abstract protected function copyPath(string $source, string $destination): bool;

    abstract protected function listPath(string $path, bool $recursive): array;

    abstract protected function applyVisibility(string $path, string $visibility): bool;

    /* ---------------------------------------------------------------------
     |  Shared helpers
     * ------------------------------------------------------------------- */

    /** @return resource */
    protected function tempStream(string $contents = '')
    {
        $stream = fopen('php://temp', 'r+b');
        if ($contents !== '') {
            fwrite($stream, $contents);
            rewind($stream);
        }
        return $stream;
    }

    protected function assertOk(CloudResponse $response, string $message): void
    {
        if (!$response->ok()) {
            $detail = $response->body() !== '' ? ' — ' . substr($response->body(), 0, 500) : '';
            $this->fail("{$message} (HTTP {$response->status()}){$detail}", $response->status());
        }
    }

    protected function fail(string $message, int $code = 500): never
    {
        throw new MachinjiriException($message, $code);
    }

    /* ---------------------------------------------------------------------
     |  Cache helpers (PSR-16-shaped; silently no-op when unset)
     * ------------------------------------------------------------------- */

    protected function cacheGet(string $key)
    {
        if (!$this->cache) return null;
        $value = $this->cache->get($key, null);
        return $value === null ? null : $value;
    }

    protected function cacheSet(string $key, $value, int $ttl = 300): void
    {
        $this->cache?->set($key, $value, $ttl);
    }

    protected function cacheForget(string $key): void
    {
        $this->cache?->delete($key);
    }
}