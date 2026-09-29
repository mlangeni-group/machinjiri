<?php

namespace Mlangeni\Machinjiri\Core\Views\Contracts;

interface AssetManagerInterface
{
    public function asset(string $path): string;
    public function style(string $path, array $attributes = []): void;
    public function script(string $path, array $attributes = []): void;
    public function setAssetsPath(string $path): void;
    public function setAssetsUrl(string $url): void;
    public function getAssetsPath(): string;
    public function getAssetsUrl(): string;
    public function buildAttributes(array $attributes): string;
    public function flushTimestampCache(): void;
}