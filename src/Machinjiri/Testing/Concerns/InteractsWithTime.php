<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithTime
{
    private ?int $frozenTime = null;

    /**
     * Freeze time at a specific timestamp.
     */
    protected function freezeTime(?int $timestamp = null): void
    {
        $this->frozenTime = $timestamp ?? time();
        // Override time() function if not already overridden (requires uopz or similar)
        // Alternatively, bind a service that returns frozen time.
        $this->bind('time', function () {
            return $this->frozenTime;
        });
    }

    /**
     * Travel forward by seconds.
     */
    protected function travel(int $seconds): void
    {
        if ($this->frozenTime !== null) {
            $this->frozenTime += $seconds;
        }
    }

    /**
     * Travel back to real time.
     */
    protected function unfreezeTime(): void
    {
        $this->frozenTime = null;
    }
}