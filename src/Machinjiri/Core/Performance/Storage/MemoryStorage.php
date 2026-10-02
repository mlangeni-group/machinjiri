<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Storage;

final class MemoryStorage implements StorageInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    public function save(array $report): void
    {
        $id = $report['id'] ?? bin2hex(random_bytes(8));
        $this->store[$id] = $report;
    }

    public function load(string $id): ?array
    {
        return $this->store[$id] ?? null;
    }

    public function list(int $limit = 50): array
    {
        return array_slice(array_keys($this->store), -$limit);
    }
}