<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Metrics;

interface MetricInterface
{
    public function name(): string;
    /** @return array<string, mixed> */
    public function snapshot(): array;
}