<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithException
{
    protected function expectExceptionType(string $exceptionClass): void
    {
        $this->expectException($exceptionClass);
    }

    protected function expectExceptionMessageText(string $message): void
    {
        $this->expectExceptionMessage($message);
    }

    protected function expectExceptionCodeValue(int $code): void
    {
        $this->expectExceptionCode($code);
    }
}