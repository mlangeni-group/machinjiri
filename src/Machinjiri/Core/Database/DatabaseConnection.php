<?php

namespace Mlangeni\Machinjiri\Core\Database;

use \PDO;
use \PDOStatement;
use \PDOException;
use MongoDB\Client;
use Mlangeni\Machinjiri\Core\Artisans\Adapters\MongoDB\MongoAdapter;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Database\Grammars\{
    Grammar,
    MySqlGrammar,
    PostgresGrammar
};

class DatabaseConnection
{
    private static ?array $config = null;
    private static ?string $path = null;

    /** @var array<string, PDO> Pool of idle connections (key = connection hash) */
    private static array $pool = [];

    /** @var array<string, PDO> Connections currently borrowed */
    private static array $borrowed = [];

    /** @var array<int, PDO> Transactional connections mapped to request/process */
    private static array $transactionConnection = [];

    /** @var bool Whether pooling is enabled */
    private static bool $poolingEnabled = false;

    /** @var int Maximum number of connections in the pool */
    private static int $maxConnections = 10;

    /** @var int Minimum idle connections to keep */
    private static int $minConnections = 2;

    /** @var int Time (seconds) after which an idle connection is closed */
    private static int $idleTimeout = 60;

    /** @var int Maximum seconds to wait for a free connection */
    private static int $waitTimeout = 5;

    /** @var bool Use persistent PDO connections (shared across PHP processes) */
    private static bool $usePersistent = false;

    /** @var MongoDB\Client|null MongoDB singleton connection */
    private static ?Client $mongoConnection = null;

    private function __construct() {}
    private function __clone() {}

    public function __wakeup()
    {
        throw new MachinjiriException("Database Error: Cannot unserialize a singleton.", 200);
    }

    /**
     * Set database configuration
     *
     * @param array $config Configuration array with keys:
     *   - 'driver' (required): mysql, pgsql, sqlite, mongodb, or custom PDO
     *   - 'pool' (optional): array with pooling settings:
     *        enabled (bool), max_connections, min_connections, idle_timeout, wait_timeout, persistent
     *   - Other driver-specific options (host, port, database, username, etc.)
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;

        // Configure pooling if enabled
        $poolCfg = $config['pool'] ?? [];
        self::$poolingEnabled = $poolCfg['enabled'] ?? false;
        self::$maxConnections = $poolCfg['max_connections'] ?? 10;
        self::$minConnections = $poolCfg['min_connections'] ?? 2;
        self::$idleTimeout = $poolCfg['idle_timeout'] ?? 60;
        self::$waitTimeout = $poolCfg['wait_timeout'] ?? 5;
        self::$usePersistent = $poolCfg['persistent'] ?? false;

        // Register shutdown to release all borrowed connections
        register_shutdown_function([self::class, 'shutdown']);
    }

    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    /**
     * Get a database connection from the pool (or create a new one if needed).
     *
     * For MongoDB, returns the singleton client (pooling not applicable).
     * For PDO drivers, returns a connection borrowed from the pool.
     *
     * @return PDO|Client
     * @throws MachinjiriException
     */
    public static function getInstance()
    {
        $driver = self::getDriver();
        if ($driver === 'mongodb') {
            return self::getMongoConnection();
        }

        // For PDO drivers, use the pool
        return self::borrowConnection();
    }

    /**
     * Borrow a PDO connection from the pool.
     */
    private static function borrowConnection(): PDO
    {
        if (!self::$poolingEnabled) {
            // Fallback to a single persistent connection (like old behavior)
            static $simpleConnection = null;
            if ($simpleConnection === null) {
                $simpleConnection = self::createPdoConnection(false);
            }
            return $simpleConnection;
        }

        // Try to get an idle connection
        $conn = self::getIdleConnection();
        if ($conn !== null) {
            self::markBorrowed($conn);
            return $conn;
        }

        // No idle connection: create a new one if under limit
        $totalInUse = count(self::$borrowed);
        if ($totalInUse < self::$maxConnections) {
            $conn = self::createPdoConnection(self::$usePersistent);
            self::markBorrowed($conn);
            return $conn;
        }

        // Wait for a connection to become available
        $start = microtime(true);
        while (microtime(true) - $start < self::$waitTimeout) {
            $conn = self::getIdleConnection();
            if ($conn !== null) {
                self::markBorrowed($conn);
                return $conn;
            }
            usleep(50000); // 50ms
        }

        throw new MachinjiriException(
            "Database Error: No free connection available after {$this->waitTimeout} seconds.",
            201
        );
    }

