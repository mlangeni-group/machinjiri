<?php

namespace Mlangeni\Machinjiri\Core\Security\Tokens;

final class CookieOptions
{
    public function __construct(
        public readonly int $expires = 0,
        public readonly string $path = '/',
        public readonly string $domain = '',
        public readonly bool $secure = true,
        public readonly bool $httponly = true,
        public readonly string $samesite = 'Lax', // 'Lax' | 'Strict' | 'None'
    ) {
        if (!in_array($this->samesite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException(
                "Invalid SameSite value: {$this->samesite}"
            );
        }
        if ($this->samesite === 'None' && !$this->secure) {
            throw new \InvalidArgumentException(
                'SameSite=None requires the Secure flag to be set.'
            );
        }
    }
}