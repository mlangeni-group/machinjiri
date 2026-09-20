<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Prefix
{
    public function __construct(public string $prefix) {}
}