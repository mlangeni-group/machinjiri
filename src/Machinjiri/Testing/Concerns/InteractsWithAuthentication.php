<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Facade\Authentication\Auth;

trait InteractsWithAuthentication
{
    protected function actingAs($user, ?string $guard = null): void
    {
        if (!Container::instancePresent()) {
            return;
        }

        Auth::guard($guard)->login($user);
    }

    protected function be($user, ?string $guard = null): void
    {
        $this->actingAs($user, $guard);
    }

    protected function assertAuthenticated(?string $guard = null): void
    {
        $this->assertTrue(Auth::guard($guard)->check(), 'The user is not authenticated.');
    }

    protected function assertGuest(?string $guard = null): void
    {
        $this->assertTrue(Auth::guard($guard)->guest(), 'The user is authenticated unexpectedly.');
    }
}
