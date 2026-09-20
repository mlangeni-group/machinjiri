<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Post extends RouteAttribute
{
    public function __construct(string $pattern = '', ?string $name = null, array $options = [])
    {
        parent::__construct(['POST'], $pattern, $name, $options);
    }
}