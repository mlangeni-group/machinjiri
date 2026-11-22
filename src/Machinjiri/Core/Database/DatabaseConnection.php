<?php

namespace Mlangeni\Machinjiri\Core\Database;

use \PDO;
use \PDOStatement;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\Grammars\{Grammar, MySqlGrammar, PostgresGrammar};
use Mlangeni\Machinjiri\Core\Database\Config\DatabaseConfiguration;
use Mlangeni\Machinjiri\Core\Database\Connection\{ State, Resolver, Pool as ConnectionPool, Factory, TransactionManager, PdoQueryRunner, MongoConnectionFactory, QueryClassifier };


/**
 * DatabaseConnection
 */
class DatabaseConnection
{
    private static ?self $instance = null;

    private State                  $state;
    private Resolver               $resolver;
    private Factory                $factory;
    private ConnectionPool         $pool;
    private TransactionManager     $transactions;
    private PdoQueryRunner         $runner;
    private MongoConnectionFactory $mongo;

    private function __clone() {}

    public function __wakeup()
    {
        throw DatabaseException::ConnectionError("Cannot unserialize a singleton.");
    }

    public function __construct(DatabaseConfiguration $dbCfg) { self::$databaseCfg = $dbCfg; }

    /**
     * __construct
     *
     * @param DatabaseConfiguration $dbCfg
     * @return void
     */
    public function __construct(DatabaseConfiguration $dbCfg)
    {
        $this->state = State::get();
        $this->state->databaseCfg = $dbCfg;

        $driver            = $dbCfg->getDriver();
        $this->resolver    = new Resolver($this->state, $driver);
        $this->factory     = new Factory($this->state, $driver);
        $this->pool        = new ConnectionPool($this->state, $this->factory, $this->resolver);
        $this->transactions = new TransactionManager($this->state, $this->pool, $this->resolver);
        $this->runner      = new PdoQueryRunner();
        $this->mongo       = new MongoConnectionFactory($this->state);

        self::$instance = $this;
    }

    // ---- configuration ----

    /**
     * setConfig
     *
     * @param DatabaseConfiguration $dbCfg
     * @return self
     */
    public static function setConfig(DatabaseConfiguration $dbCfg): self
    {
        $state = State::get();
        $state->databaseCfg = $dbCfg;

        $pool = $dbCfg->getPoolCfg();
        $state->poolingEnabled  = $pool['enabled'] ?? false;
        $state->maxConnections  = $pool['max_connections'] ?? 10;
        $state->minConnections  = $pool['min_connections'] ?? 2;
        $state->idleTimeout     = $pool['idle_timeout'] ?? 60;
        $state->waitTimeout     = $pool['wait_timeout'] ?? 5;
        $state->usePersistent   = $pool['persistent'] ?? false;

        register_shutdown_function([self::class, 'shutdown']);

        return new self($dbCfg);
    }

    /**
     * setPath
     *
     * @param string $path
     * @return void
     */
    public static function setPath(string $path): void
    {
        State::get()->path = $path;
    }

    // ---- connection acquisition ----

    /**
     * getInstance
     *
     * @param string|int|null $shardKey
     * @param bool $readOnly
     * @return void
     */
    public static function getInstance(string|int|null $shardKey = null, bool $readOnly = false)
    {
        $self = self::instance();
        if ($self->driver() === 'mongodb') {
            return $self->mongo->get();
        }
        return $self->pool->borrow($shardKey, $readOnly);
    }

    /**
     * releaseConnection
     *
     * @param PDO $connection
     * @return void
     */
    public static function releaseConnection(PDO $connection): void
    {
        self::instance()->pool->release($connection);
    }

    /**
     * withConnection
     *
     * @param callable $callback
     * @param string|int|null $shardKey
     * @param bool $readOnly
     * @return void
     */
    public static function withConnection(
        callable $callback,
        string|int|null $shardKey = null,
        bool $readOnly = false
    ) {
        $self = self::instance();
        $conn = $self->pool->borrow($shardKey, $readOnly);
        try {
            return $callback($conn);
        } finally {
            $self->pool->release($conn);
        }
    }

    // ---- queries ----

    /**
     * executeQuery
     *
     * @param string $sql
     * @param array $params
     * @param string|int|null $shardKey
     * @return PDOStatement
     */
    public static function executeQuery(
        string $sql,
        array $params = [],
        string|int|null $shardKey = null
    ): PDOStatement {
        $self = self::instance();
        $tx   = $self->transactions;

        if ($tx->isActive()) {
            if ($shardKey !== null) {
                [, $routeKey] = $self->resolver->resolve($shardKey, false);
                if ($routeKey !== $tx->routeKey()) {
                    throw DatabaseException::QueryError(
                        "A transaction cannot execute queries on a different shard."
                    );
                }
            }
            return $self->runner->run($tx->connection(), $sql, $params);
        }

        return self::withConnection(
            fn(PDO $conn) => $self->runner->run($conn, $sql, $params),
            $shardKey,
            QueryClassifier::isReadQuery($sql)
        );
    }

    // ---- transactions ----

    /**
     * beginTransaction
     *
     * @param string|int|null $shardKey
     * @return void
     */
    public static function beginTransaction(string|int|null $shardKey = null): void
    {
        self::instance()->transactions->begin($shardKey);
    }

    /**
     * commit
     *
     * @return void
     */
    public static function commit(): void
    {
        self::instance()->transactions->commit();
    }

    /**
     * rollback
     *
     * @return void
     */
    public static function rollback(): void
    {
        self::instance()->transactions->rollback();
    }

    // ---- metadata ----

    /**
     * getDriver
     *
     * @return string
     */
    public static function getDriver(): string
    {
        return State::get()->databaseCfg->getDriver();
    }

    /**
     * getGrammar
     *
     * @return Grammar
     */
    public static function getGrammar(): Grammar
    {
        return match (self::getDriver()) {
            'pgsql' => new PostgresGrammar(),
            default => new MySqlGrammar(),
        };
    }

    /**
     * shutdown
     *
     * @return void
     */
    public static function shutdown(): void
    {
        if (!isset(self::$instance)) {
            return;
        }
        self::$instance->pool->shutdown();
    }

    // ---- internals ----

    /**
     * instance
     *
     * @return self
     */
    private static function instance(): self
    {
        if (self::$instance === null) {
            throw DatabaseException::ConnectionError("DatabaseConnection has not been configured.");
        }
        return self::$instance;
    }

    /**
     * driver
     *
     * @return string
     */
    private function driver(): string
    {
        $driver = $this->state->databaseCfg->getDriver();
        if (!$driver) {
            throw DatabaseException::ConnectionError(
                "Database configuration must specify a 'driver'."
            );
        }
        return $driver;
    }
}