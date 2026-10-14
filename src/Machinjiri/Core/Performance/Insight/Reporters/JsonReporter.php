<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Reporters;

use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\ReporterInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

final class JsonReporter implements ReporterInterface
{
    public function __construct(private readonly int $flags = JSON_PRETTY_PRINT) {}

    public function report(MetricBag $bag): string
    {
        return json_encode([
            'metrics'    => $bag->all(),
            'thresholds' => $bag->thresholds(),
            'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $this->flags | JSON_THROW_ON_ERROR);
    }
}