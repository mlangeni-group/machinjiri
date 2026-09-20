<?php

namespace Mlangeni\Machinjiri\Core\FileSystem;

use Mlangeni\Machinjiri\Core\FileSystem\Contracts\FileSystem as FileSystemContract;

class FileSystem implements FileSystemContract
{
    protected FileSystemContract $adapter;

    public function __construct(FileSystemContract $adapter)
    {
        $this->adapter = $adapter;
    }

    public function read(string $path): string
    {
        return $this->adapter->read($path);
    }

    public function readStream(string $path)
    {
        return $this->adapter->readStream($path);
    }

    public function write(string $path, string $contents, array $config = []): bool
    {
        return $this->adapter->write($path, $contents, $config);
    }

    public function writeStream(string $path, $resource, array $config = []): bool
    {
        return $this->adapter->writeStream($path, $resource, $config);
    }

    public function exists(string $path): bool
    {
        return $this->adapter->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->adapter->delete($path);
    }

    public function move(string $source, string $destination): bool
    {
        return $this->adapter->move($source, $destination);
    }

    public function copy(string $source, string $destination): bool
    {
        return $this->adapter->copy($source, $destination);
    }

    public function listContents(string $directory = '', bool $recursive = false): array
    {
        return $this->adapter->listContents($directory, $recursive);
    }

    public function size(string $path): int
    {
        return $this->adapter->size($path);
    }

    public function lastModified(string $path): int
    {
        return $this->adapter->lastModified($path);
    }

    public function getVisibility(string $path): string
    {
        return $this->adapter->getVisibility($path);
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        return $this->adapter->setVisibility($path, $visibility);
    }

    public function getAdapter(): FileSystemContract
    {
        return $this->adapter;
    }
}