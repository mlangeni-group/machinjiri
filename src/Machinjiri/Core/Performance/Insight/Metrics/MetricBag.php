<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Metrics;

use ArrayAccess;
use JsonSerializable;

/**
 * A simple, dot-notation aware, immutable-ish container for metrics.
 */
final class MetricBag implements ArrayAccess, JsonSerializable
{
    /** @var array<string, mixed> */
    private array $items = [];

    /** @var array<string, float|int|string> */
    private array $thresholds = [];

    public function set(string $key, mixed $value): self
    {
        $this->items[$key] = $value;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        // dot-notation lookup
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__insight_missing__') !== '__insight_missing__';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function forget(string $key): self
    {
        unset($this->items[$key]);
        return $this;
    }

    public function merge(MetricBag $other): self
    {
        $this->items = array_replace_recursive($this->items, $other->all());
        return $this;
    }

    // ---- Thresholds -------------------------------------------------------

    public function setThreshold(string $key, float|int|string $value): self
    {
        $this->thresholds[$key] = $value;
        return $this;
    }

    public function threshold(string $key): float|int|string|null
    {
        return $this->thresholds[$key] ?? null;
    }

    /** @return array<string, float|int|string> */
    public function thresholds(): array
    {
        return $this->thresholds;
    }

    // ---- ArrayAccess ------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string) $offset);
    }

    public function jsonSerialize(): array
    {
        return [
            'metrics'    => $this->items,
            'thresholds' => $this->thresholds,
        ];
    }
}