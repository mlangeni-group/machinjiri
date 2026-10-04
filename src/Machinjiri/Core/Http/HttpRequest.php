<?php

namespace Mlangeni\Machinjiri\Core\Http;

use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Authentication\OAuth;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Behaviour changes:
 *   - getIp() / isSecure() only trust X-Forwarded-* headers when the
 *     immediate peer is in the trusted proxy list.
 *   - forward() strips hop-by-hop headers case-insensitively and does
 *     NOT forward Content-Length (cURL recalculates).
 *   - api() no longer sends headers twice.
 *   - batch() runs requests concurrently via HttpClient::multiRequest.
 *   - Cookie values must be strings (skips arrays from PHP's $_COOKIE).
 */
class HttpRequest
{
    private string $method;
    private string $uri;
    private array $queryParams;
    private array $postData;
    private array $cookies;
    private array $server;
    private array $headers;
    private string $body;
    private array $attributes = [];

    private HttpClient $client;
    private ?Session $session;
    private ?Cookie  $cookie;
    private ?OAuth   $oauth;

    /** @var string[] */
    private array $trustedProxies = [];

    public function __construct(
        string $method,
        string $uri,
        array $queryParams = [],
        array $postData = [],
        array $cookies = [],
        array $server = [],
        array $headers = [],
        string $body = '',
        ?Session $session = null,
        ?Cookie $cookie = null,
        ?OAuth $oauth = null
    ) {
        $this->method      = strtoupper($method);
        $this->uri         = $uri;
        $this->queryParams = $queryParams;
        $this->postData    = $postData;
        $this->cookies     = $cookies;
        $this->server      = $server;
        $this->headers     = $headers;
        $this->body        = $body;
        $this->session     = $session;
        $this->cookie      = $cookie;
        $this->oauth       = $oauth;
        $this->initializeClient();
    }

    public static function createFromGlobals(): self
    {
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $_SERVER['REQUEST_URI']    ?? '/',
            $_GET,
            $_POST,
            $_COOKIE,
            $_SERVER,
            self::getAllHeaders(),
            (string) file_get_contents('php://input'),
            null,
            null,
            null
        );
    }

    private function initializeClient(): void
    {
        $this->client = new HttpClient('', $this->session, $this->cookie);
    }

    public function getClient(): HttpClient
    {
        return $this->client;
    }

    public function setClient(HttpClient $client): self
    {
        $this->client = $client;
        return $this;
    }

    public function withOAuth(OAuth $oauth): self
    {
        $this->oauth = $oauth;
        return $this;
    }

    public function getOAuth(): ?OAuth
    {
        return $this->oauth;
    }

    public function withTrustedProxies(array $proxies): self
    {
        $this->trustedProxies = array_values(array_filter(array_map('trim', $proxies)));
        return $this;
    }

    /* ==================================================================
     |  HTTP client shortcuts
     * ================================================================== */

    public function get(string $url, array $queryParams = [], array $headers = []): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->get($url, $queryParams));
    }

    public function post(string $url, $data = [], array $headers = [], bool $isJson = true): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->post($url, $data, $isJson));
    }

    public function put(string $url, $data = [], array $headers = [], bool $isJson = true): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->put($url, $data, $isJson));
    }

    public function patch(string $url, $data = [], array $headers = [], bool $isJson = true): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->patch($url, $data, $isJson));
    }

    public function delete(string $url, $data = [], array $headers = []): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->delete($url, $data));
    }

    public function head(string $url, array $headers = []): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->head($url));
    }

    public function options(string $url, array $headers = []): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->options($url));
    }

    /**
     * Apply OAuth bearer token directly to the underlying client, so
     * shortcut methods don't need callers to pass it in $headers.
     */
    private function applyOAuthHeadersToClient(): void
    {
        if ($this->oauth && $this->oauth->isAuthenticated()) {
            $token = $this->oauth->getStoredToken();
            if (is_array($token) && !empty($token['access_token'])) {
                $this->client->setBearerToken((string) $token['access_token']);
            }
        }
    }

    /* ==================================================================
     |  Forward
     * ================================================================== */

    public function forward(string $url, ?string $method = null, array $additionalData = []): HttpResponse
    {
        $method = strtoupper($method ?? $this->method);

        $hopHeaders = [
            'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
            'te', 'trailers', 'transfer-encoding', 'upgrade', 'host', 'content-length',
        ];

        $headers = [];
        foreach ($this->headers as $name => $value) {
            if (in_array(strtolower((string) $name), $hopHeaders, true)) {
                continue;
            }
            $headers[$name] = $value;
        }

        $this->client->useApplicationCookies();
        $this->applyOAuthHeaders($headers);

        $isJson = stripos($this->getContentType(), 'application/json') !== false;

        return match ($method) {
            'GET'    => $this->get($url, array_merge($this->queryParams, $additionalData), $headers),
            'POST'   => $this->post($url, array_merge($this->postData, $additionalData), $headers, $isJson),
            'PUT'    => $this->put($url, array_merge($this->postData, $additionalData), $headers, $isJson),
            'PATCH'  => $this->patch($url, array_merge($this->postData, $additionalData), $headers, $isJson),
            'DELETE' => $this->delete($url, $additionalData, $headers),
            default  => throw new MachinjiriException("Unsupported HTTP method: {$method}"),
        };
    }

    private function applyOAuthHeaders(array &$headers): void
    {
        if (!$this->oauth || !$this->oauth->isAuthenticated()) {
            return;
        }
        $token = $this->oauth->getStoredToken();
        if (is_array($token) && !empty($token['access_token'])) {
            $headers['Authorization'] = 'Bearer ' . $token['access_token'];
        }
    }

    /* ==================================================================
     |  API helper
     * ================================================================== */

    public function api(string $url, ?string $method = null, $data = null, array $headers = []): HttpResponse
    {
        $method = strtoupper($method ?? $this->method);

        $headers = array_merge([
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ], $headers);

        $this->applyOAuthHeaders($headers);

        return match ($method) {
            'GET'     => $this->get($url, is_array($data) ? $data : [], $headers),
            'POST'    => $this->post($url, $data, $headers, true),
            'PUT'     => $this->put($url, $data, $headers, true),
            'PATCH'   => $this->patch($url, $data, $headers, true),
            'DELETE'  => $this->delete($url, $data, $headers),
            'HEAD'    => $this->head($url, $headers),
            'OPTIONS' => $this->options($url, $headers),
            default   => throw new MachinjiriException("Unsupported API method: {$method}"),
        };
    }

    public function oauthApi(string $url, string $method = 'GET', $data = null, array $headers = []): HttpResponse
    {
        if (!$this->oauth || !$this->oauth->isAuthenticated()) {
            throw new MachinjiriException('OAuth authentication required');
        }
        return $this->api($url, $method, $data, $headers);
    }

    /* ==================================================================
     |  Upload / Download / Batch
     * ================================================================== */

    public function upload(string $url, string $fieldName, string $filePath, array $additionalData = [], array $headers = []): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse(
            $this->client->uploadFile($url, $fieldName, $filePath, $additionalData)
        );
    }

    public function download(string $url, string $savePath, array $headers = []): HttpResponse
    {
        $this->client->setHeaders($headers);
        $this->applyOAuthHeadersToClient();
        return $this->client->toHttpResponse($this->client->downloadFile($url, $savePath));
    }

    /**
     * Concurrently dispatch a batch of requests via multiRequest().
     * Returns the same [key => HttpResponse] shape as before.
     */
    public function batch(array $requests): array
    {
        $curlRequests = [];

        foreach ($requests as $key => $request) {
            $method = strtoupper($request['method'] ?? 'GET');
            $url    = (string) ($request['url'] ?? '');
            $data   = $request['data']    ?? [];
            $hdrs   = $request['headers'] ?? [];

            $this->applyOAuthHeaders($hdrs);

            $headerLines = [];
            foreach ($hdrs as $k => $v) {
                $headerLines[] = is_int($k) ? $v : "{$k}: {$v}";
            }

            $opts = [
                CURLOPT_URL           => $url,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER    => $headerLines,
            ];

            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $opts[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
            } elseif ($method === 'GET' && !empty($data) && is_array($data)) {
                $sep = str_contains($url, '?') ? '&' : '?';
                $opts[CURLOPT_URL] = $url . $sep . http_build_query($data);
            } elseif ($method === 'DELETE' && !empty($data)) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($data);
            }

            $curlRequests[$key] = ['options' => $opts];
        }

        $raw = $this->client->multiRequest($curlRequests);

        $out = [];
        foreach ($raw as $key => $resp) {
            $out[$key] = $this->client->toHttpResponse($resp);
        }
        return $out;
    }

    /* ==================================================================
     |  Client configuration passthrough
     * ================================================================== */

    public function withOptions(array $options): self
    {
        foreach ($options as $key => $value) {
            $this->client->setOption($key, $value);
        }
        return $this;
    }

    public function withTimeout(int $timeout): self
    {
        $this->client->setTimeout($timeout);
        return $this;
    }

    public function withAuth(string $type, ...$credentials): self
    {
        switch (strtolower($type)) {
            case 'basic':
                $this->client->setBasicAuth((string) ($credentials[0] ?? ''), (string) ($credentials[1] ?? ''));
                break;
            case 'bearer':
                $this->client->setBearerToken((string) ($credentials[0] ?? ''));
                break;
            case 'oauth':
                if (($credentials[0] ?? null) instanceof OAuth) {
                    $this->withOAuth($credentials[0]);
                }
                break;
        }
        return $this;
    }

    public function withRetry(int $maxRetries = 3, int $retryDelay = 1000): self
    {
        $this->client->setRetryOptions($maxRetries, $retryDelay);
        return $this;
    }

    public function withProxy(string $proxy, ?int $port = null, ?string $username = null, ?string $password = null): self
    {
        $this->client->setProxy($proxy, $port, $username, $password);
        return $this;
    }

    public function withCookies(bool $useSessionCookies = false, bool $useApplicationCookies = true): self
    {
        if ($useSessionCookies) {
            $this->client->useSessionCookies();
        }
        if ($useApplicationCookies) {
            $this->client->useApplicationCookies();
        }
        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function getCookieHandler(): ?Cookie
    {
        return $this->cookie;
    }

    /* ==================================================================
     |  Static helpers
     * ================================================================== */

    private static function getAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                return $h;
            }
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            } elseif ($name === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($name === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }
        return $headers;
    }

    /* ==================================================================
     |  Input accessors
     * ================================================================== */

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return parse_url($this->uri, PHP_URL_PATH) ?: '/';
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQueryParam(string $key, $default = null)
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function getPostData(): array
    {
        return $this->postData;
    }

    public function getPostParam(string $key, $default = null)
    {
        return $this->postData[$key] ?? $default;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getCookie(string $key, $default = null)
    {
        return $this->cookies[$key] ?? $default;
    }

    public function getServer(): array
    {
        return $this->server;
    }

    public function getServerParam(string $key, $default = null)
    {
        return $this->server[$key] ?? $default;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, $default = null)
    {
        $name = strtolower($name);
        foreach ($this->headers as $header => $value) {
            if (strtolower((string) $header) === $name) {
                return $value;
            }
        }
        return $default;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return mixed  decoded JSON body, or null when content-type is not
     *                JSON or the body is not valid JSON.
     */
    public function getJsonBody(bool $assoc = true)
    {
        $contentType = $this->getHeader('Content-Type', '');
        if (!is_string($contentType) || stripos($contentType, 'application/json') === false) {
            return null;
        }
        $data = json_decode($this->body, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $data;
    }

    /**
     * Strict variant — throws on non-JSON or malformed JSON.
     */
    public function getJsonBodyOrFail(bool $assoc = true)
    {
        $contentType = $this->getHeader('Content-Type', '');
        if (!is_string($contentType) || stripos($contentType, 'application/json') === false) {
            throw new MachinjiriException('Request body is not JSON');
        }
        $data = json_decode($this->body, $assoc);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MachinjiriException('JSON decoding failed: ' . json_last_error_msg());
        }
        return $data;
    }

    /* ==================================================================
     |  Content-negotiation helpers
     * ================================================================== */

    public function isAjax(): bool
    {
        $xrw = (string) $this->getHeader('X-Requested-With', '');
        if ($xrw === 'XMLHttpRequest' || $xrw === 'Fetch') {
            return true;
        }
        return stripos((string) $this->getHeader('Accept', ''), 'application/json') !== false;
    }

    public function expectsJson(): bool
    {
        $accept = (string) $this->getHeader('Accept', '');
        return stripos($accept, 'application/json') !== false
            || str_contains($accept, '*/*')
            || $this->isAjax();
    }

    /**
     * Pick the best matching content type from Accept, or $default.
     */
    public function preferredContentType(array $supported, ?string $default = null): ?string
    {
        $accept = (string) $this->getHeader('Accept', '');
        if ($accept === '') {
            return $default;
        }

        $candidates = [];
        foreach (explode(',', $accept) as $part) {
            $segments = explode(';', $part);
            $type = trim($segments[0]);
            $q = 1.0;
            foreach (array_slice($segments, 1) as $s) {
                if (preg_match('/^\s*q=([0-9.]+)/i', $s, $m)) {
                    $q = (float) $m[1];
                    break;
                }
            }
            if ($type !== '') {
                $candidates[$type] = max($candidates[$type] ?? 0.0, $q);
            }
        }
        arsort($candidates);

        foreach (array_keys($candidates) as $type) {
            foreach ($supported as $s) {
                if (strcasecmp($s, $type) === 0) {
                    return $s;
                }
                // Handle wildcards like "text/*"
                if (str_ends_with($type, '/*')) {
                    $prefix = substr($type, 0, -1);
                    if (stripos($s, $prefix) === 0) {
                        return $s;
                    }
                }
                if ($type === '*/*') {
                    return $s;
                }
            }
        }

        return $default;
    }

    public function verifyCsrf(string $tokenFromSession): bool
    {
        $sent = $this->getHeader('X-CSRF-Token')
             ?? $this->getPostParam('_token')
             ?? $this->getHeader('X-XSRF-Token')
             ?? '';

        return is_string($sent)
            && $sent !== ''
            && hash_equals($tokenFromSession, $sent);
    }

    /* ==================================================================
     |  Method predicates
     * ================================================================== */

    public function isGet(): bool      { return $this->method === 'GET'; }
    public function isPost(): bool     { return $this->method === 'POST'; }
    public function isPut(): bool      { return $this->method === 'PUT'; }
    public function isDelete(): bool   { return $this->method === 'DELETE'; }
    public function isPatch(): bool    { return $this->method === 'PATCH'; }
    public function isOptions(): bool  { return $this->method === 'OPTIONS'; }

    public function isSecure(): bool
    {
        if (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') {
            return true;
        }
        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $remote = $this->server['REMOTE_ADDR'] ?? '';
        if (!$this->isTrustedProxy($remote)) {
            return false;
        }

        $proto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($proto) && stripos($proto, 'https') !== false) {
            return true;
        }
        $ssl = $this->server['HTTP_X_FORWARDED_SSL'] ?? '';
        return is_string($ssl) && strtolower($ssl) === 'on';
    }

    /* ==================================================================
     |  Client address
     * ================================================================== */

    public function getIp(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');

        if (!$this->isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            foreach (array_map('trim', explode(',', $forwarded)) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $key) {
            $v = $this->server[$key] ?? '';
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
        }

        return $remote;
    }

    private function isTrustedProxy(string $ip): bool
    {
        if ($ip === '' || $this->trustedProxies === []) {
            return false;
        }
        foreach ($this->trustedProxies as $proxy) {
            if ($proxy === $ip) {
                return true;
            }
            if (str_contains($proxy, '/') && $this->cidrMatch($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    private function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipL   = ip2long($ip);
        $subL  = ip2long($subnet);
        if ($ipL === false || $subL === false) {
            return false;
        }
        $bits = max(0, min(32, (int) $bits));
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ipL & $mask) === ($subL & $mask);
    }

    /* ==================================================================
     |  Misc getters
     * ================================================================== */

    public function getUserAgent(): string
    {
        return (string) $this->getHeader('User-Agent', '');
    }

    public function getReferer(): string
    {
        return (string) $this->getHeader('Referer', '');
    }

    public function getContentType(): string
    {
        return (string) $this->getHeader('Content-Type', '');
    }

    public function getContentLength(): int
    {
        return (int) $this->getHeader('Content-Length', 0);
    }

    public function getAccept(): string
    {
        return (string) $this->getHeader('Accept', '');
    }

    public function getAcceptLanguage(): string
    {
        return (string) $this->getHeader('Accept-Language', '');
    }

    public function getAcceptEncoding(): string
    {
        return (string) $this->getHeader('Accept-Encoding', '');
    }

    /* ==================================================================
     |  Attributes
     * ================================================================== */

    public function setAttribute(string $name, $value): self
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function removeAttribute(string $name): self
    {
        unset($this->attributes[$name]);
        return $this;
    }

    /* ==================================================================
     |  Input bag helpers
     * ================================================================== */

    public function all(): array
    {
        return array_merge($this->queryParams, $this->postData);
    }

    public function input(string $key, $default = null)
    {
        return $this->postData[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->input($key);
        }
        return $out;
    }

    public function except(array $keys): array
    {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    public function has(string $key): bool
    {
        return isset($this->postData[$key]) || isset($this->queryParams[$key]);
    }

    public function filled(string $key): bool
    {
        return !empty($this->input($key));
    }

    public function setMethod(string $method): void 
    {
        $this->method = $method;
    }
}