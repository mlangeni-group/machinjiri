<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Collectors;

use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\CollectorInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;
use PDO;
use Throwable;

final class ApplicationHealthCollector implements CollectorInterface
{
    /** @var array<string, callable(): bool> */
    private array $cacheProbes = [];

    /** @var array<string, callable(): array{healthy: bool, workers?: int, message?: string}> */
    private array $queueProbes = [];

    /** @var array<string, PDO|callable(): PDO> */
    private array $dbConnections = [];

    public function key(): string
    {
        return 'health';
    }

    public function registerDatabase(string $name, PDO|callable $connection): self
    {
        $this->dbConnections[$name] = $connection;
        return $this;
    }

    public function registerCache(string $name, callable $probe): self
    {
        $this->cacheProbes[$name] = $probe;
        return $this;
    }

    public function registerQueue(string $name, callable $probe): self
    {
        $this->queueProbes[$name] = $probe;
        return $this;
    }

    public function collect(MetricBag $bag): void
    {
        $bag->set('health.database', $this->databaseHealth());
        $bag->set('health.cache', $this->cacheHealth());
        $bag->set('health.queue', $this->queueHealth());
    }

    /** @return array<string, array{healthy: bool, latency_ms?: float, message?: string}> */
    private function databaseHealth(): array
    {
        $results = [];
        foreach ($this->dbConnections as $name => $connection) {
            $start = microtime(true);
            try {
                $pdo = $connection instanceof PDO ? $connection : $connection();
                $pdo->query('SELECT 1');
                $results[$name] = [
                    'healthy'    => true,
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            } catch (Throwable $e) {
                $results[$name] = [
                    'healthy' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /** @return array<string, array{healthy: bool, latency_ms?: float, message?: string}> */
    private function cacheHealth(): array
    {
        $results = [];
        foreach ($this->cacheProbes as $name => $probe) {
            $start = microtime(true);
            try {
                $healthy = (bool) $probe();
                $results[$name] = [
                    'healthy'    => $healthy,
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            } catch (Throwable $e) {
                $results[$name] = [
                    'healthy' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /** @return array<string, array{healthy: bool, workers?: int, message?: string}> */
    private function queueHealth(): array
    {
        $results = [];
        foreach ($this->queueProbes as $name => $probe) {
            try {
                $results[$name] = $probe();
            } catch (Throwable $e) {
                $results[$name] = [
                    'healthy' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }
}