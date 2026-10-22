<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Security\Hashing;

interface HasherInterface
{
    public function make(string $password): string;

    public function verify(string $password, string $hashed): bool;

    /** @param callable(string):void $persist */
    public function verifyAndRehash(string $password, string $hashed, callable $persist): bool;

    public function needsRehash(string $hashed): bool;
}