<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use \PDO;
use \PDOException;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\Connection\State;


/**
 * Factory
 */
final class Factory
{
    /**
     * __construct
     *
     * @param State $state
     * @param string $driver
     * @return void
     */
    public function __construct(
        private State $state,
        private string $driver
    ) {}

    /**
     * create
     *
     * @param bool $persistent
     * @param ?array $config
     * @param bool $usePath
     * @return PDO
     */
    public function create(
        bool $persistent = false,
        ?array $config = null,
        bool $usePath = true
    ): PDO {
        $config ??= $this->state->databaseCfg->getConnCfg();

        if (!in_array($this->driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            return $this->createCustom($persistent, $config);
        }

        if ($this->driver === 'sqlite') {
            $database = ($usePath ? $this->state->path : null) ?? $config['database'];
            $dsn = "sqlite:{$database}";
            $username = null;
            $password = null;
        } else {
            $host = $config['host'];
            $port = $config['port'] ?? ($this->driver === 'mysql' ? 3306 : 5432);
            $dbname = $config['database'];
            $username = $config['username'];
            $password = $config['password'] ?? '';
            $charset = $config['charset'] ?? 'utf8mb4';

            $dsn = "{$this->driver}:host={$host};port={$port};dbname={$dbname}";
            if ($this->driver === 'mysql') {
                $dsn .= ";charset={$charset}";
            }
        }

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($persistent && $this->driver !== 'sqlite') {
            $defaultOptions[PDO::ATTR_PERSISTENT] = true;
        }

        $options = array_replace($defaultOptions, $config['options'] ?? []);

        try {
            return new PDO($dsn, $username ?? null, $password ?? null, $options);
        } catch (PDOException $e) {
            throw DatabaseException::ConnectionError("Connection failed: " . $e->getMessage());
        }
    }

    private function createCustom(bool $persistent, array $config): PDO
    {
        if (empty($config['dsn'])) {
            throw DatabaseException::ConnectionError("Custom PDO driver requires 'dsn' configuration.");
        }

        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($persistent) {
            $defaultOptions[PDO::ATTR_PERSISTENT] = true;
        }

        $options = array_replace($defaultOptions, $config['options'] ?? []);

        try {
            return new PDO($config['dsn'], $username, $password, $options);
        } catch (PDOException $e) {
            throw DatabaseException::ConnectionError("PDO connection failed: " . $e->getMessage());
        }
    }
}