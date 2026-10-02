<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance;

final class Span
{
    private float $startTime;
    private ?float $endTime = null;
    private int $startMemory;
    private ?int $endMemory = null;
    /** @var array<string, mixed> */
    private array $tags = [];
    /** @var Span[] */
    private array $children = [];

    public function __construct(
        public string $name,
        public string $category = 'default',
        public ?string $parentId = null,
        public string $id = ''
    ) {
        $this->id = $id ?: bin2hex(random_bytes(8));
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    public function stop(): self
    {
        if ($this->endTime === null) {
            $this->endTime = microtime(true);
            $this->endMemory = memory_get_usage(true);
        }
        return $this;
    }

    public function addTag(string $key, mixed $value): self
    {
        $this->tags[$key] = $value;
        return $this;
    }

    public function addChild(Span $span): self
    {
        $this->children[] = $span;
        return $this;
    }

    public function duration(): float
    {
        $end = $this->endTime ?? microtime(true);
        return ($end - $this->startTime) * 1000; // ms
    }

    public function memoryDelta(): int
    {
        return ($this->endMemory ?? memory_get_usage(true)) - $this->startMemory;
    }

    public function isFinished(): bool
    {
        return $this->endTime !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'parent_id' => $this->parentId,
            'duration_ms' => round($this->duration(), 4),
            'memory_bytes' => $this->memoryDelta(),
            'tags' => $this->tags,
            'children' => array_map(fn(Span $s) => $s->toArray(), $this->children),
            'started_at' => $this->startTime,
        ];
    }
}