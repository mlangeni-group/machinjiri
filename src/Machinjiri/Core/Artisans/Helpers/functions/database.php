<?php

use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\Caching\CachedQueryBuilder;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

if (!function_exists('query_builder')) {
    /**
     * Get the QueryBuilder instance
     *
     * @param string $table
     * @return QueryBuilder
     */
    function query_builder(string $table = ''): QueryBuilder
    {
        return new QueryBuilder($table);
    }
}

if (!function_exists('cached_query_builder')) {
    /**
     * Get the CachedQueryBuilder instance
     *
     * @param string $table
     * @return CachedQueryBuilder
     */
    function cached_query_builder(string $table = ''): CachedQueryBuilder
    {
        return new CachedQueryBuilder(new QueryBuilder($table), resolve(CacheManager::class));
    }
}