<?php

namespace Mlangeni\Machinjiri\Core\FileSystem;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\FtpAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;

class FileSystemManager
{
    protected array $config;
    protected array $disks = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @throws MachinjiriException
     */
    public function disk(?string $name = null): FileSystem
    {
        $name = $name ?: ($this->config['default'] ?? 'local');

        if (!isset($this->disks[$name])) {
            $this->disks[$name] = $this->resolve($name);
        }

        return $this->disks[$name];
    }

    /**
     * @throws MachinjiriException
     */
    protected function resolve(string $name): FileSystem
    {
        $diskConfig = $this->getDiskConfig($name);
        $driver = $diskConfig['driver'] ?? null;

        if (!$driver) {
            throw new MachinjiriException("Disk [{$name}] has no driver configured", 500);
        }

        $adapter = match ($driver) {
            'local' => new LocalAdapter($diskConfig['root'] ?? getcwd()),
            'ftp'   => new FtpAdapter($diskConfig),
            default => throw new MachinjiriException("Unsupported driver: {$driver}", 500),
        };

        return new FileSystem($adapter);
    }

    /**
     * @throws MachinjiriException
     */
    protected function getDiskConfig(string $name): array
    {
        if (!isset($this->config['disks'][$name])) {
            throw new MachinjiriException("Disk [{$name}] is not defined", 500);
        }
        return $this->config['disks'][$name];
    }
}