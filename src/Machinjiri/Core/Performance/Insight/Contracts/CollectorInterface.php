<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Contracts;

use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

interface CollectorInterface
{
    /**
     * Unique key identifying this collector (e.g. "system", "health").
     */
    public function key(): string;

    /**
     * Collect metrics into the given bag.
     */
    public function collect(MetricBag $bag): void;
}