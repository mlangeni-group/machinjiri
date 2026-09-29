<?php

namespace Mlangeni\Machinjiri\Core\Http;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Exceptions\HttpTransportException;
use Mlangeni\Machinjiri\Core\Exceptions\HttpTimeoutException;
use Mlangeni\Machinjiri\Core\Exceptions\HttpStatusException;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

/**
 * HTTP client wrapper around cURL.
 *
 * Notable behaviour changes vs. the original implementation:
 *   - TLS verification is ENABLED by default (was silently disabled
 *     for non-localhost hosts — a MITM vulnerability).
 *   - close() actually closes the handle (was inverted).
 *   - setHeaders() is idempotent: same header name replaces (was append,
 *     causing duplicated Authorization / Content-Type headers).
 *   - Retry is idempotency-aware: POST/PATCH are NOT retried unless
 *     explicitly opted-in (retryOnNonIdempotent).
 *   - Retry is bounded by wall-clock budget (retryBudgetMs), not just
 *     attempt count.
 *   - Circuit breaker, optional response size cap, optional fail-on-status.
 *   - multiRequest() uses a per-handle header sink (was shared).
 *   - downloadFile() writes atomically and cleans up on failure.
 *   - traceparent / X-Request-ID helpers.
 *   - HTTP/2 negotiation by default (falls back to 1.1 automatically).
 */
class HttpClient
{
    /** @var \CurlHandle|resource|false|null */
    private $ch = null;

    private string $baseUrl;
    private array $options = [];
    private ?Session $session;
    private ?Cookie $cookie;

    private int $timeout = 30;
    private int $maxRedirects = 10;

    private int $retryCount = 0;
    private int $maxRetries = 3;
    private int $retryBaseDelayMs = 500;
    private int $retryMaxDelayMs  = 30000;
    private int $retryBudgetMs    = 120000;
    private bool $retryOnNonIdempotent = false;

    private array $responseHeaders = [];
    private string $responseBuffer = '';
    private string $currentMethod  = 'GET';

    private ?object $logger = null;

    /* ---- circuit breaker ---- */
    private int $failureThreshold   = 5;
    private int $cooldownSeconds    = 30;
    private int $consecutiveFailures = 0;
    private int $circuitOpenedAt    = 0;

    /* ---- response size cap ---- */
    private ?int $maxResponseBytes = null;

    /* ---- fail-on-status ---- */
    private bool $failOnHttpError = false;

    public function __construct(string $baseUrl = '', ?Session $session = null, ?Cookie $cookie = null)
    {
        $this->baseUrl = $baseUrl;
        $this->session = $session;
        $this->cookie  = $cookie;
        $this->initializeCurl();
    }

    public function __destruct()
    {
        $this->close();
    }

    /* ==================================================================
     |  Logging
     * ================================================================== */

