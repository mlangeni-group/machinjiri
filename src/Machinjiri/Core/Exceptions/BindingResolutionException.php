<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Throwable;

/**
 * Thrown when the service container cannot resolve a binding.
 *
 * Extends MachinjiriException so existing catch blocks keep working
 * (default code 110 matches the original).
 */
class BindingResolutionException extends MachinjiriException
{
    public function __construct(string $message = '', int $code = 110, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}