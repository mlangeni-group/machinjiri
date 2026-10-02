<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Collectors;

final class QueryCollector implements CollectorInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $queries = [];
    private float $totalTime = 0.0;

    public function record(string $sql, float $durationMs, array $params = [], ?string $connection = null): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'duration_ms' => $durationMs,
            'params' => $params,
            'connection' => $connection,
            'time' => microtime(true),
        ];
        $this->totalTime += $durationMs;
    }

    public function name(): string { return 'queries'; }

    /** @return array<int, array<string, mixed>> */
    public function queries(): array { return $this->queries; }

    public function count(): int { return count($this->queries); }
    public function totalTime(): float { return $this->totalTime; }

    public function collect(): array
    {
        return [
            'count' => $this->count(),
            'total_ms' => round($this->totalTime, 4),
            'slowest' => $this->slowest(5),
            'queries' => $this->queries,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function slowest(int $n): array
    {
        $q = $this->queries;
        usort($q, fn($a, $b) => $b['duration_ms'] <=> $a['duration_ms']);
        return array_slice($q, 0, $n);
    }
}