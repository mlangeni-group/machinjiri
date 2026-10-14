<?php

use Mlangeni\Machinjiri\Core\Debug\Dumper;
use Mlangeni\Machinjiri\Core\Debug\Debugger;

if (!function_exists('dump')) {
    /**
     * Dump the given variables and continue execution.
     *
     * @param mixed ...$args
     * @return void
     */
    function dump(...$args): void
    {
        Dumper::dump(...$args);
    }
}

if (!function_exists('dd')) {
    /**
     * Dump the given variables and end the script.
     *
     * @param mixed ...$args
     * @return never
     */
    function dd(...$args): never
    {
        Dumper::dd(...$args);
    }
}

if (!function_exists('debugger')) {
    /**
     * Get the Debugger instance.
     *
     * @return Debugger
     */
    function debugger(): Debugger
    {
        return Debugger::getInstance();
    }
}

if (!function_exists('debug')) {
    /**
     * Log a debug message.
     *
     * @param mixed $message
     * @param array $context
     * @return void
     */
    function debug($message, array $context = []): void
    {
        debugger()->log($message, 'debug', $context);
    }
}

if (!function_exists('measure')) {
    /**
     * Measure execution time of a callable.
     *
     * @param callable $callback
     * @param string $label
     * @return mixed
     */
    function measure(callable $callback, string $label = 'anonymous')
    {
        return debugger()->measure($callback, $label);
    }
}