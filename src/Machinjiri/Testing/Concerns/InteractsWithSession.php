<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Authentication\Session;

trait InteractsWithSession
{
    protected function setUpSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    protected function withSession(array $data): void
    {
        foreach ($data as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    protected function assertSessionHas(string $key, mixed $value = null): void
    {
        $this->assertArrayHasKey($key, $_SESSION);
        if ($value !== null) {
            $this->assertEquals($value, $_SESSION[$key]);
        }
    }
}