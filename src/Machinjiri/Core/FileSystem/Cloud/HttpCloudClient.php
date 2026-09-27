<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Cloud;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\TokenBucket;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\CloudClientInterface;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\TokenProviderInterface;

class HttpCloudClient implements CloudClientInterface
{
    private ?TokenProviderInterface $tokenProvider = null;
    private ?TokenBucket $rateLimiter = null;
    private ?object $logger = null;
    private array $defaultHeaders;

    public function __construct(
        private HttpRequest $http,
        array $defaultHeaders = []
    ) {
        $this->defaultHeaders = $defaultHeaders;
    }

    public function setLogger(object $logger): self
    {
        $this->logger = $logger;
        $this->http->getClient()->setLogger($logger);
        return $this;
    }

    public function setTokenProvider(?TokenProviderInterface $provider): self
    {
        $this->tokenProvider = $provider;
        return $this;
    }

    /**
     * Enable client-side rate limiting.
     *
     * @param float $requestsPerSecond  Sustained rate.
     * @param int   $burst              Maximum instantaneous burst.
     */
    public function withRateLimit(float $requestsPerSecond, int $burst = 10): self
    {
        $this->rateLimiter = new TokenBucket($burst, $requestsPerSecond);
        return $this;
    }

    public function send(string $method, string $uri, array $options = []): CloudResponse
    {
        $this->rateLimiter?->acquire();

        $headers = $this->buildHeaders($options['headers'] ?? []);

        if (isset($options['query'])) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $body = $options['body'] ?? [];

        $started = microtime(true);
        $response = $this->http->api($uri, strtoupper($method), $body, $headers);
        $elapsedMs = (int) ((microtime(true) - $started) * 1000);

        $this->logger?->debug('cloud.request', [
            'method'   => strtoupper($method),
            'uri'      => $uri,
            'status'   => $response->getStatusCode(),
            'duration' => $elapsedMs,
        ]);

        return new CloudResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody()
        );
    }

    /**
     * Streams the response body into a php://temp resource.
     *
     * @return resource
     */
    public function stream(string $method, string $uri, array $options = [])
    {
        $this->rateLimiter?->acquire();

        $headers = $this->toCurlHeaders($this->buildHeaders($options['headers'] ?? []));
        if (isset($options['query'])) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        return $this->http->getClient()->streamRequest(
            strtoupper($method),
            $uri,
            $headers,
            $options['body'] ?? null
        );
    }

    /* ----------------------------------------------------------------- */

    private function buildHeaders(array $extra): array
    {
        $headers = array_merge($this->defaultHeaders, $extra);

        if ($this->tokenProvider !== null && !$this->hasHeader($headers, 'Authorization')) {
            $token = $this->tokenProvider->getToken();
            if (!empty($token['token'])) {
                $headers['Authorization'] = 'Bearer ' . $token['token'];
            }
        }
        return $headers;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp($key, $name) === 0) return true;
        }
        return false;
    }

    /** Convert associative header map to cURL-style array. */
    private function toCurlHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = "{$k}: {$v}";
        }
        return $out;
    }
}