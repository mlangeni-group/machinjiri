<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route extends RouteAttribute
{
    public function __construct(
        array|string $methods,
        string $pattern = '',
        ?string $name = null,
        array $options = []
    ) {
        parent::__construct((array) $methods, $pattern, $name, $options);
    }
}