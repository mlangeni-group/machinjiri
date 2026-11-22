<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use \PDO;
use \PDOException;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\Connection\State;
use Mlangeni\Machinjiri\Core\Database\Connection\Pool as ConnectionPool;
use Mlangeni\Machinjiri\Core\Database\Connection\Resolver;

/**
 * Owns the active transaction (one per process/request).
 */
final class TransactionManager
{
    public function __construct(
        private State $state,
        private ConnectionPool $pool,
        private Resolver $resolver
    ) {}

    public function isActive(): bool
    {
        return !empty($this->state->transactionConnections);
    }

    public function connection(): PDO
    {
        if (empty($this->state->transactionConnections)) {
            throw DatabaseException::QueryError("No active transaction to commit/rollback.");
        }
        return reset($this->state->transactionConnections);
    }

    public function routeKey(): ?string
    {
        return $this->state->transactionRouteKey;
    }

    public function begin(string|int|null $shardKey = null): void
    {
        if ($this->isActive()) {
            throw DatabaseException::QueryError("A transaction is already active.");
        }

        [, $routeKey] = $this->resolver->resolve($shardKey, false);
        $conn = $this->pool->borrow($shardKey);

        try {
            $conn->beginTransaction();
            $this->state->transactionConnections[spl_object_hash($conn)] = $conn;
            $this->state->transactionRouteKey = $routeKey;
        } catch (PDOException $e) {
            $this->pool->release($conn);
            throw DatabaseException::QueryError("Failed to begin transaction: " . $e->getMessage());
        }
    }

    public function commit(): void
    {
        $conn = $this->connection();
        try {
            $conn->commit();
        } catch (PDOException $e) {
            throw DatabaseException::QueryError("Failed to commit transaction: " . $e->getMessage());
        } finally {
            $this->release($conn);
        }
    }

    public function rollback(): void
    {
        $conn = $this->connection();
        try {
            $conn->rollBack();
        } catch (PDOException $e) {
            throw DatabaseException::QueryError("Failed to rollback transaction: " . $e->getMessage());
        } finally {
            $this->release($conn);
        }
    }

    private function release(PDO $conn): void
    {
        $key = spl_object_hash($conn);
        if (isset($this->state->transactionConnections[$key])) {
            unset($this->state->transactionConnections[$key]);
            $this->state->transactionRouteKey = null;
            $this->pool->release($conn);
        }
    }
}