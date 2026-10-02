<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Collectors;

interface CollectorInterface
{
    public function name(): string;
    /** @return array<string, mixed> */
    public function collect(): array;
}