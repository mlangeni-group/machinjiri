<?php

namespace Mlangeni\Machinjiri\Core\Http;

/**
 * Simple token-bucket rate limiter.
 *
 * Blocks the calling thread until a token is available. Suitable for
 * CLI workers and request-scoped throttling; not a distributed limiter.
 */
final class TokenBucket
{
    private float $tokens;
    private float $lastRefill;

    public function __construct(
        private float $capacity,
        private float $refillPerSecond
    ) {
        $this->tokens     = $capacity;
        $this->lastRefill = microtime(true);
    }

    public function acquire(float $cost = 1.0): void
    {
        while (true) {
            $now = microtime(true);
            $elapsed = $now - $this->lastRefill;
            $this->tokens = min($this->capacity, $this->tokens + $elapsed * $this->refillPerSecond);
            $this->lastRefill = $now;

            if ($this->tokens >= $cost) {
                $this->tokens -= $cost;
                return;
            }

            $deficit = $cost - $this->tokens;
            $waitSec = $deficit / $this->refillPerSecond;
            usleep((int) max(1, $waitSec * 1_000_000));
        }
    }
}