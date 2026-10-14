<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Contracts;

use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

interface ReporterInterface {

    public function report(MetricBag $bag): string|Array;

}