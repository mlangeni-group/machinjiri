<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

/**
 * Thrown for cURL-level transport failures (DNS, TCP, TLS, timeouts
 * that survived retry, protocol errors).
 */
class HttpTransportException extends MachinjiriException
{
}