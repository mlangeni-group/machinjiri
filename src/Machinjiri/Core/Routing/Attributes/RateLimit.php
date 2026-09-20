<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class RateLimit
{
    public function __construct(public string $limiter) {}
}