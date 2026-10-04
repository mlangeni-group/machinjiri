<?php

namespace Mlangeni\Machinjiri\Testing\Traits;

use Mlangeni\Machinjiri\Core\Container;

trait WithoutMiddleware
{
    protected function disableMiddleware(): void
    {
        if (!Container::instancePresent()) {
            return;
        }

        $this->bind('middleware.dispatcher', function () {
            return new class {
                public function handle($request, $next)
                {
                    return $next($request);
                }
            };
        });
    }

    protected function setUpWithoutMiddleware(): void
    {
        $this->disableMiddleware();
    }

    protected function withoutMiddleware(): void
    {
        $this->disableMiddleware();
    }
}
