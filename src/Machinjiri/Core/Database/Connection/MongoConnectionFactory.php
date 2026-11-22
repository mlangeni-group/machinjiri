<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use MongoDB\Client;
use Mlangeni\Machinjiri\Core\Artisans\Adapters\MongoDB\MongoAdapter;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\Connection\State;

/**
 * Lazily builds the singleton MongoDB client.
 */
final class MongoConnectionFactory
{
    public function __construct(private State $state) {}

    public function get(): ?Client
    {
        if ($this->state->mongoConnection !== null) {
            return $this->state->mongoConnection;
        }

        try {
            $cfg = $this->state->databaseCfg->getConnCfg();

            $adapter = new MongoAdapter([
                'host'          => $cfg['host'] ?? 'localhost',
                'port'          => $cfg['port'] ?? 27017,
                'username'      => $cfg['username'] ?? null,
                'password'      => $cfg['password'] ?? null,
                'database'      => $cfg['database'] ?? null,
                'uri'           => $cfg['uri'] ?? null,
                'options'       => $cfg['options'] ?? [],
                'driverOptions' => $cfg['options'] ?? [],
            ]);
            $this->state->mongoConnection = $adapter->connect();
        } catch (DatabaseException $e) {
            $e->show();
        }

        return $this->state->mongoConnection;
    }
}