<?php

namespace Mlangeni\Machinjiri\Testing\Traits;

use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;

trait RefreshDatabase
{
    protected function setUpDatabase(): void
    {
        DatabaseConnection::setConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        DatabaseConnection::getInstance();
        $this->beginDatabaseTransaction();
    }

    protected function beginDatabaseTransaction(): void
    {
        DatabaseConnection::beginTransaction();
        $this->beforeApplicationDestroyed(function () {
            DatabaseConnection::rollback();
        });
    }

    protected function beforeApplicationDestroyed(callable $callback): void
    {
        register_shutdown_function($callback);
    }
}
