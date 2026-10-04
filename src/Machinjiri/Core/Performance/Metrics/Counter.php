<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Metrics;

final class Counter implements MetricInterface
{
    private int|float $value = 0;

    public function __construct(private readonly string $name, array $tags = [])
    {
        $this->tags = $tags;
    }

    /** @var array<string, mixed> */
    private array $tags;

    public function increment(int|float $by = 1): self
    {
        $this->value += $by;
        return $this;
    }

    public function name(): string { return $this->name; }

    public function value(): int|float { return $this->value; }

    public function snapshot(): array
    {
        return ['type' => 'counter', 'name' => $this->name, 'value' => $this->value, 'tags' => $this->tags];
    }
}