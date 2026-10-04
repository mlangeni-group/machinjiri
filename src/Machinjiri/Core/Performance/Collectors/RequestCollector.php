<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Collectors;

final class RequestCollector implements CollectorInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function capture(): void
    {
        $this->data = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'started_at' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
        ];
    }

    public function name(): string { return 'request'; }

    public function collect(): array { return $this->data; }
}