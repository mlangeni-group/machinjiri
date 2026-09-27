<?php

namespace Mlangeni\Machinjiri\Core\Http;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

class HttpClient
{
    private $ch;
    private string $baseUrl;
    private array $options = [];
    private ?Session $session;
    private ?Cookie $cookie;
    private int $timeout = 30;
    private int $maxRedirects = 10;
    private int $retryCount = 0;
    private int $maxRetries = 3;
    private int $retryBaseDelayMs = 500;   // base for exponential backoff
    private int $retryMaxDelayMs = 30000;  // cap
    private array $responseHeaders = [];

    /** Optional PSR-3 logger. */
    private ?object $logger = null;

    public function __construct(string $baseUrl = '', ?Session $session = null, ?Cookie $cookie = null)
    {
        $this->baseUrl = $baseUrl;
        $this->session = $session;
        $this->cookie  = $cookie;
        $this->initializeCurl();
    }

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

    private function initializeCurl(): void
    {
        $this->ch = curl_init();
        $this->setDefaultOptions();
        // Always capture headers so callers can read Location / Retry-After.
        $this->withHeaderCapture();
    }

    private function setDefaultOptions(): void
    {
        $this->options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $this->maxRedirects,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT      => 'Machinjiri-HttpClient/1.0',
            CURLOPT_HEADER         => false,
            CURLOPT_FAILONERROR    => false,
            // Explicit TCP connect timeout so slow DNS can't eat the whole budget.
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        $this->configureLocalhostOptions();
    }

    private function configureLocalhostOptions(): void
    {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if ($host === null) {
            return;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')) {
            $this->options[CURLOPT_SSL_VERIFYPEER] = false;
            $this->options[CURLOPT_SSL_VERIFYHOST] = false;
            $this->options[CURLOPT_IPRESOLVE] = $host === '::1' ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4;
            $this->options[CURLOPT_DNS_CACHE_TIMEOUT] = 0;
            $this->options[CURLOPT_CONNECTTIMEOUT] = 5;
            $this->options[CURLOPT_HTTPHEADER] = ['Expect:'];
        } else {
            $this->options[CURLOPT_SSL_VERIFYPEER] = false; // set true in prod
            $this->options[CURLOPT_SSL_VERIFYHOST] = 2;
        }
    }

    /* ------------------------------------------------------------------
     | Builder API (unchanged signatures, safer implementations)
     * ----------------------------------------------------------------- */

    public function setOption($option, $value): self { $this->options[$option] = $value; return $this; }

    public function setHeaders(array $headers): self
    {
        $existing = $this->options[CURLOPT_HTTPHEADER] ?? [];
        $this->options[CURLOPT_HTTPHEADER] = array_merge($existing, $headers);
        return $this;
    }

    public function setBasicAuth($username, $password): self
    {
        $this->options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $this->options[CURLOPT_USERPWD]  = "{$username}:{$password}";
        return $this;
    }

    public function setBearerToken($token): self
    {
        return $this->setHeaders(['Authorization: Bearer ' . $token]);
    }

    public function setTimeout($timeout): self
    {
        $this->timeout = (int) $timeout;
        $this->options[CURLOPT_TIMEOUT] = $this->timeout;
        return $this;
    }

    public function setMaxRedirects($maxRedirects): self
    {
        $this->maxRedirects = (int) $maxRedirects;
        $this->options[CURLOPT_MAXREDIRS] = $this->maxRedirects;
        return $this;
    }

    public function setUserAgent($ua): self { $this->options[CURLOPT_USERAGENT] = $ua; return $this; }
    public function setReferer($r): self { $this->options[CURLOPT_REFERER] = $r; return $this; }

    public function enableCookies($cookieFile = null): self
    {
        $this->options[CURLOPT_COOKIEFILE] = $cookieFile ?? '';
        $this->options[CURLOPT_COOKIEJAR]  = $cookieFile ?? '';
        return $this;
    }

