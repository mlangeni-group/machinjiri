<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance;

use Mlangeni\Machinjiri\Core\Performance\Collectors\CollectorInterface;
use Mlangeni\Machinjiri\Core\Performance\Metrics\MetricInterface;
use Mlangeni\Machinjiri\Core\Performance\Storage\StorageInterface;

final class Profiler
{
    private static ?self $instance = null;

    private Timer $timer;
    private MemoryTracker $memory;
    /** @var CollectorInterface[] */
    public array $collectors = [];
    /** @var array<string, MetricInterface> */
    private array $metrics = [];
    private ?StorageInterface $storage = null;
    private bool $enabled = true;
    private ?string $runId = null;
    private float $startedAt;

    public function __construct(?Timer $timer = null, ?MemoryTracker $memoryTracker = null)
    {
        $this->timer = $timer ?? new Timer();
        $this->memory = $memoryTracker ?? new MemoryTracker();
        $this->startedAt = microtime(true);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->timer->setEnabled($enabled);
        return $this;
    }

    public function isEnabled(): bool { return $this->enabled; }

    public function setStorage(StorageInterface $storage): self
    {
        $this->storage = $storage;
        return $this;
    }

    public function addCollector(CollectorInterface $collector): self
    {
        $this->collectors[$collector->name()] = $collector;
        return $this;
    }

    public function collector(string $name): ?CollectorInterface
    {
        return $this->collectors[$name] ?? null;
    }

    public function addMetric(MetricInterface $metric): self
    {
        $this->metrics[$metric->name()] = $metric;
        return $this;
    }

    public function metric(string $name): ?MetricInterface
    {
        return $this->metrics[$name] ?? null;
    }

    public function timer(): Timer { return $this->timer; }
    public function memory(): MemoryTracker { return $this->memory; }

    public function start(string $name, string $category = 'default', array $tags = []): Span
    {
        return $this->timer->start($name, $category, $tags);
    }

    public function stop(): ?Span
    {
        return $this->timer->stop();
    }

    public function measure(string $name, callable $fn, string $category = 'default', array $tags = []): mixed
    {
        return $this->timer->measure($name, $fn, $category, $tags);
    }

    public function begin(): void
    {
        if (!$this->enabled) return;
        $this->runId = bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
        $this->start('__request__', 'request');

        foreach ($this->collectors as $c) {
            if (method_exists($c, 'capture')) {
                $c->capture();
            }
        }
    }

    
    public function end(): array
    {
        if (!$this->enabled) {
            return ['enabled' => false];
        }
        $this->stop(); // close root span

        $report = $this->buildReport();

        if ($this->storage !== null) {
            $this->storage->save($report);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    public function buildReport(): array
    {
        $collectorData = [];
        foreach ($this->collectors as $name => $collector) {
            $collectorData[$name] = $collector->collect();
        }

        $metricData = [];
        foreach ($this->metrics as $name => $metric) {
            $metricData[$name] = $metric->snapshot();
        }

        return [
            'id' => $this->runId ?? bin2hex(random_bytes(8)),
            'enabled' => true,
            'started_at' => $this->startedAt,
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000, 3),
            'memory' => $this->memory->toArray(),
            'spans' => $this->timer->toArray(),
            'collectors' => $collectorData,
            'metrics' => $metricData,
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'peak_memory' => memory_get_peak_usage(true),
            ],
        ];
    }

    public function reset(): void
    {
        $this->timer->reset();
        $this->memory = new MemoryTracker();
        $this->startedAt = microtime(true);
        $this->runId = null;
    }
}