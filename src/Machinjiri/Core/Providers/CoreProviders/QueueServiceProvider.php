<?php

namespace Mlangeni\Machinjiri\Core\Providers\CoreProviders;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\{QueueInterface, BaseWorker, BaseJobDispatcher};
use Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers\{DatabaseQueue, FileQueue, MemoryQueue, RedisQueue, SyncQueue};

class QueueServiceProvider extends ServiceProvider
{
    /**
     * Register QueueService Services
     */
    public function register(): void
    {
        // Register queue bindings
        $this->bind('queue', function($app) {
            $config = $app->getConfigurations()['queue'] ?? [];
            $driver = $config['default'] ?? getenv('QUEUE_DRIVER');
            return $this->createQueueDriver($driver, $config);
        });
        
        // Register queue worker
        $this->singleton(BaseWorker::class, function($app) {
            $queue = $app->resolve('queue');
            $processor = $app->resolve('queue.processor');
            return new BaseWorker($app, $queue, $processor);
        });
        
        // Register job processor
        $this->singleton('queue.processor', function($app) {
            return new class($app) extends \Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJobProcessor {};
        });
        
        // Register job dispatcher
        $this->singleton(BaseJobDispatcher::class, function($app) {
            $queue = $app->resolve('queue');
            return new BaseJobDispatcher($app, $queue);
        });

        $this->aliasMany([
            'queue.dispatcher'        => BaseJobDispatcher::class,
            'queue.worker'            => BaseWorker::class,
        ]);
        
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Load queue configuration
        $this->mergeConfigFrom($this->app->coreConfig . 'queue.php', 'queue');
        
        // Create jobs table if using database driver
        $this->createJobsTableIfNeeded();
        
    }

    /**
     * Create queue driver instance
     */
    protected function createQueueDriver(string $driver, array $config): QueueInterface
    {
        $driverConfig = $config['drivers'][$driver] ?? [];
        
        switch ($driver) {
            case 'database':
                return new DatabaseQueue($this->app, $driver, $driverConfig);
            case 'redis':
                return new RedisQueue($this->app, $driver, $driverConfig);
            case 'file':
                return new FileQueue($this->app, $driver, $driverConfig);
            case 'memory':
                return new MemoryQueue($this->app, $driver, $driverConfig);
            case 'sync':
                return new SyncQueue($this->app, $driver, $driverConfig);
            default:
                // Try to load custom driver
                $driverClass = "App\\Queue\\Drivers\\" . ucfirst($driver) . 'Queue';
                if (class_exists($driverClass)) {
                    return new $driverClass($this->app, $driver, $driverConfig);
                }
                
                throw new MachinjiriException("Queue driver not found: {$driver}. Try running php artisan queue:init");
        }
    }

    /**
     * Create jobs table if needed
     */
    protected function createJobsTableIfNeeded(): void
    {
        $config = $this->getConfigurations()['queue'] ?? [];
        $driver = $config['default'] ?? 'sync';
        
        if ($driver === 'database') {
            $table = $config['drivers']['database']['table'] ?? 'jobs';
            
            $query = new \Mlangeni\Machinjiri\Core\Database\QueryBuilder('');
            $sql = $query->createTable($table, [
                'id' => $query->id()->primary()->autoincrement(),
                'queue' => $query->string('queue', 255)->notNull(),
                'payload' => $query->text('payload'),
                'attempts' => $query->integer('attempts')->default(0),
                'reserved_at' => $query->integer('reserved_at')->default(0),
                'available_at' => $query->integer('available_at')->notNull(),
                'created_at' => $query->integer('created_at')->notNull(),
            ], ['if_not_exists' => true])->compileCreateTable();
            
            \Mlangeni\Machinjiri\Core\Database\DatabaseConnection::executeQuery($sql);
        }
    }
}