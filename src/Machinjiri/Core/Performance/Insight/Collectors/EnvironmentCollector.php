<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Performance\Insight\Collectors;

use Mlangeni\Machinjiri\Core\Performance\Insight\Contracts\CollectorInterface;
use Mlangeni\Machinjiri\Core\Performance\Insight\Metrics\MetricBag;

final class EnvironmentCollector implements CollectorInterface
{
    public function __construct(
        private readonly ?bool $debug = null,
    ) {}

    public function key(): string
    {
        return 'environment';
    }

    public function collect(MetricBag $bag): void
    {
        $bag->set('environment.php', [
            'version'  => PHP_VERSION,
            'sapi'     => PHP_SAPI,
            'os'       => PHP_OS,
            'family'   => PHP_OS_FAMILY,
        ]);

        $bag->set('environment.extensions', $this->extensions());
        $bag->set('environment.debug_mode', $this->debugMode());
        $bag->set('environment.ini', [
            'memory_limit'        => ini_get('memory_limit'),
            'max_execution_time'  => ini_get('max_execution_time'),
            'display_errors'      => (bool) ini_get('display_errors'),
            'error_reporting'     => (int) ini_get('error_reporting'),
            'opcache_enabled'     => (bool) ini_get('opcache.enable'),
        ]);
    }

    /** @return array<int, string> */
    private function extensions(): array
    {
        $loaded = get_loaded_extensions();
        sort($loaded);
        return $loaded;
    }

    private function debugMode(): bool
    {
        if ($this->debug !== null) {
            return $this->debug;
        }

        $env = getenv('APP_DEBUG');

        if ($env !== false) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) ini_get('display_errors');
    }
}