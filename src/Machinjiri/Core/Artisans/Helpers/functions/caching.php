<?php

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

if (!function_exists('cache_manager')) {
    /**
     * Get the CacheManager instance
     *
     * @return CacheManager
     */
    function cache_manager(): CacheManager
    {
        $app = app();

        if ($app->bound(CacheManager::class)) {
            return $app->resolve(CacheManager::class);
        }

        return new CacheManager(config('cache'));

    }
}