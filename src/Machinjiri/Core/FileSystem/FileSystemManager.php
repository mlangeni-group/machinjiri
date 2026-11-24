<?php

namespace Mlangeni\Machinjiri\Core\FileSystem;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\FtpAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Cloud\Adapters\GoogleDrive;
use Mlangeni\Machinjiri\Core\FileSystem\Cloud\Adapters\OneDrive;
use Mlangeni\Machinjiri\Core\FileSystem\Cloud\HttpCloudClient;
use Mlangeni\Machinjiri\Core\FileSystem\Cloud\TokenProviders\GoogleServiceAccountTokenProvider;
use Mlangeni\Machinjiri\Core\FileSystem\Cloud\TokenProviders\GraphClientCredentialsProvider;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\TokenProviderInterface;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;

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
            'local'        => new LocalAdapter($diskConfig['root'] ?? getcwd()),
            'ftp'          => new FtpAdapter($diskConfig),
            'google_drive' => $this->googleDrive($diskConfig),
            'onedrive'     => $this->oneDrive($diskConfig),
            default        => throw new MachinjiriException("Unsupported driver: {$driver}", 500),
        };

        return new FileSystem($adapter);
    }

    private function googleDrive(array $config): GoogleDrive
    {
        $clientEmail = $this->requiredString($config, 'client_email');
        $privateKey = $config['private_key'] ?? null;
        if (!is_string($privateKey) || $privateKey === '') {
            $keyPath = $config['private_key_path'] ?? null;
            if (!is_string($keyPath) || $keyPath === '' || !is_readable($keyPath)) {
                throw new MachinjiriException(
                    'Google Drive disk requires private_key or a readable private_key_path.',
                    500
                );
            }
            $privateKey = file_get_contents($keyPath);
            if ($privateKey === false) {
                throw new MachinjiriException("Unable to read Google Drive private key file '{$keyPath}'.", 500);
            }
        }

        $scope = $config['scope'] ?? 'https://www.googleapis.com/auth/drive';
        if (!is_string($scope) || $scope === '') {
            throw new MachinjiriException('Google Drive scope must be a non-empty string.', 500);
        }

        $provider = new GoogleServiceAccountTokenProvider(
            $clientEmail,
            $privateKey,
            $scope
        );

        return new GoogleDrive(
            $this->cloudClient($provider),
            $this->root($config)
        );
    }

    private function oneDrive(array $config): OneDrive
    {
        $provider = new GraphClientCredentialsProvider(
            $this->requiredString($config, 'tenant_id'),
            $this->requiredString($config, 'client_id'),
            $this->requiredString($config, 'client_secret')
        );

        return new OneDrive(
            $this->cloudClient($provider),
            $this->requiredString($config, 'drive_id'),
            $this->root($config)
        );
    }

    private function root(array $config): string
    {
        $root = $config['root'] ?? '';
        if (!is_string($root)) {
            throw new MachinjiriException('Filesystem disk root must be a string.', 500);
        }

        return $root;
    }

    private function cloudClient(TokenProviderInterface $provider): HttpCloudClient
    {
        return (new HttpCloudClient(new HttpRequest('GET', '/')))
            ->setTokenProvider($provider);
    }

    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MachinjiriException("Cloud filesystem disk requires '{$key}' configuration.", 500);
        }

        return $value;
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