<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ContractsException;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class TerminalException extends MachinjiriException
{
    public function __construct(string $message = '', int $code = 130, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}