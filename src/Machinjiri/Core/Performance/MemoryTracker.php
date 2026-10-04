<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance;

final class MemoryTracker
{
    private int $peakStart;
    private int $baseline;

    public function __construct()
    {
        $this->baseline = memory_get_usage(true);
        $this->peakStart = memory_get_peak_usage(true);
    }

    public function current(): int
    {
        return memory_get_usage(true);
    }

    public function peak(): int
    {
        return memory_get_peak_usage(true);
    }

    public function delta(): int
    {
        return $this->current() - $this->baseline;
    }

    public function peakDelta(): int
    {
        return $this->peak() - $this->peakStart;
    }

    public static function format(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $v, $units[$i]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'current' => $this->current(),
            'peak' => $this->peak(),
            'delta' => $this->delta(),
            'peak_delta' => $this->peakDelta(),
        ];
    }
}