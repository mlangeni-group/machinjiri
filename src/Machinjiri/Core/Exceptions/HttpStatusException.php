<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

/**
 * Thrown when a caller opts into status-code checking via
 * HttpClient::failOnHttpError(true) and the response is 4xx/5xx.
 */
class HttpStatusException extends MachinjiriException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        public readonly ?string $body = null
    ) {
        parent::__construct($message !== '' ? $message : "HTTP {$status}");
    }
}