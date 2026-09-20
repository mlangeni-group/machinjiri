<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Head extends RouteAttribute
{
    public function __construct(string $pattern = '', ?string $name = null, array $options = [])
    {
        parent::__construct(['HEAD'], $pattern, $name, $options);
    }
}