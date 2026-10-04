<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Collectors;

final class ExceptionCollector implements CollectorInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $exceptions = [];

    public function record(\Throwable $e): void
    {
        $this->exceptions[] = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'time' => microtime(true),
        ];
    }

    public function name(): string { return 'exceptions'; }

    /** @return array<int, array<string, mixed>> */
    public function exceptions(): array { return $this->exceptions; }

    public function collect(): array
    {
        return ['count' => count($this->exceptions), 'items' => $this->exceptions];
    }
}