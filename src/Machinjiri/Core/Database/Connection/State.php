<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use MongoDB\Client;
use Mlangeni\Machinjiri\Core\Database\Config\DatabaseConfiguration;

/**
 * Holds all mutable, process-wide connection state.
 *
 * Extracted from DatabaseConnection so that pooling, transaction and
 * replica-routing collaborators can share it without static coupling.
 */
final class State
{
    private static ?self $instance = null;

    public ?string $path = null;

    /** @var array<string, PDO> Idle connections (key = spl_object_hash) */
    public array $pool = [];

    /** @var array<string, string> Route key per idle pooled connection */
    public array $poolRoutes = [];

    /** @var array<string, PDO> Borrowed connections */
    public array $borrowed = [];

    /** @var array<string, string> Route key per borrowed connection */
    public array $borrowedRoutes = [];

    /** @var array<string, PDO> Unpooled connections keyed by route */
    public array $simpleConnections = [];

    /** @var array<int, PDO> Transactional connections */
    public array $transactionConnections = [];

    public ?string $transactionRouteKey = null;

    /** @var array<string, int> Next replica index by route */
    public array $replicaIndexes = [];

    public bool $poolingEnabled = false;
    public int $maxConnections = 10;
    public int $minConnections = 2;
    public int $idleTimeout = 60;
    public int $waitTimeout = 5;
    public bool $usePersistent = false;

    public ?Client $mongoConnection = null;

    public ?DatabaseConfiguration $databaseCfg = null;

    private function __construct() {}

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset all mutable state (used by tests and shutdown).
     */
    public function reset(): void
    {
        $this->pool = [];
        $this->poolRoutes = [];
        $this->borrowed = [];
        $this->borrowedRoutes = [];
        $this->simpleConnections = [];
        $this->transactionConnections = [];
        $this->transactionRouteKey = null;
        $this->replicaIndexes = [];
        $this->mongoConnection = null;
    }
}