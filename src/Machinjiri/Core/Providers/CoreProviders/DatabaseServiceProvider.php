<?php

namespace Mlangeni\Machinjiri\Core\Providers\CoreProviders;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Seeder\SeederManager;
use Mlangeni\Machinjiri\Core\Database\Factory\FactoryManager;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationCreator;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Database\Caching\PrefetchManager;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register core application services
     */
    public function register(): void
    {
        $this->singleton('db.kernel.connection', function($app) {
          $config = $app->getConfigurations()['database'];
          DatabaseConnection::setConfig($config);
          DatabaseConnection::setPath($app->database);
          return DatabaseConnection::getInstance();
        });
        
        $this->singleton(MigrationCreator::class, function ($app) {
          return new MigrationCreator($app);
        });
        
        $this->singleton(MigrationHandler::class, function ($app) {
          return new MigrationHandler($app, $app->resolve('db.kernel.connection'));
        });
        
        $this->singleton(SeederManager::class, function ($app) {
          return new SeederManager($app);
        });
        
        $this->singleton(FactoryManager::class, function ($app) {
          return new FactoryManager($app);
        });
        
        $this->aliasMany([
          'db.migration.creator' => MigrationCreator::class,
          'db.migration.handler' => MigrationHandler::class,
          'db.seeder.manager' => SeederManager::class,
          'db.factory.manager' => FactoryManager::class,
        ]);
    }
    
    public function boot(): void
    {
      try {
        $this->prefetchDatabase();
      } catch (MachinjiriException $machinjiriException) {
        $machinjiriException->show();
      }
    }
    
    /**
     * Prefetch database queries to cache.
     * @return void
     */
    public function prefetchDatabase(): void
    {
        $prefetchEnabled = filter_var(env('DB_PREFETCH') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if ($prefetchEnabled) {
          $prefetchFile = $this->app->database . "cache-prefetch-db.php";
          if (!is_dir($this->app->database) || !is_file($prefetchFile)) {
            throw new MachinjiriException("Unable to find Prefetch Database file. [cache-prefetch-db.php]");
          }
          
          $warmers = require $prefetchFile;
          
          if (!is_array($warmers)) {
              throw new MachinjiriException("Prefetch file must return an array of callbacks in {$prefetchFile}");
          }
          
          if (!$this->app->bound(CacheManager::class)) {
            throw new MachinjiriException('CacheManager not bound – cannot prefetch database queries');
          }
          
          $cacheManager = $this->resolve(CacheManager::class);
          $prefetchManager = new PrefetchManager($cacheManager);
          $logger = new Logger("db-prefetch-provider");
          
          foreach ($warmers as $name => $callback) {
            if (!is_callable($callback)) {
                $logger->warning("Prefetch warmer {$name} is not callable, skipping");
                continue;
            }
      
            try {
              $callback($prefetchManager);
              $logger->info("Database prefetch warmer executed \n table => {warmer}", ['warmer' => $name]);
            } catch (MachinjiriException $e) {
              throw new MachinjiriException(" Failed to Warmer on table '{$name}' due to: " . $e->getMessage());
            }
          }
        }
    }
    /**
     * Get the services provided by the provider
     */
    public function provides(): array
    {
        return array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->aliases)
        );
    }
}