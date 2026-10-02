<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance;

final class Timer
{
    /** @var Span[] */
    private array $stack = [];
    /** @var Span[] */
    private array $completed = [];
    private bool $enabled = true;

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
 
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function start(string $name, string $category = 'default', array $tags = []): Span
    {
        $parentId = $this->current()?->id;
        $span = new Span($name, $category, $parentId);
        foreach ($tags as $k => $v) {
            $span->addTag($k, $v);
        }
        $this->stack[] = $span;
        return $span;
    }

    public function stop(): ?Span
    {
        $span = array_pop($this->stack);
        if ($span === null) {
            return null;
        }
        $span->stop();
        $parent = $this->current();
        if ($parent !== null) {
            $parent->addChild($span);
        } else {
            $this->completed[] = $span;
        }
        return $span;
    }

    public function current(): ?Span
    {
        return $this->stack[count($this->stack) - 1] ?? null;
    }

    /**
     * Time a callable and return its result.
     */
    public function measure(string $name, callable $fn, string $category = 'default', array $tags = []): mixed
    {
        if (!$this->enabled) {
            return $fn();
        }
        $this->start($name, $category, $tags);
        try {
            return $fn();
        } finally {
            $this->stop();
        }
    }

    /** @return Span[] */
    public function getCompleted(): array
    {
        return $this->completed;
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->completed = [];
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn(Span $s) => $s->toArray(), $this->completed);
    }
}