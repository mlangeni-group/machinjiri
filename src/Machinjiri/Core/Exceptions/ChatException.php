<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

class ChatException extends MachinjiriException
{
    public static function configError(string $message): self
    {
        return new self('Chat configuration error: ' . $message, 0, null, [], 'chat_config');
    }

    public static function validationError(string $message): self
    {
        return new self('Chat message error: ' . $message, 422, null, [], 'chat_validation');
    }
}
