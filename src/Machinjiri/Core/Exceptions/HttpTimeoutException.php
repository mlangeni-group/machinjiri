<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

/**
 * Specific subclass so callers can distinguish timeouts from generic
 * transport failures (useful for circuit breaker / fallback logic).
 */
class HttpTimeoutException extends HttpTransportException
{
}