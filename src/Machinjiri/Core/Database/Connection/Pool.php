<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use \PDO;
use \PDOException;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\Connection\State;
use Mlangeni\Machinjiri\Core\Database\Connection\Factory;
use Mlangeni\Machinjiri\Core\Database\Connection\Resolver;

/**
 * Manages borrowing and releasing pooled PDO connections.
 */
final class Pool
{
    public function __construct(
        private State $state,
        private Factory $factory,
        private Resolver $resolver
    ) {}

    /**
     * Borrow a connection for the given shard key / read intent.
     * Handles both pooled and unpooled modes.
     */
    public function borrow(string|int|null $shardKey = null, bool $readOnly = false): PDO
    {
        [$config, $routeKey] = $this->resolver->resolve($shardKey, $readOnly);

        if (!$this->state->poolingEnabled) {
            if (!isset($this->state->simpleConnections[$routeKey])) {
                $this->state->simpleConnections[$routeKey] = $this->factory->create(
                    false,
                    $config,
                    $shardKey === null
                );
            }
            return $this->state->simpleConnections[$routeKey];
        }

        // Try idle
        $conn = $this->takeIdle($routeKey);
        if ($conn !== null) {
            $this->markBorrowed($conn, $routeKey);
            return $conn;
        }

        // Under limit?
        if (count($this->state->borrowed) < $this->state->maxConnections) {
            $conn = $this->factory->create(
                $this->state->usePersistent,
                $config,
                $shardKey === null
            );
            $this->markBorrowed($conn, $routeKey);
            return $conn;
        }

        // Wait for one to become free
        $start = microtime(true);
        while (microtime(true) - $start < $this->state->waitTimeout) {
            $conn = $this->takeIdle($routeKey);
            if ($conn !== null) {
                $this->markBorrowed($conn, $routeKey);
                return $conn;
            }
            usleep(50000);
        }

        throw DatabaseException::ConnectionError(
            "No free connection available after " . $this->state->waitTimeout . " seconds."
        );
    }

    /**
     * Return a borrowed connection to the pool (no-op when pooling is off).
     */
    public function release(PDO $connection): void
    {
        if (!$this->state->poolingEnabled) {
            return;
        }

        $key = spl_object_hash($connection);
        if (!isset($this->state->borrowed[$key])) {
            return;
        }

        unset($this->state->borrowed[$key]);

        try {
            $connection->query('SELECT 1');
            $this->state->pool[$key] = $connection;
            $this->state->poolRoutes[$key] = $this->state->borrowedRoutes[$key];
        } catch (PDOException $e) {
            // Dead connection — discard
        } finally {
            unset($this->state->borrowedRoutes[$key]);
        }
    }

    /**
     * Release every borrowed connection at shutdown and trim idle pool.
     */
    public function shutdown(): void
    {
        foreach ($this->state->borrowed as $conn) {
            $this->release($conn);
        }
        $this->state->borrowed = [];

        if ($this->state->poolingEnabled && count($this->state->pool) > $this->state->minConnections) {
            $excess = array_slice($this->state->pool, $this->state->minConnections, null, true);
            foreach ($excess as $key => $conn) {
                unset($this->state->pool[$key], $this->state->poolRoutes[$key]);
                $conn = null;
            }
        }
    }

    // ---- internals ----

    private function takeIdle(string $routeKey): ?PDO
    {
        static $lastCleanup = 0;
        if (time() - $lastCleanup > 30) {
            $this->cleanIdleConnections();
            $lastCleanup = time();
        }

        foreach ($this->state->pool as $key => $conn) {
            if (($this->state->poolRoutes[$key] ?? null) !== $routeKey) {
                continue;
            }

            unset($this->state->pool[$key], $this->state->poolRoutes[$key]);
            try {
                $conn->query('SELECT 1');
                return $conn;
            } catch (PDOException $e) {
                // Discard and keep looking
            }
        }
        return null;
    }

    private function markBorrowed(PDO $conn, string $routeKey): void
    {
        $key = spl_object_hash($conn);
        $this->state->borrowed[$key] = $conn;
        $this->state->borrowedRoutes[$key] = $routeKey;
    }

    private function cleanIdleConnections(): void
    {
        // Preserved as-is from the original (was an empty stub).
        foreach ($this->state->pool as $key => $conn) {
            // TODO: enforce idleTimeout / max lifetime here
        }
    }
}