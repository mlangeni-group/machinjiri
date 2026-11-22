<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\Connection\State;

/**
 * Resolves a concrete connection config (and stable route key) for a
 * given shard key and read/write intent.
 */
final class Resolver
{
    public function __construct(
        private State $state,
        private string $driver
    ) {}

    /**
     * @return array{0: array, 1: string} [config, routeKey]
     */
    public function resolve(string|int|null $shardKey, bool $readOnly): array
    {
        $config = $this->state->databaseCfg->getConnCfg();
        $shards = $config['shards'] ?? [];
        $shardRoute = 'primary';

        if ($shardKey !== null) {
            if (!is_array($shards) || $shards === [] || !array_is_list($shards)) {
                throw DatabaseException::CfgError("A shard key requires 'shards' to be a non-empty list.");
            }
            foreach ($shards as $shard) {
                if (!is_array($shard)) {
                    throw DatabaseException::CfgError("Each database shard must be a connection-options array.");
                }
            }

            $shardIndex = hexdec(substr(hash('sha256', (string) $shardKey), 0, 8)) % count($shards);
            $config = array_replace($config, $shards[$shardIndex]);
            $shardRoute = 'shard:' . $shardIndex;
        }
        unset($config['shards']);

        $replicas = $config['replicas'] ?? [];
        unset($config['replicas']);
        if (!is_array($replicas) || !array_is_list($replicas)) {
            throw DatabaseException::CfgError("Database 'replicas' must be a list of connection-options arrays.");
        }

        if ($readOnly && $replicas !== []) {
            foreach ($replicas as $replica) {
                if (!is_array($replica)) {
                    throw DatabaseException::CfgError("Each database replica must be a connection-options array.");
                }
            }

            $replicaSet = hash('sha256', serialize([$this->driver, $shardRoute, $replicas]));
            $replicaIndex = $this->state->replicaIndexes[$replicaSet] ?? 0;
            $this->state->replicaIndexes[$replicaSet] = ($replicaIndex + 1) % count($replicas);
            $config = array_replace($config, $replicas[$replicaIndex]);
        }

        $routeKey = hash('sha256', serialize([$this->driver, $config]));
        return [$config, $routeKey];
    }
}