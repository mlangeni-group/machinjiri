<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Security\Password;

use Mlangeni\Machinjiri\Core\Security\Password\PasswordPolicy;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;
use Mlangeni\Machinjiri\Core\Exceptions\SecurityException;

/**
 * Password value object.
 *
 * Responsibilities:
 *  - Validate a raw password against a PasswordPolicy.
 *  - Report *why* validation failed (list of human-readable messages).
 *  - Provide a heuristic strength score (0–4).
 *  - Hash immediately (bcrypt / argon2) so the plaintext is not retained.
 *  - Verify against a stored hash using a timing-safe comparison.
 *
 * NOTE: Character-class rules are weak security on their own. For production
 * systems, consider pairing with zxcvbn-php for a real strength estimate and
 * an externally-maintained blocklist of breached passwords.
 */
class Password
{
    /** Short, curated blocklist of very common passwords (extend as needed). */
    private const COMMON_PASSWORDS = [
        'password', 'password1', 'password123', 'passw0rd', 'p@ssw0rd',
        '12345678', '123456789', '1234567890', 'qwerty', 'qwerty123',
        'letmein', 'welcome', 'welcome1', 'admin', 'admin123', 'iloveyou',
        'monkey', 'dragon', 'football', 'baseball', 'abc123', '11111111',
        'sunshine', 'princess', 'trustno1', 'superman', 'batman',
    ];

    private PasswordPolicy $policy;
    private Hasher $hasher;

    public function __construct(
        private readonly string $password,
        ?PasswordPolicy $policy = null,
        ?Hasher $hasher = null
    ) {
        $this->policy = $policy ?? new PasswordPolicy();
        $this->hasher = $hasher ?? new Hasher();

        // Guard against pathological input before any regex runs.
        if (strlen($this->password) > $this->policy->maxLength * 4) {
            throw new SecurityException(
                'Password: Password is unreasonably long; refuse to process.'
            );
        }

    }

    /* --------------------------------------------------------------------- */
    /*  Validation                                                           */
    /* --------------------------------------------------------------------- */

    public function validate(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return string[] Human-readable validation errors. Empty array = valid.
     */
    public function errors(): array
    {
        $errors = [];
        $len    = strlen($this->password);
        $p      = $this->policy;

        if ($len < $p->minLength) {
            $errors[] = "Password must be at least {$p->minLength} characters.";
        }

        if ($len > $p->maxLength) {
            $errors[] = "Password must not exceed {$p->maxLength} characters.";
        }

        if ($p->rejectControlChars && preg_match('/[\x00-\x1F\x7F]/', $this->password)) {
            $errors[] = 'Password must not contain control characters.';
        }

        if ($p->requireUppercase && !preg_match('/\p{Lu}/u', $this->password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if ($p->requireLowercase && !preg_match('/\p{Ll}/u', $this->password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if ($p->requireNumber && !preg_match('/\p{N}/u', $this->password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        // "Special" = not a letter, not a number (Unicode-aware).
        if ($p->requireSpecial && !preg_match('/[^\p{L}\p{N}]/u', $this->password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        if ($p->blockCommonPasswords && $this->isCommonPassword()) {
            $errors[] = 'Password is too common; please choose another.';
        }

        return $errors;
    }

    /* --------------------------------------------------------------------- */
    /*  Strength                                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Heuristic strength score from 0 (very weak) to 4 (very strong).
     *
     * This is intentionally simple. For anything security-critical, use a real
     * estimator such as zxcvbn-php.
     */
    public function strength(): int
    {
        if (!$this->validate()) {
            return 0;
        }

        $score  = 0;
        $len    = strlen($this->password);

        // Length contribution.
        if ($len >= 8)  { $score++; }
        if ($len >= 12) { $score++; }
        if ($len >= 16) { $score++; }

        // Variety bonus (already guaranteed by policy, but worth weighting).
        $classes = 0;
        $classes += (int) preg_match('/\p{Lu}/u', $this->password);
        $classes += (int) preg_match('/\p{Ll}/u', $this->password);
        $classes += (int) preg_match('/\p{N}/u', $this->password);
        $classes += (int) preg_match('/[^\p{L}\p{N}]/u', $this->password);
        if ($classes >= 3) { $score++; }

        // Penalise obvious repetition (e.g. "aaaaaaaa", "abcabcabc").
        if (preg_match('/(.)\1{2,}/u', $this->password)) {
            $score--;
        }

        return max(0, min(4, $score));
    }

    /* --------------------------------------------------------------------- */
    /*  Hashing / Verification                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Hash the password immediately so the plaintext is not retained by
     * callers who only need the digest.
     * @throws SecurityException
     */
    public function hash(): string
    {
        return $this->hasher->make($this->password);
    }

    /**
     * Timing-safe verification against a previously stored hash.
     */
    public function verify(string $hash): bool
    {
        return $this->hasher->verify($this->password, $hash);
    }

    /* --------------------------------------------------------------------- */
    /*  Internals                                                            */
    /* --------------------------------------------------------------------- */

    private function isCommonPassword(): bool
    {
        $normalised = strtolower($this->password);

        // Exact match against blocklist.
        if (in_array($normalised, self::COMMON_PASSWORDS, true)) {
            return true;
        }

        // Strip digits/symbols and re-check (catches "P@ssw0rd123").
        $stripped = preg_replace('/[^a-z]/i', '', $normalised) ?? '';
        return $stripped !== ''
            && in_array(strtolower($stripped), self::COMMON_PASSWORDS, true);
    }
}