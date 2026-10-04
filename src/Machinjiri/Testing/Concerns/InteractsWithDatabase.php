<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;

trait InteractsWithDatabase
{
    protected function setUpDatabase(): void
    {
        // Use in-memory SQLite by default for tests.
        DatabaseConnection::setConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        DatabaseConnection::getInstance();
    }

    protected function tearDownDatabase(): void
    {
        DatabaseConnection::shutdown();
    }

    protected function assertDatabaseHas(string $table, array $conditions): void
    {
        $qb = new QueryBuilder();
        $result = $qb->select()->from($table)->where($conditions)->first();
        $this->assertNotNull($result, "Failed asserting that table {$table} has matching record.");
    }

    protected function assertDatabaseMissing(string $table, array $conditions): void
    {
        $qb = new QueryBuilder();
        $result = $qb->select()->from($table)->where($conditions)->first();
        $this->assertNull($result, "Failed asserting that table {$table} does NOT have matching record.");
    }
    
    protected function assertDatabaseCount(string $table, int $expected): void
    {
        $qb = new QueryBuilder($table);
        $count = $qb->count();
        $this->assertEquals($expected, $count, "Table {$table} has {$count} records, expected {$expected}.");
    }
    
    protected function assertDeleted(string $model, array $conditions = []): void
    {
        $qb = new QueryBuilder($model::getTable());
        if ($conditions) {
            foreach ($conditions as $col => $val) {
                $qb->where($col, '=', $val);
            }
        }
        $exists = $qb->first() !== null;
        $this->assertFalse($exists, "Model {$model} still exists.");
    }
}