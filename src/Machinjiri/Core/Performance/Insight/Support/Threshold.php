<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Support;

final class Threshold
{
    public function __construct(
        public readonly float|int $warning,
        public readonly float|int $critical,
        public readonly string $unit = '',
    ) {}

    public function evaluate(float|int $value): string
    {
        if ($value >= $this->critical) {
            return 'critical';
        }
        if ($value >= $this->warning) {
            return 'warning';
        }
        return 'ok';
    }
}