<?php

namespace Mlangeni\Machinjiri\Core\Http;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Behaviour changes:
 *   - send() refuses to run if headers were already sent, adds
 *     Content-Length automatically, and calls fastcgi_finish_request()
 *     where available.
 *   - addHeader() supports multiple values per name (Set-Cookie,
 *     WWW-Authenticate, Link, etc.).
 *   - withCookie() validates the name and enforces Secure when
 *     SameSite=None.
 *   - withSecurityHeaders() helper for common hardening headers.
 *   - sendEarlyHints() emits a 103 response.
 *   - statusTexts is now a private const (was a mutable public property).
 */
class HttpResponse
{
    private int $statusCode = 200;
    /** @var array<string,string|string[]> */
    private array $headers = [];
    private string $body = '';
    private bool $sent = false;

    private const STATUS_TEXTS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Append a header value; multiple values for the same name are kept
     * and emitted as separate header lines on send().
     */
    public function addHeader(string $name, string $value): self
    {
        if (!isset($this->headers[$name])) {
            $this->headers[$name] = $value;
        } elseif (is_array($this->headers[$name])) {
            $this->headers[$name][] = $value;
        } else {
            $this->headers[$name] = [$this->headers[$name], $value];
        }
        return $this;
    }

    public function getHeader(string $name): ?string
    {
        $v = $this->headers[$name] ?? null;
        if (is_array($v)) {
            return $v[0] ?? null;
        }
        return $v;
    }

    /**
     * @return string[]
     */
    public function getHeaderAll(string $name): array
    {
        $v = $this->headers[$name] ?? null;
        if ($v === null) {
            return [];
        }
        return is_array($v) ? $v : [$v];
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function removeHeader(string $name): self
    {
        unset($this->headers[$name]);
        return $this;
    }

    public function setContentType(string $type): self
    {
        return $this->setHeader('Content-Type', $type);
    }

    public function setBody(string $content): self
    {
        $this->body = $content;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setJsonBody($data, int $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT): self
    {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $encoded = json_encode($data, $options);
        if ($encoded === false) {
            throw new MachinjiriException('JSON encoding failed: ' . json_last_error_msg());
        }
        $this->body = $encoded;
        return $this;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    /* ==================================================================
     |  Status helpers
     * ================================================================== */

    public function isInformational(): bool { return $this->statusCode >= 100 && $this->statusCode < 200; }
    public function isSuccess(): bool       { return $this->statusCode >= 200 && $this->statusCode < 300; }
    public function isRedirect(): bool      { return $this->statusCode >= 300 && $this->statusCode < 400; }
    public function isClientError(): bool   { return $this->statusCode >= 400 && $this->statusCode < 500; }
    public function isServerError(): bool   { return $this->statusCode >= 500; }
    public function isEmpty(): bool         { return in_array($this->statusCode, [204, 304], true); }

    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Location', $url);
        return $this;
    }

    /* ==================================================================
     |  Cookies
     * ================================================================== */

    public function withCookie(
        string $name,
        string $value = '',
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false,
        string $samesite = ''
    ): self {
        if (!preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/", $name)) {
            throw new MachinjiriException("Invalid cookie name: {$name}");
        }

        $samesite = $samesite === '' ? '' : ucfirst(strtolower($samesite));
        if ($samesite === 'None' && !$secure) {
            throw new MachinjiriException('SameSite=None requires Secure=true');
        }
        if ($samesite !== '' && !in_array($samesite, ['Lax', 'Strict', 'None'], true)) {
            throw new MachinjiriException("Invalid SameSite value: {$samesite}");
        }

        $cookie = sprintf('%s=%s', $name, rawurlencode($value));

        if ($expire > 0) {
            $cookie .= sprintf('; Expires=%s', gmdate('D, d M Y H:i:s T', $expire));
            $cookie .= sprintf('; Max-Age=%d', max(0, $expire - time()));
        }

        if ($path !== '') {
            $cookie .= sprintf('; Path=%s', $path);
        }
        if ($domain !== '') {
            $cookie .= sprintf('; Domain=%s', $domain);
        }
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httponly) {
            $cookie .= '; HttpOnly';
        }
        if ($samesite !== '') {
            $cookie .= sprintf('; SameSite=%s', $samesite);
        }

        return $this->addHeader('Set-Cookie', $cookie);
    }

    /* ==================================================================
     |  Security headers helper
     * ================================================================== */

    public function withSecurityHeaders(
        string $csp = "default-src 'self'",
        bool $hsts = true,
        string $frameOptions = 'DENY'
    ): self {
        $this->setHeader('X-Content-Type-Options', 'nosniff');
        $this->setHeader('X-Frame-Options', $frameOptions);
        $this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->setHeader('Content-Security-Policy', $csp);

        if ($hsts) {
            $this->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $this;
    }

    /* ==================================================================
     |  Early Hints (HTTP 103)
     * ================================================================== */

    /**
     * @param array<string,string> $links  href => as (e.g. '/app.css' => 'style')
     */
    public function sendEarlyHints(array $links): void
    {
        if ($this->sent || headers_sent()) {
            return;
        }
        http_response_code(103);
        foreach ($links as $href => $as) {
            header(sprintf('Link: <%s>; rel=preload; as=%s', $href, $as), false);
        }
    }

    /* ==================================================================
     |  Emission
     * ================================================================== */

    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new MachinjiriException("Headers already sent in {$file}:{$line}");
        }

        $statusText = self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown Status';
        header(
            sprintf('HTTP/1.1 %d %s', $this->statusCode, $statusText),
            true,
            $this->statusCode
        );

        // Auto Content-Length for empty-body statuses / string bodies.
        if ($this->isEmpty()) {
            unset($this->headers['Content-Length'], $this->headers['Transfer-Encoding']);
        } elseif (!isset($this->headers['Content-Length'])
               && !isset($this->headers['Transfer-Encoding'])) {
            $this->headers['Content-Length'] = (string) strlen($this->body);
        }

        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    header("{$name}: {$item}", false);
                }
            } else {
                header("{$name}: {$value}", true);
            }
        }

        if ($this->body !== '' && !$this->isEmpty()) {
            echo $this->body;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $this->sent = true;
    }

    public function sendJson($data, int $statusCode = 200, int $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT): void
    {
        $this->setStatusCode($statusCode)
             ->setJsonBody($data, $options)
             ->send();
    }

    public function sendError(string $message, int $statusCode = 500): void
    {
        $this->sendJson([
            'error'     => true,
            'message'   => $message,
            'code'      => $statusCode,
            'timestamp' => time(),
        ], $statusCode);
    }

    public function sendSuccess($data = null, string $message = 'Success', int $statusCode = 200): void
    {
        $this->sendJson([
            'success'   => true,
            'message'   => $message,
            'data'      => $data,
            'code'      => $statusCode,
            'timestamp' => time(),
        ], $statusCode);
    }

    public function clear(): self
    {
        $this->statusCode = 200;
        $this->headers    = [];
        $this->body       = '';
        $this->sent       = false;
        return $this;
    }
}