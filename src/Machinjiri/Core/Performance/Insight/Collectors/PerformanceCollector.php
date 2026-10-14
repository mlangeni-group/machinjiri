<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Collectors;

use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\CollectorInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

/**
 * Tracks request-level performance. Designed to be started at the beginning of a
 * request and finished at the end. Also captures DB query counts if a counter
 * has been registered.
 */
final class PerformanceCollector implements CollectorInterface
{
    private float $startTime;

    private int $queryCount = 0;

    private float $queryTime = 0.0;

    public function __construct(
        private readonly ?float $externalStart = null,
    ) {
        $this->startTime = $externalStart ?? microtime(true);
    }

    public function key(): string
    {
        return 'performance';
    }

    public function recordQuery(float $durationMs = 0.0): void
    {
        $this->queryCount++;
        $this->queryTime += $durationMs;
    }

    public function start(): void
    {
        $this->startTime = microtime(true);
    }

    public function collect(MetricBag $bag): void
    {
        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);

        $bag->set('performance.request_duration_ms', $durationMs);
        $bag->set('performance.memory_peak_bytes', memory_get_peak_usage(true));
        $bag->set('performance.memory_peak_mb', round(memory_get_peak_usage(true) / 1048576, 2));
        $bag->set('performance.db_queries', [
            'count'    => $this->queryCount,
            'time_ms'  => round($this->queryTime, 2),
        ]);
    }
}