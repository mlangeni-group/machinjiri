<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Contracts;

use Mlangeni\Machinjiri\Core\FileSystem\Cloud\HttpCloudClient;
use Mlangeni\Machinjiri\Core\FileSystem\Cloud\CloudResponse;
use Mlangeni\Machinjiri\Core\FileSystem\Contracts\TokenProviderInterface;

interface CloudClientInterface
{ 
    public function send(string $method, string $uri, array $options = []): CloudResponse;

    /** @return resource */
    public function stream(string $method, string $uri, array $options = []);

    public function setTokenProvider(?TokenProviderInterface $provider): HttpCloudClient;

    public function withRateLimit(float $requestsPerSecond, int $burst = 10): HttpCloudClient;
}