    public function setLogger(object $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->{$level}($message, $context);
        }
    }

    /* ==================================================================
     |  cURL lifecycle
     * ================================================================== */

    private function initializeCurl(): void
    {
        $this->ch = curl_init();
        if ($this->ch === false) {
            throw new MachinjiriException('Failed to initialize cURL handle');
        }
        $this->setDefaultOptions();
        $this->withHeaderCapture();
    }

    private function setDefaultOptions(): void
    {
        $this->options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $this->maxRedirects,
            CURLOPT_TIMEOUT        => $this->timeout,
            // Prefer HTTP/2 over TLS, fall back to 1.1 automatically.
            CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_2TLS')
                                        ? CURL_HTTP_VERSION_2TLS
                                        : CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT      => 'Machinjiri-HttpClient/2.0',
            CURLOPT_HEADER         => false,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_TCP_KEEPIDLE   => 30,
            CURLOPT_TCP_KEEPINTVL  => 15,
            // Safe default: verify TLS. Callers may opt out explicitly.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $this->configureLocalhostOptions();
    }

    private function configureLocalhostOptions(): void
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if ($host === null) {
            return;
        }

        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with($host, '.localhost');

        if ($isLocal) {
            // Preserve user-supplied headers; only ensure Expect: is suppressed.
            $existing = $this->options[CURLOPT_HTTPHEADER] ?? [];
            $existing = array_values(array_filter(
                $existing,
                static fn ($h) => !is_string($h) || stripos($h, 'Expect:') !== 0
            ));

            $this->options[CURLOPT_SSL_VERIFYPEER]    = false;
            $this->options[CURLOPT_SSL_VERIFYHOST]    = false;
            $this->options[CURLOPT_IPRESOLVE]         = $host === '::1'
                ? CURL_IPRESOLVE_V6
                : CURL_IPRESOLVE_V4;
            $this->options[CURLOPT_DNS_CACHE_TIMEOUT] = 0;
            $this->options[CURLOPT_CONNECTTIMEOUT]    = 5;
            $this->options[CURLOPT_HTTPHEADER]        = array_merge($existing, ['Expect:']);
        }
    }

    public function close(): void
    {
        if ($this->ch instanceof \CurlHandle || is_resource($this->ch)) {
            curl_close($this->ch);
        }
        $this->ch = null;
    }

    /**
     * Reset for a fresh request but keep the underlying handle so
     * connection pooling is preserved.
     */
    public function reset(): self
    {
        $this->responseHeaders = [];
        $this->responseBuffer  = '';
        $this->retryCount      = 0;
        return $this;
    }

    /* ==================================================================
     |  Builder API
     * ================================================================== */

    public function setOption($option, $value): self
    {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * Replace-by-name headers. Accepts both:
     *   ['Content-Type: application/json', 'Accept: ...']
     *   ['Content-Type' => 'application/json']
     */
    public function setHeaders(array $headers): self
    {
        $existing = $this->options[CURLOPT_HTTPHEADER] ?? [];
        $byName = [];
        foreach ($existing as $h) {
            if (!is_string($h)) {
                continue;
            }
            $name = strtolower(trim(explode(':', $h, 2)[0]));
            $byName[$name] = $h;
        }

        $this->mergeHeaderArray($byName, $headers);
        $this->options[CURLOPT_HTTPHEADER] = array_values($byName);
        return $this;
    }

    /**
     * Append headers without replacing existing ones of the same name.
     */
    public function addHeaders(array $headers): self
    {
        $existing = $this->options[CURLOPT_HTTPHEADER] ?? [];
        $flat = [];
        foreach ($existing as $h) {
            $flat[] = $h;
        }
        foreach ($headers as $k => $v) {
            $flat[] = is_int($k) ? $v : "{$k}: {$v}";
        }
        $this->options[CURLOPT_HTTPHEADER] = $flat;
        return $this;
    }

    private function mergeHeaderArray(array &$byName, array $headers): void
    {
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !str_contains($value, ':')) {
                    continue;
                }
                $name = strtolower(trim(explode(':', $value, 2)[0]));
                $byName[$name] = $value;
            } else {
                $byName[strtolower((string) $key)] = "{$key}: {$value}";
            }
        }
    }

    public function setBasicAuth(string $username, string $password): self
    {
        $this->options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $this->options[CURLOPT_USERPWD]  = "{$username}:{$password}";
        return $this;
    }

    public function setBearerToken(string $token): self
    {
        return $this->setHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function setTimeout($timeout): self
    {
        $this->timeout = max(1, (int) $timeout);
        $this->options[CURLOPT_TIMEOUT] = $this->timeout;
        return $this;
    }

    public function setMaxRedirects($maxRedirects): self
    {
        $this->maxRedirects = max(0, (int) $maxRedirects);
        $this->options[CURLOPT_MAXREDIRS] = $this->maxRedirects;
        return $this;
    }

    public function setUserAgent(string $ua): self
    {
        $this->options[CURLOPT_USERAGENT] = $ua;
        return $this;
    }

    public function setReferer(string $r): self
    {
        $this->options[CURLOPT_REFERER] = $r;
        return $this;
    }

    public function enableCookies($cookieFile = null): self
    {
        $this->options[CURLOPT_COOKIEFILE] = $cookieFile ?? '';
        $this->options[CURLOPT_COOKIEJAR]  = $cookieFile ?? '';
        return $this;
    }

    /**
     * Append a cookie. Name and value are URL-encoded to prevent
     * CRLF/header-injection when callers pass untrusted input.
     */
    public function setCookie(string $name, string $value): self
    {
        $cookie = rawurlencode($name) . '=' . rawurlencode($value);
        $this->options[CURLOPT_COOKIE] = isset($this->options[CURLOPT_COOKIE])
            ? $this->options[CURLOPT_COOKIE] . '; ' . $cookie
            : $cookie;
        return $this;
    }

    public function setProxy(string $proxy, ?int $port = null, ?string $username = null, ?string $password = null): self
    {
        $this->options[CURLOPT_PROXY] = $proxy;
        if ($port !== null) {
            $this->options[CURLOPT_PROXYPORT] = $port;
        }
        if ($username !== null && $password !== null) {
            $this->options[CURLOPT_PROXYUSERPWD] = "{$username}:{$password}";
        }
        return $this;
    }

    public function setSslOptions(bool $verifyPeer = true, int $verifyHost = 2, ?string $certPath = null, ?string $keyPath = null): self
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = $verifyPeer;
        $this->options[CURLOPT_SSL_VERIFYHOST] = $verifyHost;
        if ($certPath) {
            $this->options[CURLOPT_SSLCERT] = $certPath;
        }
        if ($keyPath) {
            $this->options[CURLOPT_SSLKEY] = $keyPath;
        }
        return $this;
    }

    public function setRetryOptions(
        int $maxRetries = 3,
        int $retryBaseDelayMs = 500,
        int $retryMaxDelayMs = 30000,
        int $retryBudgetMs = 120000
    ): self {
        $this->maxRetries       = max(0, $maxRetries);
        $this->retryBaseDelayMs = max(1, $retryBaseDelayMs);
        $this->retryMaxDelayMs  = max($this->retryBaseDelayMs, $retryMaxDelayMs);
        $this->retryBudgetMs    = max($retryBaseDelayMs, $retryBudgetMs);
        return $this;
    }

    public function setRetryOnNonIdempotent(bool $enabled): self
    {
        $this->retryOnNonIdempotent = $enabled;
        return $this;
    }

    public function setMaxResponseBytes(?int $bytes): self
    {
        $this->maxResponseBytes = $bytes !== null ? max(1, $bytes) : null;
        return $this;
    }

    public function failOnHttpError(bool $enabled = true): self
    {
        $this->failOnHttpError = $enabled;
        return $this;
    }

    public function setCircuitBreaker(int $failureThreshold = 5, int $cooldownSeconds = 30): self
    {
        $this->failureThreshold = max(1, $failureThreshold);
        $this->cooldownSeconds  = max(1, $cooldownSeconds);
        return $this;
    }

    public function enableCompression(): self
    {
        $this->options[CURLOPT_ENCODING] = '';
        return $this;
    }

    public function setCustomRequest(string $method): self
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        return $this;
    }

    /* ==================================================================
     |  Tracing helpers
     * ================================================================== */

    public function withTrace(string $traceId, string $spanId, bool $sampled = true, ?string $requestId = null): self
    {
        $flags = $sampled ? '01' : '00';
        $requestId = $requestId ?? bin2hex(random_bytes(16));

        return $this->setHeaders([
            'traceparent'  => "00-{$traceId}-{$spanId}-{$flags}",
            'X-Request-ID' => $requestId,
        ]);
    }

    /* ==================================================================
     |  Header capture
     * ================================================================== */

    public function withHeaderCapture(): self
    {
        $this->responseHeaders = [];
        $sink = &$this->responseHeaders;

        $this->options[CURLOPT_HEADER] = false;
        $this->options[CURLOPT_HEADERFUNCTION] = $this->makeHeaderCollector($sink);
        return $this;
    }

    /**
     * Produce a header-parsing callback bound to the supplied array.
     * Used to keep per-request header state isolated in multiRequest().
     */
    private function makeHeaderCollector(array &$sink): callable
    {
        return static function ($ch, string $header) use (&$sink): int {
            $length = strlen($header);
            $line   = trim($header);
            if ($line === '') {
                return $length;
            }

            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d+)#', $line, $m)) {
                $code = (int) $m[1];
                // Reset when entering a new final response (drop stale
                // redirect headers). Keep 1xx informational as-is.
                if ($code >= 200 || $code === 101) {
                    $sink = [];
                }
                $sink['Status-Line'] = $line;
                return $length;
            }

            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name  = trim($name);
                $value = trim($value);
                if (isset($sink[$name])) {
                    $sink[$name] = is_array($sink[$name])
                        ? [...$sink[$name], $value]
                        : [$sink[$name], $value];
                } else {
                    $sink[$name] = $value;
                }
            }

            return $length;
        };
    }

    /* ==================================================================
     |  Circuit breaker
     * ================================================================== */

    private function assertCircuitClosed(): void
    {
        if ($this->consecutiveFailures < $this->failureThreshold) {
            return;
        }
        if (time() - $this->circuitOpenedAt < $this->cooldownSeconds) {
            throw new HttpTransportException(sprintf(
                'Circuit breaker open (%d consecutive failures, cooldown %ds)',
                $this->consecutiveFailures,
                $this->cooldownSeconds
            ));
        }
        // Half-open: allow next call through, reset counter optimistically.
        $this->consecutiveFailures = 0;
    }

    private function recordSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->circuitOpenedAt     = 0;
    }

    private function recordFailure(): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->circuitOpenedAt = time();
        }
    }

    /* ==================================================================
     |  Execution + retry
     * ================================================================== */

    private function execute(): array
    {
        $this->assertCircuitClosed();
        $this->retryCount = 0;

        $deadlineNs = hrtime(true) + ($this->retryBudgetMs * 1_000_000);

        try {
            $result = $this->performRequest($deadlineNs);
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }

        $code = (int) ($result['http_code'] ?? 0);

        if ($this->failOnHttpError && $code >= 400) {
            $this->recordFailure();
            throw new HttpStatusException(
                $code,
                "HTTP {$code} for {$this->currentMethod} " . ($this->options[CURLOPT_URL] ?? ''),
                $result['headers'] ?? [],
                is_string($result['data'] ?? null) ? $result['data'] : null
            );
        }

        $this->recordSuccess();
        return $result;
    }

    private function performRequest(int $deadlineNs): array
    {
        $this->responseHeaders = [];
        $this->responseBuffer  = '';

        // Local copy so we can add the size-guard callback without
        // mutating persistent options.
        $opts = $this->options;

        $usingBuffer = false;
        if ($this->maxResponseBytes !== null && !isset($opts[CURLOPT_FILE])) {
            $opts[CURLOPT_RETURNTRANSFER] = false;
            $opts[CURLOPT_WRITEFUNCTION]  = function ($ch, string $chunk): int {
                $this->responseBuffer .= $chunk;
                if ($this->maxResponseBytes !== null
                    && strlen($this->responseBuffer) > $this->maxResponseBytes) {
                    return 0; // aborts transfer with CURLE_WRITE_ERROR
                }
                return strlen($chunk);
            };
            $usingBuffer = true;
        }

        curl_setopt_array($this->ch, $opts);

        $raw         = curl_exec($this->ch);
        $error       = curl_error($this->ch);
        $errno       = curl_errno($this->ch);
        $httpCode    = (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($this->ch, CURLINFO_CONTENT_TYPE);
        $totalTime   = curl_getinfo($this->ch, CURLINFO_TOTAL_TIME);

        if ($usingBuffer && $raw === true) {
            $raw = $this->responseBuffer;
        }

        $retryable = $this->isRetryable($httpCode, $errno);

        if ($retryable && $this->retryCount < $this->maxRetries) {
            $delayMs = $this->computeRetryDelayMs($httpCode);
            $nextAttemptCost = $delayMs * 1_000_000; // ns

            if (hrtime(true) + $nextAttemptCost > $deadlineNs) {
                $this->log('warning', 'Retry budget exhausted', [
                    'attempt'   => $this->retryCount,
                    'status'    => $httpCode,
                    'errno'     => $errno,
                    'url'       => $this->options[CURLOPT_URL] ?? null,
                ]);
            } else {
                $this->retryCount++;
                $this->log('warning', 'HTTP request failed, retrying', [
                    'attempt'  => $this->retryCount,
                    'max'      => $this->maxRetries,
                    'status'   => $httpCode,
                    'errno'    => $errno,
                    'delay_ms' => $delayMs,
                    'method'   => $this->currentMethod,
                    'url'      => $this->options[CURLOPT_URL] ?? null,
                ]);
                usleep($delayMs * 1000);
                return $this->performRequest($deadlineNs);
            }
        }

        if ($errno !== 0 && $this->retryCount >= $this->maxRetries) {
            $msg = "cURL error after {$this->maxRetries} retries: {$error} ({$errno})";
            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                throw new HttpTimeoutException($msg);
            }
            throw new HttpTransportException($msg);
        }

        return [
            'data'         => is_string($raw) ? $raw : '',
            'http_code'    => $httpCode,
            'content_type' => $contentType,
            'total_time'   => $totalTime,
            'error'        => $error ?: null,
            'errno'        => $errno ?: null,
            'retry_count'  => $this->retryCount,
            'headers'      => $this->responseHeaders,
        ];
    }

    /**
     * Only idempotent verbs are retried by default. Enable retries for
     * POST/PATCH via setRetryOnNonIdempotent(true) when the caller knows
     * the server is safe (e.g. an Idempotency-Key is being sent).
     */
    private function isRetryable(int $httpCode, int $errno): bool
    {
        $idempotent = in_array($this->currentMethod, ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE', 'TRACE'], true);
        if (!$idempotent && !$this->retryOnNonIdempotent) {
            return false;
        }

        if (in_array($httpCode, [408, 425, 429, 500, 502, 503, 504], true)) {
            return true;
        }

        return in_array($errno, [
            CURLE_COULDNT_CONNECT,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_OPERATION_TIMEOUTED,
            CURLE_GOT_NOTHING,
            CURLE_RECV_ERROR,
            CURLE_SEND_ERROR,
        ], true);
    }

    /**
     * Exponential backoff with full jitter, honouring Retry-After.
     */
    private function computeRetryDelayMs(int $httpCode): int
    {
        $retryAfter = $this->responseHeaders['Retry-After'] ?? null;
        if (is_array($retryAfter)) {
            $retryAfter = $retryAfter[0] ?? null;
        }
        if ($retryAfter !== null) {
            if (ctype_digit((string) $retryAfter)) {
                return min((int) $retryAfter * 1000, $this->retryMaxDelayMs);
            }
            $ts = strtotime((string) $retryAfter);
            if ($ts !== false) {
                return max(0, min(($ts - time()) * 1000, $this->retryMaxDelayMs));
            }
        }

        $exp = $this->retryBaseDelayMs * (2 ** max(0, $this->retryCount - 1));
        $cap = (int) min($exp, $this->retryMaxDelayMs);
        if ($cap < 2) {
            return max(1, $cap);
        }
        return random_int((int) ($cap / 2), $cap);
    }

    /* ==================================================================
     |  Request shortcuts
     * ================================================================== */

    public function get($endpoint = '', $queryParams = []): array
    {
        $this->currentMethod = 'GET';
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint, $queryParams);
        $this->options[CURLOPT_HTTPGET] = true;
        unset($this->options[CURLOPT_CUSTOMREQUEST], $this->options[CURLOPT_POSTFIELDS]);
        return $this->execute();
    }

    public function post($endpoint = '', $data = [], bool $isJson = true): array
    {
        $this->currentMethod = 'POST';
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POST]          = true;
        $this->encodeBody($data, $isJson);
        return $this->execute();
    }

    public function put($endpoint = '', $data = [], bool $isJson = true): array
    {
        $this->currentMethod = 'PUT';
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $this->encodeBody($data, $isJson);
        return $this->execute();
    }

    public function patch($endpoint = '', $data = [], bool $isJson = true): array
    {
        $this->currentMethod = 'PATCH';
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        $this->encodeBody($data, $isJson);
        return $this->execute();
    }

    public function delete($endpoint = '', $data = []): array
    {
        $this->currentMethod = 'DELETE';
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        if (!empty($data)) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type' => 'application/json']);
        }
        return $this->execute();
    }

    public function head($endpoint = ''): array
    {
        $this->currentMethod = 'HEAD';
        $this->options[CURLOPT_URL]    = $this->buildUrl($endpoint);
        $this->options[CURLOPT_NOBODY] = true;
        unset($this->options[CURLOPT_CUSTOMREQUEST]);
        return $this->execute();
    }

    public function options($endpoint = ''): array
    {
        $this->currentMethod = 'OPTIONS';
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'OPTIONS';
        return $this->execute();
    }

    private function encodeBody($data, bool $isJson): void
    {
        if ($isJson && !is_string($data)) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type' => 'application/json']);
        } elseif (is_array($data)) {
            $this->options[CURLOPT_POSTFIELDS] = http_build_query($data);
            $this->setHeaders(['Content-Type' => 'application/x-www-form-urlencoded']);
        } else {
            $this->options[CURLOPT_POSTFIELDS] = $data;
        }
    }

    /* ==================================================================
     |  File upload / download
     * ================================================================== */

    public function uploadFile(string $endpoint, string $fieldName, string $filePath, array $additionalData = []): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new MachinjiriException("File not found or not readable: {$filePath}");
        }

        $this->currentMethod = 'POST';
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POST]          = true;
        $additionalData[$fieldName] = new \CURLFile($filePath);

        // Large uploads can stall with the default "Expect: 100-continue".
        $this->setHeaders(['Expect' => '']);

        $this->options[CURLOPT_POSTFIELDS] = $additionalData;
        return $this->execute();
    }

    /**
     * Atomic download: writes to <savePath>.part-<rand>, renames on
     * success, cleans up on failure.
     */
    public function downloadFile(string $endpoint, string $savePath): array
    {
        $dir = dirname($savePath);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new MachinjiriException("Directory not writable: {$dir}");
        }

        $tmp = $savePath . '.part-' . bin2hex(random_bytes(4));
        $fh  = fopen($tmp, 'w+b');
        if ($fh === false) {
            throw new MachinjiriException("Cannot open file for writing: {$tmp}");
        }

        $this->currentMethod = 'GET';

        $saved = $this->options;
        $this->options[CURLOPT_URL]            = $this->buildUrl($endpoint);
        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_FILE]           = $fh;

        try {
            $result = $this->execute();

            if (!fclose($fh)) {
                throw new MachinjiriException("Failed to flush {$tmp}");
            }
            $fh = null;

            $code = (int) ($result['http_code'] ?? 0);
            if ($code >= 400 || $code === 0) {
                @unlink($tmp);
                throw new HttpStatusException(
                    $code,
                    "Download failed ({$code}) for {$endpoint}"
                );
            }

            if (!rename($tmp, $savePath)) {
                @unlink($tmp);
                throw new MachinjiriException("Cannot rename {$tmp} to {$savePath}");
            }

            return $result;
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            $this->options = $saved;
        }
    }

    /**
     * Perform a request whose body should be returned as a PHP stream
     * resource rather than a string. Uses php://temp (memory, spills
     * to disk past 2 MiB).
     *
     * @return resource
     */
    public function streamRequest(string $method, string $url, array $headers = [], $body = null)
    {
        $tmp = fopen('php://temp', 'w+b');
        if ($tmp === false) {
            throw new MachinjiriException('Cannot open php://temp stream');
        }

        $this->currentMethod = strtoupper($method);

        $saved = $this->options;
        $this->options[CURLOPT_URL]            = $this->buildUrl($url);
        $this->options[CURLOPT_CUSTOMREQUEST]  = $this->currentMethod;
        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_FILE]           = $tmp;
        $this->options[CURLOPT_HTTPHEADER]     = [];

        if ($headers) {
            $this->setHeaders($headers);
        }
        if ($body !== null) {
            $this->options[CURLOPT_POSTFIELDS] = $body;
        }

        try {
            $this->execute();
        } finally {
            $this->options = $saved;
        }

        rewind($tmp);
        return $tmp;
    }

    /**
     * Concurrent multi-request. Header state is isolated per handle.
     */
    public function multiRequest(array $requests): array
    {
        $mh = curl_multi_init();
        $handles          = [];
        $perHandleHeaders = [];
        $results          = [];

        foreach ($requests as $key => $request) {
            $ch = curl_init();
            if ($ch === false) {
                continue;
            }

            $opts = array_merge($this->options, $request['options'] ?? []);
            unset($opts[CURLOPT_HEADER]);

            $perHandleHeaders[$key] = [];
            $opts[CURLOPT_HEADERFUNCTION] = $this->makeHeaderCollector($perHandleHeaders[$key]);
            $opts[CURLOPT_RETURNTRANSFER] = true;

            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $results[$key] = [
                'data'         => is_string($body) ? $body : '',
                'http_code'    => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                'error'        => curl_error($ch) ?: null,
                'errno'        => curl_errno($ch) ?: null,
                'headers'      => $perHandleHeaders[$key] ?? [],
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $results;
    }

    private function buildUrl(string $endpoint, array $queryParams = []): string
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($queryParams)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParams);
        }
        return $url;
    }

    /* ==================================================================
     |  Introspection
     * ================================================================== */

    public function getInfo($option = null)
    {
        if ($this->ch === null) {
            return null;
        }
        return $option !== null ? curl_getinfo($this->ch, $option) : curl_getinfo($this->ch);
    }

    public function getError(): string
    {
        return $this->ch !== null ? curl_error($this->ch) : '';
    }

    public function getErrorCode(): int
    {
        return $this->ch !== null ? curl_errno($this->ch) : 0;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseHeader(string $name): ?string
    {
        $v = $this->responseHeaders[$name] ?? null;
        return is_array($v) ? ($v[0] ?? null) : $v;
    }

    /* ==================================================================
     |  Session / Cookie integration
     * ================================================================== */

    public function useSessionCookies(): self
    {
        if ($this->session && ($sid = $this->session->get('session_id'))) {
            $this->setCookie('PHPSESSID', $sid);
        }
        return $this;
    }

    public function useApplicationCookies(): self
    {
        if ($this->cookie) {
            foreach ($_COOKIE as $name => $value) {
                if (is_string($value)) {
                    $this->setCookie($name, $value);
                }
            }
        }
        return $this;
    }

    public function createFromHttpRequest(HttpRequest $request): self
    {
        $this->setHeaders($request->getHeaders());
        if ($token = $request->getCookie('auth_token')) {
            $this->setBearerToken((string) $token);
        }
        return $this;
    }

    public function toHttpResponse(array $response): HttpResponse
    {
        $httpResponse = new HttpResponse();
        $httpResponse->setStatusCode((int) ($response['http_code'] ?? 200));

        foreach ($response['headers'] ?? [] as $name => $value) {
            if ($name === 'Status-Line') {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    $httpResponse->addHeader((string) $name, (string) $v);
                }
            } else {
                $httpResponse->setHeader((string) $name, (string) $value);
            }
        }
        if (!empty($response['content_type'])) {
            $httpResponse->setHeader('Content-Type', (string) $response['content_type']);
        }

        $body = (string) ($response['data'] ?? '');
        $ct   = $response['content_type'] ?? '';
        if (is_string($ct) && stripos($ct, 'application/json') !== false) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $httpResponse->setJsonBody($decoded);
            } else {
                $httpResponse->setBody($body);
            }
        } else {
            $httpResponse->setBody($body);
        }

        return $httpResponse;
    }
}