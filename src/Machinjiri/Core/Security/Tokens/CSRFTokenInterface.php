<?php

namespace Mlangeni\Machinjiri\Core\Security\Tokens;

interface CSRFTokenInterface
{
    public function generateToken(): string;
    public function getToken(): string;
    public function regenerate(): string;
    public function validateToken(string $token): bool;
    public function generateTokenWithCookie(): string;
    public function validateTokenWithCookie(string $token): bool;
    public function csrfField(string $name = '_token'): string;
}