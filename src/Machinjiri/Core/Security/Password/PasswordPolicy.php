<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Security\Password;

use Mlangeni\Machinjiri\Core\Exceptions\SecurityException;
/**
 * Password policy value object.
 *
 * Encapsulates the rules used to validate a password. Kept immutable so it
 * can be safely shared / injected wherever a policy is required.
 */
final class PasswordPolicy
{
    public function __construct(
        public readonly int  $minLength        = 8,
        public readonly int  $maxLength        = 72,   // bcrypt truncates at 72 bytes
        public readonly bool $requireUppercase = true,
        public readonly bool $requireLowercase = true,
        public readonly bool $requireNumber    = true,
        public readonly bool $requireSpecial   = true,
        public readonly bool $rejectControlChars = true,
        public readonly bool $blockCommonPasswords = true,
    ) {
        if ($minLength < 1) {
            throw new SecurityException('Password Policy: minLength must be >= 1.');
        }
        if ($maxLength < $minLength) {
            throw new SecurityException('Password Policy: maxLength must be >= minLength.');
        }
    }
}