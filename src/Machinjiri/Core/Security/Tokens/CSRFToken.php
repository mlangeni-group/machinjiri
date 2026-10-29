<?php

namespace Mlangeni\Machinjiri\Core\Security\Tokens;

use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Exceptions\SecurityException;

final class CSRFToken implements CSRFTokenInterface
{
    private const DEFAULT_TOKEN_NAME = 'csrf_token';
    private const DEFAULT_COOKIE_NAME = 'csrf_cookie';
    private const TOKEN_BYTES = 32;          // 32 bytes => 64 hex chars
    private const DEFAULT_TTL = 3600;         // 1 hour

    private string $tokenName;
    private string $cookieName;
    private int $ttlSeconds;
    private CookieOptions $cookieOptions;
    private ?int $maxFailures;
    private int $failureCount = 0;

    public function __construct(
        private readonly Session $session,
        private readonly Cookie $cookie,
        ?string $tokenName = null,
        ?string $cookieName = null,
        int $ttlSeconds = self::DEFAULT_TTL,
        ?CookieOptions $cookieOptions = null,
        ?int $maxFailures = 5,
    ) {
        if ($ttlSeconds <= 0) {
            throw new SecurityException('CSRFToken: TTL must be positive.');
        }
        $this->tokenName     = $tokenName  ?? self::DEFAULT_TOKEN_NAME;
        $this->cookieName    = $cookieName ?? self::DEFAULT_COOKIE_NAME;
        $this->ttlSeconds    = $ttlSeconds;
        $this->cookieOptions = $cookieOptions ?? new CookieOptions();
        $this->maxFailures   = $maxFailures;
    }

    // ---------------------------------------------------------------------
    // Token generation & retrieval
    // ---------------------------------------------------------------------

    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $this->session->set($this->tokenName, [
            'value'   => $token,
            'expires' => time() + $this->ttlSeconds,
        ]);
        $this->failureCount = 0;

        return $token;
    }

    public function getToken(): string
    {
        $stored = $this->session->get($this->tokenName);

        if (!$this->isValidStoredToken($stored)) {
            return $this->generateToken();
        }

        return $stored['value'];
    }

    public function regenerate(): string
    {
        return $this->generateToken();
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    public function validateToken(?string $token = null): bool
    {
        if ($token === null) return false;
        if (!$this->isWellFormed($token)) {
            $this->recordFailure();
            return false;
        }

        $stored = $this->session->get($this->tokenName);

        if (!$this->isValidStoredToken($stored)) {
            $this->recordFailure();
            return false;
        }

        if (!$this->timingSafeEquals($stored['value'], $token)) {
            $this->recordFailure();
            return false;
        }

        $this->failureCount = 0;
        return true;
    }

    // ---------------------------------------------------------------------
    // Double-submit cookie pattern
    // ---------------------------------------------------------------------

    public function generateTokenWithCookie(): string
    {
        $token = $this->generateToken();

        $this->cookie->set(
            $this->cookieName,
            $token,
            $this->cookieOptions->expires,
            $this->cookieOptions->path,
            $this->cookieOptions->domain,
            $this->cookieOptions->secure,
            $this->cookieOptions->httponly,
            $this->cookieOptions->samesite,
        );

        return $token;
    }

    public function validateTokenWithCookie(string $token): bool
    {
        if (!$this->validateToken($token)) {
            return false;
        }

        $cookieToken = $this->cookie->get($this->cookieName);

        if (!$this->timingSafeEquals($cookieToken, $token)) {
            $this->recordFailure();
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Render a hidden input for forms. Escapes values for safe HTML output.
     */
    public function csrfField(string $name = '_token'): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    /**
     * Check the shape of the incoming token before doing any work.
     */
    private function isWellFormed(string $token): bool
    {
        return $token !== ''
            && strlen($token) === self::TOKEN_BYTES * 2
            && ctype_xdigit($token);
    }

    /**
     * Validate the stored session structure (value + expiry).
     */
    private function isValidStoredToken(mixed $stored): bool
    {
        return is_array($stored)
            && isset($stored['value'], $stored['expires'])
            && is_string($stored['value'])
            && $stored['value'] !== ''
            && $stored['expires'] >= time();
    }

    /**
     * Constant-time string comparison with type guards.
     */
    private function timingSafeEquals(?string $known, ?string $user): bool
    {
        return is_string($known)
            && is_string($user)
            && $known !== ''
            && $user !== ''
            && hash_equals($known, $user);
    }

    /**
     * Invalidate the token after repeated failures to block brute-force probing.
     */
    private function recordFailure(): void
    {
        $this->failureCount++;

        if ($this->maxFailures !== null && $this->failureCount >= $this->maxFailures) {
            $this->session->set($this->tokenName, null);
            $this->failureCount = 0;
        }
    }
}