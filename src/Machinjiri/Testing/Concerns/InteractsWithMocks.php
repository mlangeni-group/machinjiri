<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithMocks
{
    /**
     * Create a mock of a class using Mockery.
     */
    protected function mock(string $class, ?callable $expectations = null)
    {
        $mock = \Mockery::mock($class);
        if ($expectations) {
            $expectations($mock);
        }
        $this->singleton($class, $mock);
        return $mock;
    }

    /**
     * Create a spy (partial mock) that records calls.
     */
    protected function spy(string $class)
    {
        $spy = \Mockery::spy($class);
        $this->singleton($class, $spy);
        return $spy;
    }

    /**
     * Create a stub with method return values.
     */
    protected function stub(string $class, array $methodsReturns = [])
    {
        $stub = \Mockery::mock($class);
        foreach ($methodsReturns as $method => $return) {
            $stub->shouldReceive($method)->andReturn($return);
        }
        $this->singleton($class, $stub);
        return $stub;
    }
}