    public function setCookie($name, $value): self
    {
        $cookie = "{$name}={$value}";
        $this->options[CURLOPT_COOKIE] = isset($this->options[CURLOPT_COOKIE])
            ? $this->options[CURLOPT_COOKIE] . "; {$cookie}"
            : $cookie;
        return $this;
    }

    public function setProxy($proxy, $port = null, $username = null, $password = null): self
    {
        $this->options[CURLOPT_PROXY] = $proxy;
        if ($port) $this->options[CURLOPT_PROXYPORT] = $port;
        if ($username && $password) $this->options[CURLOPT_PROXYUSERPWD] = "{$username}:{$password}";
        return $this;
    }

    public function setSslOptions($verifyPeer = true, $verifyHost = 2, $certPath = null, $keyPath = null): self
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = $verifyPeer;
        $this->options[CURLOPT_SSL_VERIFYHOST] = $verifyHost;
        if ($certPath) $this->options[CURLOPT_SSLCERT] = $certPath;
        if ($keyPath)  $this->options[CURLOPT_SSLKEY]  = $keyPath;
        return $this;
    }

    /**
     * Configure retry behaviour.
     *
     * @param int $maxRetries          Total retries (0 disables retries).
     * @param int $retryBaseDelayMs    Base backoff delay (exponential).
     * @param int $retryMaxDelayMs     Hard cap on any single backoff sleep.
     */
    public function setRetryOptions(int $maxRetries = 3, int $retryBaseDelayMs = 500, int $retryMaxDelayMs = 30000): self
    {
        $this->maxRetries       = max(0, $maxRetries);
        $this->retryBaseDelayMs = max(1, $retryBaseDelayMs);
        $this->retryMaxDelayMs  = max($this->retryBaseDelayMs, $retryMaxDelayMs);
        return $this;
    }

    public function enableCompression(): self { $this->options[CURLOPT_ENCODING] = ''; return $this; }
    public function setCustomRequest($method): self { $this->options[CURLOPT_CUSTOMREQUEST] = strtoupper($method); return $this; }

    /* ------------------------------------------------------------------
     | Header capture
     * ----------------------------------------------------------------- */

    public function withHeaderCapture(): self
    {
        $this->options[CURLOPT_HEADER] = false;
        $this->options[CURLOPT_HEADERFUNCTION] = function ($ch, $header) {
            $length = strlen($header);
            $header = trim($header);
            if ($header === '') {
                return $length;
            }
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $name  = trim($name);
                $value = trim($value);
                if (isset($this->responseHeaders[$name])) {
                    $this->responseHeaders[$name] = is_array($this->responseHeaders[$name])
                        ? [...$this->responseHeaders[$name], $value]
                        : [$this->responseHeaders[$name], $value];
                } else {
                    $this->responseHeaders[$name] = $value;
                }
            } elseif (preg_match('/^HTTP\/\d(?:\.\d)?\s+\d+/', $header)) {
                $this->responseHeaders['Status-Line'] = $header;
            }
            return $length;
        };
        return $this;
    }

    /* ------------------------------------------------------------------
     | Execution + retry
     * ----------------------------------------------------------------- */

    private function execute()
    {
        $this->responseHeaders = [];

        curl_setopt_array($this->ch, $this->options);

        $response    = curl_exec($this->ch);
        $error       = curl_error($this->ch);
        $errno       = curl_errno($this->ch);
        $httpCode    = (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($this->ch, CURLINFO_CONTENT_TYPE);
        $totalTime   = curl_getinfo($this->ch, CURLINFO_TOTAL_TIME);

        $retryable = $this->isRetryable($httpCode, $errno);

        if ($retryable && $this->retryCount < $this->maxRetries) {
            $this->retryCount++;
            $delayMs = $this->computeRetryDelayMs($httpCode);
            $this->log('warning', 'HTTP request failed, retrying', [
                'attempt'    => $this->retryCount,
                'max'        => $this->maxRetries,
                'status'     => $httpCode,
                'errno'      => $errno,
                'delay_ms'   => $delayMs,
                'url'        => $this->options[CURLOPT_URL] ?? null,
            ]);
            usleep($delayMs * 1000);
            return $this->execute();
        }

        if ($errno !== 0 && $this->retryCount >= $this->maxRetries) {
            throw new MachinjiriException("cURL error after {$this->maxRetries} retries: {$error} ({$errno})");
        }

        return [
            'data'         => $response,
            'http_code'    => $httpCode,
            'content_type' => $contentType,
            'total_time'   => $totalTime,
            'error'        => $error ?: null,
            'errno'        => $errno ?: null,
            'retry_count'  => $this->retryCount,
            'headers'      => $this->responseHeaders,
        ];
    }

    /** Which HTTP statuses / cURL errno values should trigger a retry. */
    private function isRetryable(int $httpCode, int $errno): bool
    {
        if (in_array($httpCode, [408, 425, 429, 500, 502, 503, 504], true)) {
            return true;
        }
        // Transient network errors.
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
     * Exponential backoff with full jitter, respecting Retry-After.
     */
    private function computeRetryDelayMs(int $httpCode): int
    {
        // Honour Retry-After (seconds or HTTP-date) if present.
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

        // Exponential backoff with full jitter.
        $exp   = $this->retryBaseDelayMs * (2 ** ($this->retryCount - 1));
        $cap   = min($exp, $this->retryMaxDelayMs);
        return random_int((int) ($cap / 2), (int) $cap);
    }

    /* ------------------------------------------------------------------
     | Request shortcuts (unchanged behaviour, saner state resets)
     * ----------------------------------------------------------------- */

    public function get($endpoint = '', $queryParams = [])
    {
        $this->options[CURLOPT_URL]        = $this->buildUrl($endpoint, $queryParams);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'GET';
        $this->options[CURLOPT_POSTFIELDS] = null;
        $this->options[CURLOPT_HTTPGET]    = true;
        $this->retryCount = 0;
        return $this->execute();
    }

    public function post($endpoint = '', $data = [], $isJson = true)
    {
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POST]          = true;
        $this->encodeBody($data, $isJson);
        $this->retryCount = 0;
        return $this->execute();
    }

    public function put($endpoint = '', $data = [], $isJson = true)
    {
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $this->encodeBody($data, $isJson);
        $this->retryCount = 0;
        return $this->execute();
    }

    public function patch($endpoint = '', $data = [], $isJson = true)
    {
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        $this->encodeBody($data, $isJson);
        $this->retryCount = 0;
        return $this->execute();
    }

    public function delete($endpoint = '', $data = [])
    {
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        if (!empty($data)) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type: application/json']);
        }
        $this->retryCount = 0;
        return $this->execute();
    }

    public function head($endpoint = '')
    {
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        $this->options[CURLOPT_NOBODY]        = true;
        $this->retryCount = 0;
        return $this->execute();
    }

    public function options($endpoint = '')
    {
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'OPTIONS';
        $this->retryCount = 0;
        return $this->execute();
    }

    private function encodeBody($data, bool $isJson): void
    {
        if ($isJson && !is_string($data)) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type: application/json']);
        } elseif (is_array($data)) {
            $this->options[CURLOPT_POSTFIELDS] = http_build_query($data);
            $this->setHeaders(['Content-Type: application/x-www-form-urlencoded']);
        } else {
            $this->options[CURLOPT_POSTFIELDS] = $data; // raw string/resource
        }
    }

    /* ------------------------------------------------------------------
     | File upload / download
     * ----------------------------------------------------------------- */

    public function uploadFile($endpoint, $fieldName, $filePath, $additionalData = [])
    {
        if (!file_exists($filePath)) {
            throw new MachinjiriException("File not found: {$filePath}");
        }
        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POST]          = true;
        $additionalData[$fieldName] = new \CURLFile($filePath);
        $this->options[CURLOPT_POSTFIELDS] = $additionalData;
        $this->retryCount = 0;
        return $this->execute();
    }

    public function downloadFile($endpoint, $savePath)
    {
        $fh = fopen($savePath, 'w+b');
        if ($fh === false) {
            throw new MachinjiriException("Cannot open file for writing: {$savePath}");
        }

        $this->options[CURLOPT_URL]           = $this->buildUrl($endpoint);
        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_FILE]          = $fh;
        $this->retryCount = 0;

        try {
            $result = $this->execute();
        } finally {
            fclose($fh);
            $this->options[CURLOPT_RETURNTRANSFER] = true;
            unset($this->options[CURLOPT_FILE]);
        }
        return $result;
    }

    /**
     * Perform a request whose body should be returned as a PHP stream
     * resource rather than a string.  Writes the response into a
     * php://temp stream (memory, spills to disk past 2 MiB).
     *
     * @return resource
     */
    public function streamRequest(string $method, string $url, array $headers = [], $body = null)
    {
        $tmp = fopen('php://temp', 'w+b');

        $saved = $this->options;
        $this->options[CURLOPT_URL]            = $this->buildUrl($url);
        $this->options[CURLOPT_CUSTOMREQUEST]  = strtoupper($method);
        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_FILE]           = $tmp;
        $this->options[CURLOPT_HTTPHEADER]     = $headers;

        if ($body !== null) {
            $this->options[CURLOPT_POSTFIELDS] = $body;
        }

        $this->retryCount = 0;

        try {
            $this->execute();
        } finally {
            $this->options = $saved;
        }

        rewind($tmp);
        return $tmp;
    }

    public function multiRequest(array $requests): array
    {
        $mh = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $key => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, array_merge($this->options, $request['options'] ?? []));
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $key => $ch) {
            $results[$key] = [
                'data'      => curl_multi_getcontent($ch),
                'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'error'     => curl_error($ch),
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

    /* ------------------------------------------------------------------
     | Introspection
     * ----------------------------------------------------------------- */

    public function getInfo($option = null) { return $option ? curl_getinfo($this->ch, $option) : curl_getinfo($this->ch); }
    public function getError(): string { return curl_error($this->ch); }
    public function getErrorCode(): int { return curl_errno($this->ch); }
    public function getResponseHeaders(): array { return $this->responseHeaders; }
    public function getResponseHeader(string $name): ?string
    {
        $v = $this->responseHeaders[$name] ?? null;
        return is_array($v) ? ($v[0] ?? null) : $v;
    }

    public function reset(): self
    {
        curl_close($this->ch);
        $this->options = [];
        $this->responseHeaders = [];
        $this->retryCount = 0;
        $this->initializeCurl();
        return $this;
    }

    public function close(): void
    {
        if (is_resource($this->ch) || $this->ch instanceof \CurlHandle) {
            return;
        }
    }

    public function __destruct() { $this->close(); }

    /* ------------------------------------------------------------------
     | Session / Cookie integration
     * ----------------------------------------------------------------- */

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
                $this->setCookie($name, $value);
            }
        }
        return $this;
    }

    public function createFromHttpRequest(HttpRequest $request): self
    {
        $this->setHeaders($request->getHeaders());
        if ($token = $request->getCookie('auth_token')) {
            $this->setBearerToken($token);
        }
        return $this;
    }

    public function toHttpResponse($response): HttpResponse
    {
        $httpResponse = new HttpResponse();
        $httpResponse->setStatusCode($response['http_code']);

        foreach ($response['headers'] ?? [] as $name => $value) {
            if ($name === 'Status-Line') continue;
            $httpResponse->setHeader($name, is_array($value) ? ($value[0] ?? '') : $value);
        }
        if (!empty($response['content_type'])) {
            $httpResponse->setHeader('Content-Type', $response['content_type']);
        }

        $body = $response['data'] ?? '';
        if (str_contains($response['content_type'] ?? '', 'application/json')) {
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