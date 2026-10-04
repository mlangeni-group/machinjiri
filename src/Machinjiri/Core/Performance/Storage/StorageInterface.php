<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Storage;

interface StorageInterface
{
    /** @param array<string, mixed> $report */
    public function save(array $report): void;

    /** @return array<string, mixed>|null */
    public function load(string $id): ?array;

    /** @return string[] */
    public function list(int $limit = 50): array;
}