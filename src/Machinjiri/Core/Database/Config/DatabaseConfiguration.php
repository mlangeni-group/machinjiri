<?php

namespace Mlangeni\Machinjiri\Core\Database\Config;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;

class DatabaseConfiguration
{
    /** @var array $config */
    private array $cfg;

    /** @var string $driver */
    private string $driver;

    /** @var array $connCfg */
    private array $connCfg;

    /** @var bool $prefetch */
    private bool $prefetch = false;

    /** @var array $pool */
    private array $pool = [];

    /** @var bool $useMultiple */
    private bool $useMultiple = false;

    /** @var array $supportedDrivers */
    private array $supportedDrivers = ['mysql', 'sqlite', 'psgl', 'custome'];

    public function __construct(private Container $container)
    {
        $this->loadCfg();
    }

    /**
     * @throws DatabaseException
     * @return void
     */
    private function loadCfg(): void 
    {
        $cfg = $this->container->config('database');

        if ($cfg === null) {
            throw DatabaseException::CfgError('Configuration not found');
        }

        if (!is_array($cfg)) {
            throw DatabaseException::CfgError('Invalid configuration provided');
        }

        $this->cfg = $cfg;
        $this->validateCfg();
        return;
    }

    /**
     * @throws DatabaseException
     * @return void
     */
    private function validateCfg(): void
    {
        $driver = $this->cfg['default'] ?? null;
        if ($driver === null || !$this->isSupported($driver)) {
            throw DatabaseException::CfgError('Default driver not set or is not supported');
        }

        if (!isset($this->cfg['connections']) || !is_array($this->cfg['connections'])) {
            throw DatabaseException::CfgError('Connections not set in config/database.php');
        }

        $connections = $this->cfg['connections'];
        if (!isset($connections[$driver]) || !is_array($connections[$driver])) {
            throw DatabaseException::CfgError("Config for {$driver} not set in config/database.php");
        }

        $this->driver = $driver;
        $this->connCfg = $connections[$this->driver];
        $this->prefetch = filter_var($this->cfg['prefetch'] ?? false, FILTER_VALIDATE_BOOL);
        $this->useMultiple = filter_var($this->cfg['connect-multiple'] ?? false, FILTER_VALIDATE_BOOL);
        $this->pool = $this->cfg['pool'] ?? [];
        return;
    }

    /** @return string */
    public function getDriver(): string { return $this->driver; }

    /** @return array */
    public function getConnCfg(): array { return $this->cfg['connections'][$this->driver]; }

    /** @return array */
    public function getCfg(): array { return $this->cfg; }

    /** @return array */
    public function getPoolCfg(): array { return $this->pool; }

    /** @return bool */
    public function useMultipleConns(): bool { return $this->useMultiple; }

    /** @return bool */
    public function prefetch(): bool { return $this->prefetch; }

    /**
     * @throws DatabaseException
     * @return bool
     */
    public function valdateMySQLCfg(): bool
    {
        $conn = $this->connCfg;
        $required = ['driver', 'host', 'username', 'password', 'port', 'database'];
        foreach ($required as $key) {
            if (!isset($conn[$key]) || empty($conn[$key])) {
                throw DatabaseException::CfgError("Config option {$key} for {$driver} in connections cannot be blank in config/database.php");
            }
        }
        return true;
    }

    /**
     * @throws DatabaseException
     * @return bool
     */
    public function valdateSQliteCfg(): bool
    {
        $conn = $this->connCfg;
        if (!isset($conn['database']) || empty($conn['database'])) {
            throw DatabaseException::CfgError("Config option 'database' for {$driver} in connections cannot be blank in config/database.php");
        }
        $database = realpath($conn['database']);
        return is_file($database);
    }

    /**
     * @throws DatabaseException
     * @return self
     */
    public function setDriver(string $driver): self
    {
        if (!isset($this->cfg['default'])) throw DatabaseException::CfgError('default driver not set in config/database.php');
        if (!$this->isSupported($driver)) throw DatabaseException::CfgError("the driver '{$driver}' specified is currently not supported");
        $driver = strtolower($driver);
        $this->cfg['default'] = $this->driver = $driver;
        return $this;
    }

    /**
     * @return bool
     */
    private function isSupported(string $driver): bool 
    {
        if (in_array(strtolower($driver), $this->supportedDrivers)) {
            return true;
        }

        return false;
    }

}