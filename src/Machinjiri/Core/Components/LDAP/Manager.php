<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\LDAP\EntryManager;

class Manager
{
    protected array $config;
    protected array $connections = [];
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;

    private EntryManager $entryManager;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = resolve(Logger::class);
        $this->events = resolve(EventListener::class);
        $this->cache = resolve(CacheManager::class);

        $this->entryManager = new EntryManager(
            $this->connection(),
            $this->logger,
            $this->events
        );

    }

    public function connection(?string $name = null): Connection
    {
        $name = $name ?: $this->config['default'] ?? 'default';

        if (!isset($this->connections[$name])) {
            $connConfig = $this->config['connections'][$name] ?? null;
            if ($connConfig === null) {
                throw new LdapException("LDAP connection [{$name}] not configured.", 500, null, ['connection' => $name]);
            }
            $this->connections[$name] = new Connection(
                $connConfig,
                $this->logger,
                $this->events
            );
        }

        return $this->connections[$name];
    }

    public function __call(string $method, array $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }

    public function create(string $dn, array $attrs): bool
    {
        return $this->entryManager->create($dn, $attrs);
    }

    public function read(string $dn, array $attributes = []): ?Entry
    {
        return $this->entryManager->read($dn, $attributes);
    }

    public function update(string $dn, array $attrs): bool
    {
        return $this->entryManager->update($dn, $attrs);
    }

    public function delete(string $dn): bool
    {
        return $this->entryManager->delete($dn);
    }


}