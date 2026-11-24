<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Database\Utility;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Container;

class DatabaseBackupJob extends BaseJob
{
    public function __construct(Container $app, array $payload = [], array $options = [])
    {
        parent::__construct($app, $payload, array_merge([
            'name' => 'database.backup',
            'queue' => 'database-backups',
            'maxAttempts' => 3,
            'timeout' => 3600,
            'retryDelay' => 60,
        ], $options));
    }

    public function handle(): void
    {
        $payload = $this->getPayload();
        $path = DatabaseBackupManager::fromContainer($this->app)->backup(
            $payload['path'] ?? null,
            (bool) ($payload['overwrite'] ?? false)
        );

        $this->logger->info('Database backup completed', ['path' => $path]);
    }
}
