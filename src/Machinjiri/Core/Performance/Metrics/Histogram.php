<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Metrics;

final class Histogram implements MetricInterface
{
    /** @var float[] */
    private array $samples = [];

    public function __construct(private readonly string $name, private readonly int $maxSamples = 10000)
    {
    }

    public function observe(float $value): self
    {
        if (count($this->samples) >= $this->maxSamples) {
            array_shift($this->samples);
        }
        $this->samples[] = $value;
        return $this;
    }

    public function name(): string { return $this->name; }

    /** @return float[] */
    public function samples(): array { return $this->samples; }

    public function percentile(float $p): float
    {
        if ($this->samples === []) return 0.0;
        $sorted = $this->samples;
        sort($sorted);
        $idx = (int) floor(($p / 100) * (count($sorted) - 1));
        return $sorted[$idx];
    }

    public function snapshot(): array
    {
        $count = count($this->samples);
        $sum = array_sum($this->samples);
        return [
            'type' => 'histogram',
            'name' => $this->name,
            'count' => $count,
            'sum' => $sum,
            'avg' => $count ? $sum / $count : 0,
            'min' => $count ? min($this->samples) : 0,
            'max' => $count ? max($this->samples) : 0,
            'p50' => $this->percentile(50),
            'p95' => $this->percentile(95),
            'p99' => $this->percentile(99),
        ];
    }
}