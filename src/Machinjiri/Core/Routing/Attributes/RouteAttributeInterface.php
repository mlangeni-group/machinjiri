<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

interface RouteAttributeInterface
{
    public function getMethods(): array;
    public function getPattern(): string;
    public function getName(): ?string;
    public function getOptions(): array;
}