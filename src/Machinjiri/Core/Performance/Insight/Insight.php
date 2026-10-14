<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight;

use Mlangeni\Machinjiri\Core\Performance\Insight\Collectors\ApplicationHealthCollector;
use Mlangeni\Machinjiri\Core\Performance\Insight\Collectors\EnvironmentCollector;
use Mlangeni\Machinjiri\Core\Performance\Insight\Collectors\PerformanceCollector;
use Mlangeni\Machinjiri\Core\Performance\Insight\Collectors\SystemResourceCollector;
use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\CollectorInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\ReporterInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;
use Mlangeni\Machinjiri\Core\Performance\Insight\Reporters\ArrayReporter;
use Mlangeni\Machinjiri\Core\Performance\Insight\Support\Threshold;

final class Insight
{
    /** @var array<string, CollectorInterface> */
    private array $collectors = [];

    private MetricBag $bag;

    public function __construct(
        private ?ReporterInterface $reporter = null,
        bool $withDefaults = true,
    ) {
        $this->bag      = new MetricBag();
        $this->reporter = $reporter ?? new ArrayReporter();

        if ($withDefaults) {
            $this->registerDefaults();
        }
    }

    // ---- Registration -----------------------------------------------------

    public function register(CollectorInterface $collector): self
    {
        $this->collectors[$collector->key()] = $collector;
        return $this;
    }

    public function collector(string $key): ?CollectorInterface
    {
        return $this->collectors[$key] ?? null;
    }

    public function reporter(?ReporterInterface $reporter = null): ReporterInterface
    {
        if ($reporter !== null) {
            $this->reporter = $reporter;
        }
        return $this->reporter;
    }

    public function bag(): MetricBag
    {
        return $this->bag;
    }

    // ---- Thresholds -------------------------------------------------------

    public function threshold(string $metricKey, float|int $warning, float|int $critical, string $unit = ''): self
    {
        $this->bag->setThreshold($metricKey, [
            'warning'  => $warning,
            'critical' => $critical,
            'unit'     => $unit,
        ]);
        return $this;
    }

    // ---- Execution --------------------------------------------------------

    /**
     * Run all registered collectors and return the raw MetricBag.
     */
    public function capture(): MetricBag
    {
        foreach ($this->collectors as $collector) {
            try {
                $collector->collect($this->bag);
            } catch (\Throwable $e) {
                $this->bag->set('errors.' . $collector->key(), $e->getMessage());
            }
        }
        return $this->bag;
    }

    /**
     * Run collectors and produce a report using the configured reporter.
     */
    public function report(): mixed
    {
        return $this->reporter->report($this->capture());
    }

    /**
     * Evaluate configured thresholds against collected metrics.
     *
     * @return array<string, array{value: mixed, status: string, threshold: array}>
     */
    public function evaluate(): array
    {
        $results = [];
        foreach ($this->bag->thresholds() as $key => $threshold) {
            $value = $this->bag->get($key);
            if ($value === null) {
                continue;
            }

            $unit  = $threshold['unit'] ?? '';
            $value = is_numeric($value) ? (float) $value : $value;

            $status = 'ok';
            if (is_numeric($value)) {
                if ($value >= $threshold['critical']) {
                    $status = 'critical';
                } elseif ($value >= $threshold['warning']) {
                    $status = 'warning';
                }
            }

            $results[$key] = [
                'value'     => $value,
                'status'    => $status,
                'unit'      => $unit,
                'threshold' => $threshold,
            ];
        }
        return $results;
    }

    // ---- Helpers ----------------------------------------------------------

    public function performance(): ?PerformanceCollector
    {
        $c = $this->collectors['performance'] ?? null;
        return $c instanceof PerformanceCollector ? $c : null;
    }

    public function health(): ?ApplicationHealthCollector
    {
        $c = $this->collectors['health'] ?? null;
        return $c instanceof ApplicationHealthCollector ? $c : null;
    }

    public function environment(): ?EnvironmentCollector
    {
        $c = $this->collectors['environment'] ?? null;
        return $c instanceof EnvironmentCollector ? $c : null;
    }

    public function system(): ?SystemResourceCollector
    {
        $c = $this->collectors['system'] ?? null;
        return $c instanceof SystemResourceCollector ? $c : null;
    }

    private function registerDefaults(): void
    {
        $this->register(new SystemResourceCollector());
        $this->register(new ApplicationHealthCollector());
        $this->register(new PerformanceCollector());
        $this->register(new EnvironmentCollector());
    }
}