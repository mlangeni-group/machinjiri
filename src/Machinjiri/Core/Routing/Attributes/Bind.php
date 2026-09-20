<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Bind
{
    public function __construct(public string $param, public string $model) {}
}