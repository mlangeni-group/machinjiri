<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Container;

trait InteractsWithContainer
{
    protected function bind(string $abstract, $concrete, bool $shared = false): void
    {
        Container::getInstance()->bind($abstract, $concrete, $shared);
    }

    protected function singleton(string $abstract, mixed $concrete = null): void
    {
        Container::getInstance()->singleton($abstract, $concrete);
    }

    protected function resolve(string $abstract, array $parameters = [])
    {
        return Container::getInstance()->resolve($abstract, $parameters);
    }
}