    /**
     * Return a borrowed connection to the pool.
     *
     * @param PDO $connection
     */
    public static function releaseConnection(PDO $connection): void
    {
        if (!self::$poolingEnabled) {
            return; // nothing to release
        }

        $key = spl_object_hash($connection);
        if (isset(self::$borrowed[$key])) {
            unset(self::$borrowed[$key]);

            // Check if the connection is still alive
            try {
                $connection->query('SELECT 1');
                self::$pool[$key] = $connection;
            } catch (PDOException $e) {
                // Connection is dead, will discard it
            }
        }
    }

    /**
     * Execute a closure with a connection from the pool, automatically releasing it.
     *
     * @param callable $callback function(PDO $conn): mixed
     * @return mixed
     */
    public static function withConnection(callable $callback)
    {
        $conn = self::borrowConnection();
        try {
            return $callback($conn);
        } finally {
            self::releaseConnection($conn);
        }
    }

    /**
     * Execute a prepared SQL statement using a connection from the pool.
     * The connection is released automatically after execution.
     *
     * @return PDOStatement
     */
    public static function executeQuery(string $sql, array $params = []): PDOStatement
    {
        return self::withConnection(function (PDO $conn) use ($sql, $params) {
            try {
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $e) {
                throw new MachinjiriException("Database Error: Query execution failed: " . $e->getMessage(), 202);
            }
        });
    }

    /**
     * Begin a transaction.
     * The connection is held until commit/rollback.
     */
    public static function beginTransaction(): void
    {
        $conn = self::borrowConnection();
        try {
            $conn->beginTransaction();
            // Store the connection as transactional for this request/process
            self::$transactionConnection[spl_object_hash($conn)] = $conn;
        } catch (PDOException $e) {
            self::releaseConnection($conn);
            throw new MachinjiriException("Database Error: Failed to begin transaction: " . $e->getMessage(), 203);
        }
    }

    /**
     * Commit the current transaction and release the connection.
     */
    public static function commit(): void
    {
        $conn = self::getTransactionalConnection();
        try {
            $conn->commit();
        } catch (PDOException $e) {
            throw new MachinjiriException("Database Error: Failed to commit transaction: " . $e->getMessage(), 204);
        } finally {
            self::releaseTransactionalConnection($conn);
        }
    }

    /**
     * Roll back the current transaction and release the connection.
     */
    public static function rollback(): void
    {
        $conn = self::getTransactionalConnection();
        try {
            $conn->rollBack();
        } catch (PDOException $e) {
            throw new MachinjiriException("Database Error: Failed to rollback transaction: " . $e->getMessage(), 205);
        } finally {
            self::releaseTransactionalConnection($conn);
        }
    }

    public static function getDriver(): string
    {
        return self::$config['driver'] ?? '';
    }

    public static function getGrammar(): Grammar
    {
        $driver = self::getDriver();
        return match ($driver) {
            'pgsql' => new PostgresGrammar(),
            default => new MySqlGrammar(),
        };
    }

    /**
     * Clean up all borrowed connections at script end.
     */
    public static function shutdown(): void
    {
        foreach (self::$borrowed as $conn) {
            self::releaseConnection($conn);
        }
        self::$borrowed = [];

        // Close idle connections above min_connections
        if (self::$poolingEnabled && count(self::$pool) > self::$minConnections) {
            $excess = array_slice(self::$pool, self::$minConnections, null, true);
            foreach ($excess as $key => $conn) {
                unset(self::$pool[$key]);
                $conn = null;
            }
        }
    }

    // ------------------- Private helpers -------------------

    private static function getDriverOrFail(): string
    {
        if (self::$config === null) {
            throw new MachinjiriException("Database Error: Database configuration not set. Call setConfig() first.", 206);
        }

        $driver = self::$config['driver'] ?? null;
        if (!$driver) {
            throw new MachinjiriException("Database Error: Database configuration must specify a 'driver'.", 207);
        }
        
        return $driver;
    }

