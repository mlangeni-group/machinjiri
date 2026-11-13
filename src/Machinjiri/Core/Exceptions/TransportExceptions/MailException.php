<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class MailException extends MachinjiriException 
{
    private const CODE = 800;

    public static function TransportException(string $message, ?\Throwable $previous): self
    {
        return new self('Mail Transport' . $message, self::CODE, $previous);
    }

    public static function TemplateException(string $message, ?\Throwable $previous): self
    {
        return new self('Mail Template' . $message, self::CODE, $previous);
    }

    public static function ManagerException(string $message, ?\Throwable $previous): self
    {
        return new self('Mail' . $message, self::CODE, $previous);
    }

}