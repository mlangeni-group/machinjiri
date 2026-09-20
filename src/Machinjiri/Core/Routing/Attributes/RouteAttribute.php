<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

abstract class RouteAttribute implements RouteAttributeInterface
{
    public function __construct(
        protected array $methods,
        protected string $pattern = '',
        protected ?string $name = null,
        protected array $options = []
    ) {}

    public function getMethods(): array
    {
        return array_map('strtoupper', $this->methods);
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}