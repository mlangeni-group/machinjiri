<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Database\Utility;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Container;
use RuntimeException;

class DatabaseRestoreJob extends BaseJob
{
    public function __construct(Container $app, array $payload = [], array $options = [])
    {
        parent::__construct($app, $payload, array_merge([
            'name' => 'database.restore',
            'queue' => 'database-restores',
            'maxAttempts' => 1,
            'timeout' => 3600,
        ], $options));
    }

    public function handle(): void
    {
        $path = $this->getPayload()['path'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('A backup file path is required for database restore.');
        }

        DatabaseBackupManager::fromContainer($this->app)->restore($path);
        $this->logger->info('Database restore completed', ['path' => $path]);
    }
}
