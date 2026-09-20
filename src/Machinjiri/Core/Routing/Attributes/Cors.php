<?php

namespace Mlangeni\Machinjiri\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Cors
{
    public function __construct(
        public array $allowedOrigins = ['*'],
        public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        public array $allowedHeaders = ['*'],
        public int $maxAge = 86400
    ) {}

    public function toConfig(): array
    {
        return [
            'allowed_origins' => $this->allowedOrigins,
            'allowed_methods' => $this->allowedMethods,
            'allowed_headers' => $this->allowedHeaders,
            'max_age'         => $this->maxAge,
        ];
    }
}