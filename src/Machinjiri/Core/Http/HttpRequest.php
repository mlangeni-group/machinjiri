<?php

namespace Mlangeni\Machinjiri\Core\Http;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Authentication\OAuth;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class HttpRequest {
    private $method;
    private $uri;
    private $queryParams;
    private $postData;
    private $cookies;
    private $server;
    private $headers;
    private $body;
    private $attributes = [];
    private $client;
    private $session;
    private $cookie;
    private $oauth;

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
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->queryParams = $queryParams;
        $this->postData = $postData;
        $this->cookies = $cookies;
        $this->server = $server;
        $this->headers = $headers;
        $this->body = $body;
        $this->session = $session;
        $this->cookie = $cookie;
        $this->oauth = $oauth;
        $this->initializeClient();
    }

    public static function createFromGlobals(): self {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $queryParams = $_GET;
        $postData = $_POST;
        $cookies = $_COOKIE;
        $server = $_SERVER;
        
        $headers = self::getAllHeaders();
        
        $body = file_get_contents('php://input');

        // Initialize Session and Cookie if needed
        $session = null;
        $cookie = null;
        
        // Initialize OAuth if needed (optional)
        $oauth = null;
        if (class_exists('\Mlangeni\Machinjiri\Core\Authentication\OAuth')) {
            // OAuth requires configuration, so we'll initialize it only if needed
            // This can be set later using withOAuth() method
        }

        return new self(
            $method,
            $uri,
            $queryParams,
            $postData,
            $cookies,
            $server,
            $headers,
            $body,
            $session,
            $cookie,
            $oauth
        );
    }

    private function initializeClient(): void {
        $this->client = new HttpClient('', $this->session, $this->cookie);
    }

    public function getClient(): HttpClient {
        return $this->client;
    }

    public function setClient(HttpClient $client): self {
        $this->client = $client;
        return $this;
    }

    public function withOAuth(OAuth $oauth): self {
        $this->oauth = $oauth;
        return $this;
    }

    public function getOAuth(): ?OAuth {
        return $this->oauth;
    }

    // HTTP Client Methods
    public function get(string $url, array $queryParams = [], array $headers = []): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->get($url, $queryParams);
        return $this->client->toHttpResponse($response);
    }

    public function post(string $url, $data = [], array $headers = [], bool $isJson = true): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->post($url, $data, $isJson);
        return $this->client->toHttpResponse($response);
    }

    public function put(string $url, $data = [], array $headers = [], bool $isJson = true): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->put($url, $data, $isJson);
        return $this->client->toHttpResponse($response);
    }

    public function patch(string $url, $data = [], array $headers = [], bool $isJson = true): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->patch($url, $data, $isJson);
        return $this->client->toHttpResponse($response);
    }

    public function delete(string $url, $data = [], array $headers = []): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->delete($url, $data);
        return $this->client->toHttpResponse($response);
    }

    public function head(string $url, array $headers = []): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->head($url);
        return $this->client->toHttpResponse($response);
    }

    public function options(string $url, array $headers = []): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->options($url);
        return $this->client->toHttpResponse($response);
    }

    private function applyOAuthHeaders(array &$headers): void {
        if ($this->oauth && $this->oauth->isAuthenticated()) {
            $token = $this->oauth->getStoredToken();
            if ($token && isset($token['access_token'])) {
                $headers['Authorization'] = 'Bearer ' . $token['access_token'];
            }
        }
    }

    public function forward(string $url, ?string $method = null, array $additionalData = []): HttpResponse {
        $method = $method ?? $this->method;
        $headers = $this->headers;
        
        // Remove hop-by-hop headers
        $hopHeaders = [
            'Connection', 'Keep-Alive', 'Proxy-Authenticate',
            'Proxy-Authorization', 'TE', 'Trailers', 'Transfer-Encoding', 'Upgrade'
        ];
        
        foreach ($hopHeaders as $header) {
            unset($headers[$header]);
        }

        // Forward cookies
        $this->client->useApplicationCookies();
        
        // Apply OAuth headers
        $this->applyOAuthHeaders($headers);
        
        switch (strtoupper($method)) {
            case 'GET':
                $data = array_merge($this->queryParams, $additionalData);
                return $this->get($url, $data, $headers);
            case 'POST':
                $data = array_merge($this->postData, $additionalData);
                $isJson = strpos($this->getContentType(), 'application/json') !== false;
                return $this->post($url, $data, $headers, $isJson);
                
            case 'PUT':
                $data = array_merge($this->postData, $additionalData);
                $isJson = strpos($this->getContentType(), 'application/json') !== false;
                return $this->put($url, $data, $headers, $isJson);
                
            case 'PATCH':
                $data = array_merge($this->postData, $additionalData);
                $isJson = strpos($this->getContentType(), 'application/json') !== false;
                return $this->patch($url, $data, $headers, $isJson);
                
            case 'DELETE':
                return $this->delete($url, $additionalData, $headers);
                
            default:
                throw new MachinjiriException("Unsupported HTTP method: {$method}");
        }
    }

    // Enhanced API method with OAuth
    public function api(string $url, ?string $method = null, $data = null, array $headers = []): HttpResponse {
        $method = $method ?? $this->method;
        $defaultHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
        
        $mergedHeaders = array_merge($defaultHeaders, $headers);
        
        // Apply OAuth headers
        $this->applyOAuthHeaders($mergedHeaders);
        
        // Convert headers to HttpClient format
        $curlHeaders = [];
        foreach ($mergedHeaders as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }
        
        $this->client->setHeaders($curlHeaders);
        
        switch (strtoupper($method)) {
            case 'GET':
                return $this->get($url, is_array($data) ? $data : [], $curlHeaders);
            case 'POST':
                return $this->post($url, $data, $curlHeaders, true);
            case 'PUT':
                return $this->put($url, $data, $curlHeaders, true);
            case 'PATCH':
                return $this->patch($url, $data, $curlHeaders, true);
            case 'DELETE':
                return $this->delete($url, $data, $curlHeaders);
            default:
                throw new MachinjiriException("Unsupported API method: {$method}");
        }
    }

    public function oauthApi(string $url, string $method = 'GET', $data = null, array $headers = []): HttpResponse {
        if (!$this->oauth || !$this->oauth->isAuthenticated()) {
            throw new MachinjiriException('OAuth authentication required');
        }
        
        return $this->api($url, $method, $data, $headers);
    }

    // Upload file with OAuth support
    public function upload(string $url, string $fieldName, string $filePath, array $additionalData = [], array $headers = []): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->uploadFile($url, $fieldName, $filePath, $additionalData);
        return $this->client->toHttpResponse($response);
    }

    // Download file with OAuth support
    public function download(string $url, string $savePath, array $headers = []): HttpResponse {
        $this->applyOAuthHeaders($headers);
        $this->client->setHeaders($headers);
        $response = $this->client->downloadFile($url, $savePath);
        return $this->client->toHttpResponse($response);
    }

    // Make multiple requests concurrently with OAuth
    public function batch(array $requests): array {
        $results = [];
        foreach ($requests as $key => $request) {
            $method = $request['method'] ?? 'GET';
            $url = $request['url'] ?? '';
            $data = $request['data'] ?? [];
            $headers = $request['headers'] ?? [];
            
            $this->applyOAuthHeaders($headers);
            
            switch (strtoupper($method)) {
                case 'GET':
                    $results[$key] = $this->get($url, $data, $headers);
                    break;
                case 'POST':
                    $results[$key] = $this->post($url, $data, $headers, true);
                    break;
                case 'PUT':
                    $results[$key] = $this->put($url, $data, $headers, true);
                    break;
                case 'PATCH':
                    $results[$key] = $this->patch($url, $data, $headers, true);
                    break;
                case 'DELETE':
                    $results[$key] = $this->delete($url, $data, $headers);
                    break;
            }
        }
        
        return $results;
    }

    // Configure client options
    public function withOptions(array $options): self {
        foreach ($options as $key => $value) {
            $this->client->setOption($key, $value);
        }
        return $this;
    }

    public function withTimeout(int $timeout): self {
        $this->client->setTimeout($timeout);
        return $this;
    }

    public function withAuth(string $type, ...$credentials): self {
        switch (strtolower($type)) {
            case 'basic':
                $this->client->setBasicAuth($credentials[0] ?? '', $credentials[1] ?? '');
                break;
            case 'bearer':
                $this->client->setBearerToken($credentials[0] ?? '');
                break;
            case 'oauth':
                if ($credentials[0] instanceof OAuth) {
                    $this->withOAuth($credentials[0]);
                }
                break;
        }
        return $this;
    }

    public function withRetry(int $maxRetries = 3, int $retryDelay = 1000): self {
        $this->client->setRetryOptions($maxRetries, $retryDelay);
        return $this;
    }

    public function withProxy(string $proxy, ?int $port = null, ?string $username = null, ?string $password = null): self {
        $this->client->setProxy($proxy, $port, $username, $password);
        return $this;
    }

    public function withCookies(bool $useSessionCookies = false, bool $useApplicationCookies = true): self {
        if ($useSessionCookies) {
            $this->client->useSessionCookies();
        }
        if ($useApplicationCookies) {
            $this->client->useApplicationCookies();
        }
        return $this;
    }

    public function getSession(): ?Session {
        return $this->session;
    }

    public function getCookieHandler(): ?Cookie {
        return $this->cookie;
    }

    private static function getAllHeaders(): array {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            } elseif ($name == 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($name == 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }
        return $headers;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getUri(): string {
        return $this->uri;
    }

    public function getPath(): string {
        return parse_url($this->uri, PHP_URL_PATH) ?: '/';
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getQueryParam(string $key, $default = null) {
        return $this->queryParams[$key] ?? $default;
    }

    public function getPostData(): array {
        return $this->postData;
    }

    public function getPostParam(string $key, $default = null) {
        return $this->postData[$key] ?? $default;
    }

    public function getCookies(): array {
        return $this->cookies;
    }

    public function getCookie(string $key, $default = null) {
        return $this->cookies[$key] ?? $default;
    }

    public function getServer(): array {
        return $this->server;
    }

    public function getServerParam(string $key, $default = null) {
        return $this->server[$key] ?? $default;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getHeader(string $name, $default = null) {
        $name = strtolower($name);
        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === $name) {
                return $value;
            }
        }
        return $default;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function getJsonBody(bool $assoc = true) {
        $contentType = $this->getHeader('Content-Type', '');
        
        // Check if content type is JSON
        if (strpos($contentType, 'application/json') === false) {
            return null;
        }

        $data = json_decode($this->body, $assoc);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MachinjiriException('JSON decoding failed: ' . json_last_error_msg());
        }
        
        return $data;
    }

    public function isAjax(): bool {
        return $this->getHeader('X-Requested-With') === 'XMLHttpRequest' ||
               $this->getHeader('X-Requested-With') === 'Fetch' ||
               strpos($this->getHeader('Accept', ''), 'application/json') !== false;
    }

    public function expectsJson(): bool {
        $accept = $this->getHeader('Accept', '');
        return strpos($accept, 'application/json') !== false ||
               strpos($accept, '*/*') !== false ||
               $this->isAjax();
    }

    public function isGet(): bool {
        return $this->method === 'GET';
    }

    public function isPost(): bool {
        return $this->method === 'POST';
    }

    public function isPut(): bool {
        return $this->method === 'PUT';
    }

    public function isDelete(): bool {
        return $this->method === 'DELETE';
    }

    public function isPatch(): bool {
        return $this->method === 'PATCH';
    }

    public function isOptions(): bool {
        return $this->method === 'OPTIONS';
    }

    public function isSecure(): bool {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
               (!empty($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (!empty($this->server['HTTP_X_FORWARDED_SSL']) && $this->server['HTTP_X_FORWARDED_SSL'] === 'on') ||
               (!empty($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] == 443);
    }

    public function getIp(): string {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($this->server[$key])) {
                $ip = $this->server[$key];
                
                // Handle multiple IPs in X-Forwarded-For
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function getUserAgent(): string {
        return $this->getHeader('User-Agent', '');
    }

    public function getReferer(): string {
        return $this->getHeader('Referer', '');
    }

    public function getContentType(): string {
        return $this->getHeader('Content-Type', '');
    }

    public function getContentLength(): int {
        return (int) $this->getHeader('Content-Length', 0);
    }

    public function getAccept(): string {
        return $this->getHeader('Accept', '');
    }

    public function getAcceptLanguage(): string {
        return $this->getHeader('Accept-Language', '');
    }

    public function getAcceptEncoding(): string {
        return $this->getHeader('Accept-Encoding', '');
    }

    public function setAttribute(string $name, $value): self {
        $this->attributes[$name] = $value;
        return $this;
    }

    public function getAttribute(string $name, $default = null) {
        return $this->attributes[$name] ?? $default;
    }

    public function getAttributes(): array {
        return $this->attributes;
    }

    public function hasAttribute(string $name): bool {
        return isset($this->attributes[$name]);
    }

    public function removeAttribute(string $name): self {
        unset($this->attributes[$name]);
        return $this;
    }

    public function all(): array {
        return array_merge($this->queryParams, $this->postData);
    }

    public function input(string $key, $default = null) {
        return $this->postData[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function only(array $keys): array {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->input($key);
        }
        return $results;
    }

    public function except(array $keys): array {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    public function has(string $key): bool {
        return isset($this->postData[$key]) || isset($this->queryParams[$key]);
    }

    public function filled(string $key): bool {
        $value = $this->input($key);
        return !empty($value);
    }

}