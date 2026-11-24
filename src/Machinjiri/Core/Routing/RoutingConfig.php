<?php

namespace Mlangeni\Machinjiri\Core\Routing;

class RoutingConfig
{
    public function __construct(
        public string $cacheFile = '',
        public string $errorsDir = '',
        public string $controllersNamespace = 'Mlangeni\\Machinjiri\\App\\Http\\Controllers',
        public array $rateLimiters = [],
        public string $viewsBasePath = '',
        public string $viewsCachePath = '',
        public bool $enableCsrf = true,
        public array $corsDefaults = [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['*'],
            'max_age' => 86400,
        ]
    ) {
        // Auto-detect paths if not provided
        $projectRoot = dirname(\Mlangeni\Machinjiri\Core\Container::$appBasePath);
        if (empty($this->cacheFile)) {
            $this->cacheFile = $projectRoot . '/storage/cache/routes.cache';
        }
        if (empty($this->errorsDir)) {
            $this->errorsDir = $projectRoot . '/resources/errors';
        }
        if (empty($this->viewsBasePath)) {
            $this->viewsBasePath = $projectRoot . '/resources/views';
        }
        if (empty($this->viewsCachePath)) {
            $this->viewsCachePath = $projectRoot . '/storage/cache/views';
        }
    }
}