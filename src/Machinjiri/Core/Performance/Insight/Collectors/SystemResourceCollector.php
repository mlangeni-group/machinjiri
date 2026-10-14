<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Collectors;

use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\CollectorInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

final class SystemResourceCollector implements CollectorInterface
{
    public function key(): string
    {
        return 'system';
    }

    public function collect(MetricBag $bag): void
    {
        $bag->set('system.cpu', $this->cpuLoad());
        $bag->set('system.memory', $this->memory());
        $bag->set('system.disk', $this->disk());
    }

    /** @return array{load_1m: float|null, load_5m: float|null, load_15m: float|null, cores: int|null, percent: float|null} */
    private function cpuLoad(): array
    {
        $loads = null;
        if (function_exists('sys_getloadavg')) {
            $loads = sys_getloadavg() ?: null;
        }

        $cores = $this->detectCores();

        $percent = null;
        if (is_array($loads) && $cores !== null && $cores > 0) {
            $percent = round(($loads[0] / $cores) * 100, 2);
        }

        return [
            'load_1m'  => isset($loads[0]) ? round($loads[0], 4) : null,
            'load_5m'  => isset($loads[1]) ? round($loads[1], 4) : null,
            'load_15m' => isset($loads[2]) ? round($loads[2], 4) : null,
            'cores'    => $cores,
            'percent'  => $percent,
        ];
    }

    private function detectCores(): ?int
    {
        // Linux
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // macOS / BSD fallback
        if (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $out = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (is_string($out) && is_numeric(trim($out))) {
                return (int) trim($out);
            }
        }

        return null;
    }

    /** @return array{total: int|null, used: int|null, free: int|null, available: int|null, percent: float|null} */
    private function memory(): array
    {
        $total     = null;
        $free      = null;
        $available = null;

        if (is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo') ?: '';
            $total   = $this->parseMeminfoKb($meminfo, 'MemTotal');
            $free    = $this->parseMeminfoKb($meminfo, 'MemFree');
            $available = $this->parseMeminfoKb($meminfo, 'MemAvailable')
                ?? $this->parseMeminfoKb($meminfo, 'MemFree');
        }

        $used    = ($total !== null && $available !== null) ? $total - $available : null;
        $percent = ($total !== null && $used !== null && $total > 0)
            ? round(($used / $total) * 100, 2)
            : null;

        return [
            'total'     => $total,
            'used'      => $used,
            'free'      => $free,
            'available' => $available,
            'percent'   => $percent,
            'php'       => [
                'current_usage' => memory_get_usage(true),
                'peak_usage'    => memory_get_peak_usage(true),
                'limit'         => $this->phpMemoryLimit(),
            ],
        ];
    }

    private function parseMeminfoKb(string $meminfo, string $key): ?int
    {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s+kB/m', $meminfo, $m)) {
            return ((int) $m[1]) * 1024;
        }
        return null;
    }

    private function phpMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return -1;
        }
        return $this->toBytes($limit);
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit  = strtolower(substr($value, -1));
        $num   = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    /** @return array{total: int|null, free: int|null, used: int|null, percent: float|null, path: string} */
    private function disk(): array
    {
        $path  = defined('INSIGHT_DISK_PATH') ? INSIGHT_DISK_PATH : (getcwd() ?: '/');
        $total = @disk_total_space($path) ?: null;
        $free  = @disk_free_space($path) ?: null;
        $used  = ($total !== null && $free !== null) ? $total - $free : null;
        $percent = ($total !== null && $used !== null && $total > 0)
            ? round(($used / $total) * 100, 2)
            : null;

        return [
            'path'    => $path,
            'total'   => $total,
            'free'    => $free,
            'used'    => $used,
            'percent' => $percent,
        ];
    }
}