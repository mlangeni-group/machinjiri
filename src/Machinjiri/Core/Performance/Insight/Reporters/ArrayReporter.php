<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Reporters;

use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\ReporterInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

final class ArrayReporter implements ReporterInterface
{
    public function report(MetricBag $bag): array
    {
        return [
            'metrics'    => $bag->all(),
            'thresholds' => $bag->thresholds(),
            'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}