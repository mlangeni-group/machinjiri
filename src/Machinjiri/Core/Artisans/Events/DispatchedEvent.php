<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

/**
 * Immutable value object representing a dispatched event.
 */
final class DispatchedEvent
{
    protected string $name;
    protected ?int   $id;
    protected $payload;

    public function __construct(string $name, ?int $id = null, $payload = null)
    {
        $this->name    = $name;
        $this->id      = $id;
        $this->payload = $payload;
    }

    public function name(): string    { return $this->name; }
    public function id(): ?int        { return $this->id; }
    public function payload()         { return $this->payload; }

    public function withId(int $id): self
    {
        return new self($this->name, $id, $this->payload);
    }
}