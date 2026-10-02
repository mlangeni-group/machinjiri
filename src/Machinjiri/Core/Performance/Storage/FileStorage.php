<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Storage;

final class FileStorage implements StorageInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create storage dir: {$directory}");
        }
    }

    public function save(array $report): void
    {
        $id = $report['id'] ?? bin2hex(random_bytes(8));
        $file = $this->path($id);
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        rename($tmp, $file);
    }

    public function load(string $id): ?array
    {
        $file = $this->path($id);
        if (!is_file($file)) return null;
        $json = file_get_contents($file);
        return $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function list(int $limit = 50): array
    {
        $files = glob($this->directory . '/*.json') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return array_map(fn($f) => basename($f, '.json'), array_slice($files, 0, $limit));
    }

    private function path(string $id): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
        return $this->directory . '/' . $safe . '.json';
    }
}