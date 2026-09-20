<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Middleware
{
    public array $middleware;

    public function __construct(array|string $middleware)
    {
        $this->middleware = (array) $middleware;
    }
}