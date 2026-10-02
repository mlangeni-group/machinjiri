<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Metrics;

final class Gauge implements MetricInterface
{
    private int|float $value = 0;

    public function __construct(private readonly string $name, array $tags = [])
    {
        $this->tags = $tags;
    }

    /** @var array<string, mixed> */
    private array $tags;

    public function set(int|float $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function name(): string { return $this->name; }

    public function value(): int|float { return $this->value; }

    public function snapshot(): array
    {
        return ['type' => 'gauge', 'name' => $this->name, 'value' => $this->value, 'tags' => $this->tags];
    }
}