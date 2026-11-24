<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Database\Utility;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Components\Task\ScheduledTask;
use Mlangeni\Machinjiri\Core\Container;
use RuntimeException;

class DatabaseBackupTask extends ScheduledTask
{
    public function __construct(Container $app)
    {
        parent::__construct($app);

        $config = $app->configurations['database_backup'] ?? [];
        $this->name = 'database-backup';
        $this->cronExpression = $config['schedule'] ?? '0 2 * * *';
        $this->options = [
            'queue' => 'database-backups',
            'timeout' => 3600,
            'maxAttempts' => 3,
        ];
    }

    public function handle(): string
    {
        if (!$this->app->bound('queue.dispatcher')) {
            throw new RuntimeException('queue.dispatcher is not bound; cannot schedule a database backup.');
        }

        $dispatcher = $this->app->resolve('queue.dispatcher');
        if (!$dispatcher instanceof JobDispatcherInterface) {
            throw new RuntimeException('queue.dispatcher must implement JobDispatcherInterface.');
        }

        return $dispatcher->dispatch(new DatabaseBackupJob($this->app));
    }
}
