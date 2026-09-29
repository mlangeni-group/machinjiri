<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Providers\Contracts;

/**
 * Implement on any deferred ServiceProvider whose service list can be
 * statically declared. Enables manifest caching and opcache preloading
 * without ever instantiating the provider at boot time.
 */
interface DeferredProviderInterface
{
    /**
     * Services the provider will register.
     *
     * @return list<string>
     */
    public static function providesStatic(): array;

    /**
     * Optional: extra trigger services (aliases, tags, interfaces).
     *
     * @return list<string>
     */
    public static function when(): array;
}