    private static function getIdleConnection(): ?PDO
    {
        // Remove timed-out idle connections
        static $lastCleanup = 0;
        if (time() - $lastCleanup > 30) {
            self::cleanIdleConnections();
            $lastCleanup = time();
        }

        if (empty(self::$pool)) {
            return null;
        }
        // Return any idle connection (FIFO order)
        $key = array_key_first(self::$pool);
        $conn = self::$pool[$key];
        unset(self::$pool[$key]);

        // Verify connection is still alive
        try {
            $conn->query('SELECT 1');
            return $conn;
        } catch (PDOException $e) {
            // Dead connection, discard and try next
            return self::getIdleConnection();
        }
    }

    private static function markBorrowed(PDO $conn): void
    {
        self::$borrowed[spl_object_hash($conn)] = $conn;
    }

    private static function cleanIdleConnections(): void
    {
        foreach (self::$pool as $key => $conn) {
            
        }
    }

    private static function getTransactionalConnection(): PDO
    {
        if (empty(self::$transactionConnection)) {
            throw new MachinjiriException("Database Error: No active transaction to commit/rollback.", 208);
        }
        return reset(self::$transactionConnection);
    }

    private static function releaseTransactionalConnection(PDO $conn): void
    {
        $key = spl_object_hash($conn);
        if (isset(self::$transactionConnection[$key])) {
            unset(self::$transactionConnection[$key]);
            self::releaseConnection($conn);
        }
    }

    private static function getMongoConnection(): ?Client
    {
        if (self::$mongoConnection === null) {
            try {
                $mongoAdapter = new MongoAdapter([
                    'host' => self::$config['host'] ?? 'localhost',
                    'port' => self::$config['port'] ?? 27017,
                    'username' => self::$config['username'] ?? null,
                    'password' => self::$config['password'] ?? null,
                    'database' => self::$config['database'] ?? null,
                    'uri' => self::$config['uri'] ?? null,
                    'options' => self::$config['options'] ?? [],
                    'driverOptions' => self::$config['options'] ?? []
                ]);
                self::$mongoConnection = $mongoAdapter->connect();
            } catch (MachinjiriException $e) {
                $e->show();
            }

        }
        return self::$mongoConnection;
    }

    private static function createPdoConnection(bool $persistent = false): PDO
    {
        $driver = self::getDriverOrFail();
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'])) {
            return self::createCustomPdoConnection($persistent);
        }

        if ($driver === 'sqlite') {
            $database = self::$path;
            if (!is_file(self::$path) && is_dir(self::$path)) {
                $database = self::$path . '/database.sqlite';
                if (!is_file($database)) @fopen($database, 'w');
            }
            $path = (is_file($database)) ? $database : getcwd() . '/database/database.sqlite';
            
            $dsn = "sqlite:{$path}";
            $username = null;
            $password = null;
        } else {
            $required = ['host', 'database', 'username', 'password'];
            foreach ($required as $key) {
                if (!isset(self::$config[$key]) && $key !== 'password') {
                    throw new MachinjiriException("Database Error: Missing required configuration: {$key}", 211);
                }
            }

            $host = self::$config['host'];
            $port = self::$config['port'] ?? ($driver === 'mysql' ? 3306 : 5432);
            $dbname = self::$config['database'];
            $username = self::$config['username'];
            $password = self::$config['password'] ?? '';
            $charset = self::$config['charset'] ?? 'utf8mb4';

            $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname}";
            if ($driver === 'mysql') {
                $dsn .= ";charset={$charset}";
            }
        }

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($persistent && $driver !== 'sqlite') {
            $defaultOptions[PDO::ATTR_PERSISTENT] = true;
        }

        $options = array_replace($defaultOptions, self::$config['options'] ?? []);

        try {
            return new PDO($dsn, $username ?? null, $password ?? null, $options);
        } catch (PDOException $e) {
            throw new MachinjiriException("Database Error: Database connection failed: " . $e->getMessage(), 212);
        }
    }

    private static function createCustomPdoConnection(bool $persistent = false): PDO
    {
        if (empty(self::$config['dsn'])) {
            throw new MachinjiriException("Database Error: Custom PDO driver requires 'dsn' configuration.", 213);
        }

        $username = self::$config['username'] ?? null;
        $password = self::$config['password'] ?? null;

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($persistent) {
            $defaultOptions[PDO::ATTR_PERSISTENT] = true;
        }

        $options = array_replace($defaultOptions, self::$config['options'] ?? []);

        try {
            return new PDO(self::$config['dsn'], $username, $password, $options);
        } catch (PDOException $e) {
            throw new MachinjiriException("Database Error: PDO connection failed: " . $e->getMessage(), 214);
        }
    }
}