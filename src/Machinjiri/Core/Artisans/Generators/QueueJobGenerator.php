<?php
/**
 * QueueJobGenerator
 *
 * Generates queue and job templates for the Machinjiri framework.
 *
 * Responsibilities:
 *  - Create job templates in app/Jobs/ directory
 *  - Create queue driver templates in app/Queue/Drivers/ directory
 *  - Generate configuration files for queue system
 *  - Create migration for jobs table
 *  - Generate queue service provider
 *
 * Implementation notes:
 *  - Follows the same patterns as ServiceProviderGenerator
 *  - Integrates with existing Container and path structure
 *  - Provides stubs for different job and queue types
 */

namespace Mlangeni\Machinjiri\Core\Artisans\Generators;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class QueueJobGenerator
{
    /**
     * Application base path
     *
     * @var string
     */
    private string $appBasePath;

    /**
     * Source directory for app files
     *
     * @var string
     */
    private string $srcPath;

    /**
     * Jobs directory
     *
     * @var string
     */
    private string $jobsPath;

    /**
     * Queue drivers directory
     *
     * @var string
     */
    private string $queuesPath;

    /**
     * Configuration directory
     *
     * @var string
     */
    private string $configPath;

    /**
     * Migrations directory
     *
     * @var string
     */
    private string $migrationsPath;

    /**
     * Constructor
     *
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->appBasePath = realpath(rtrim($container->getRootPath(), DIRECTORY_SEPARATOR));
        $this->srcPath = $this->appBasePath . '/src/Machinjiri/';
        $this->jobsPath = $this->appBasePath . '/app/Jobs/';
        $this->queuesPath = $this->appBasePath . '/app/Queue/Drivers/';
        $this->configPath = $this->appBasePath . '/config/';
        $this->migrationsPath = $this->appBasePath . '/database/migrations/';
    }

    /**
     * Generate a new job
     *
     * @param string $name Job name (without Job suffix)
     * @param array $options Generation options
     * @return string Created file path
     * @throws MachinjiriException
     */
    public function generateJob(string $name, array $options = []): string
    {
        $name = $this->normalizeJobName($name);
        
        // Validate name
        $this->validateJobName($name);
        
        // Get options
        $type = $options['type'] ?? 'standard';
        $queue = $options['queue'] ?? 'default';
        $maxAttempts = $options['max_attempts'] ?? 3;
        $timeout = $options['timeout'] ?? 60;
        $delay = $options['delay'] ?? 0;
        $sync = $options['sync'] ?? false;
        $withDatabase = $options['database'] ?? false;
        
        // Ensure jobs directory exists
        $this->ensureDirectoryExists($this->jobsPath);
        
        $jobFile = $this->jobsPath . $name . '.php';
        
        // Generate template based on type
        $template = $this->generateJobTemplate($name, [
            'type' => $type,
            'queue' => $queue,
            'max_attempts' => $maxAttempts,
            'timeout' => $timeout,
            'delay' => $delay,
            'sync' => $sync,
        ]);
        
        // Write file
        if (file_put_contents($jobFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create job file: {$jobFile}",
                91001
            );
        }
        
        // Create database migration if requested
        if ($withDatabase && $type === 'model') {
            $this->createJobMigration($name);
        }
        
        // Update configuration if requested
        if ($options['register'] ?? false) {
            $this->registerJobInConfig($name, [
                'queue' => $queue,
                'max_attempts' => $maxAttempts,
                'timeout' => $timeout,
            ]);
        }
        
        // Register job in command if requested
        if ($options['command'] ?? true) {
            $this->registerJobInCommand($name, $queue);
        }
        
        return $jobFile;
    }

    /**
     * Normalize job name
     *
     * @param string $name
     * @return string
     */
    private function normalizeJobName(string $name): string
    {
        // Remove "Job" suffix if present
        $name = preg_replace('/Job$/i', '', $name);
        
        // Convert to PascalCase
        $name = str_replace(['-', '_', ' '], '', ucwords($name, '-_ '));
        
        // Add Job suffix
        return $name . 'Job';
    }

    /**
     * Validate job name
     *
     * @param string $name
     * @throws MachinjiriException
     */
    private function validateJobName(string $name): void
    {
        // Check if name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*Job$/', $name)) {
            throw new MachinjiriException(
                "Invalid job name: {$name}. Name must be in PascalCase and end with Job.",
                91002
            );
        }
        
        // Check if job already exists
        $jobFile = $this->jobsPath . $name . '.php';
        if (file_exists($jobFile)) {
            throw new MachinjiriException(
                "Job already exists: {$name}",
                91003
            );
        }
        
        // Check if class already exists
        $className = "App\\Jobs\\{$name}";
        if (class_exists($className)) {
            throw new MachinjiriException(
                "Job class already exists: {$className}",
                91004
            );
        }
    }

    /**
     * Generate job template
     *
     * @param string $name
     * @param array $options
     * @return string
     */
    private function generateJobTemplate(string $name, array $options): string
    {
        $shortName = str_replace('Job', '', $name);
        $type = $options['type'];
        $queue = $options['queue'];
        $maxAttempts = $options['max_attempts'];
        $timeout = $options['timeout'];
        $delay = $options['delay'];
        $sync = $options['sync'] ? 'true' : 'false';
        
        // Different templates for different job types
        switch ($type) {
            case 'email':
              return $this->generateMailJobTemplate($shortName, $queue, $maxAttempts, $timeout, $delay);
            case 'model':
                return $this->generateModelJobTemplate($shortName, $queue, $maxAttempts, $timeout, $delay);
            default:
                return $this->generateStandardJobTemplate($shortName, $queue, $maxAttempts, $timeout, $delay);
        }
    }

    /**
     * Generate standard job template
     */
    private function generateStandardJobTemplate(string $name, string $queue, int $maxAttempts, int $timeout, int $delay): string
    {
        $lowerName = strtolower($name);
        
        return <<<PHP
<?php

namespace App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;

/**
 * {$name} Job
 *
 * This job handles {$lowerName} processing tasks.
 * It will be queued on the '{$queue}' queue with a maximum of {$maxAttempts} attempts.
 */
class {$name}Job extends BaseJob
{
    /**
     * Create a new job instance
     */
    public function __construct(\Mlangeni\Machinjiri\Core\Container \$app, array \$payload = [], array \$options = [])
    {
        // Set default options
        \$defaultOptions = [
            'maxAttempts' => {$maxAttempts},
            'queue' => '{$queue}',
            'timeout' => {$timeout},
            'delay' => {$delay},
        ];
        
        parent::__construct(\$app, \$payload, array_merge(\$defaultOptions, \$options));
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        // Get payload data
        \$data = \$this->getPayload();
        
        // TODO: Implement your job logic here
        
        // Example: Process data
        // \$result = \$this->processData(\$data);
        
        // Example: Log completion
        \$this->addMetadata('processed_at', date('Y-m-d H:i:s'));
        
        // Example: Trigger event
        // \$this->app->resolve(EventListener::class)->trigger('{$lowerName}.processed', \$data);
    }

    /**
     * Handle job failure
     */
    public function failed(JobException \$exception): void
    {
        // Log the failure
        LoggerFactory::system("queue-worker", "queue", false)
        ->warning(sprintf(
          '{$name}Job failed after %d attempts: %s',
          \$this->getAttempts(),
          \$exception->getMessage()
        ));
        
        // TODO: Implement failure handling
        // - Send notification
        // - Update database status
        // - Retry with different parameters
    }

    /**
     * Optional: Add helper methods for your job
     */
    protected function processData(array \$data): array
    {
        // Process your data here
        return \$data;
    }
}
PHP;
    }

    /**
     * Generate model job template
     */
    private function generateModelJobTemplate(string $name, string $queue, int $maxAttempts, int $timeout, int $delay): string
    {
        $lowerName = strtolower($name);
        $modelName = str_replace('Job', '', $name);
        $tableName = $this->snakeCase($modelName) . 's';
        
        return <<<PHP
<?php

namespace App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

/**
 * {$name} Job
 *
 * This job handles {$lowerName} model processing tasks.
 */
class {$name}Job extends BaseJob
{
    protected QueryBuilder \$queryBuilder;
    protected string \$tableName = '{$tableName}';

    /**
     * Create a new job instance
     */
    public function __construct(\Mlangeni\Machinjiri\Core\Container \$app, array \$payload = [], array \$options = [])
    {
        \$defaultOptions = [
            'maxAttempts' => {$maxAttempts},
            'queue' => '{$queue}',
            'timeout' => {$timeout},
            'delay' => {$delay},
        ];
        
        parent::__construct(\$app, \$payload, array_merge(\$defaultOptions, \$options));
        
        // Initialize query builder
        \$this->queryBuilder = new QueryBuilder(\$this->tableName);
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        \$action = \$this->payload['action'] ?? 'process';
        \$modelId = \$this->payload['model_id'] ?? null;
        
        switch (\$action) {
            case 'create':
                \$this->handleCreate();
                break;
            case 'update':
                \$this->handleUpdate(\$modelId);
                break;
            case 'delete':
                \$this->handleDelete(\$modelId);
                break;
            case 'process':
                \$this->handleProcess(\$modelId);
                break;
            default:
                throw new MachinjiriException("Unknown action: {\$action}");
        }
    }

    /**
     * Handle model creation
     */
    protected function handleCreate(): void
    {
        \$data = \$this->payload['data'] ?? [];
        
        if (empty(\$data)) {
            throw new MachinjiriException('No data provided for creation');
        }
        
        \$data['created_at'] = date('Y-m-d H:i:s');
        \$data['updated_at'] = date('Y-m-d H:i:s');
        
        \$result = \$this->queryBuilder->insert(\$data)->execute();
        
        if (!isset(\$result['rowCount']) || \$result['rowCount'] === 0) {
            throw new MachinjiriException('Failed to create record');
        }
        
        \$this->addMetadata('created_id', \$result['lastInsertId'] ?? null);
        \$this->addMetadata('action', 'create');
    }

    /**
     * Handle model update
     */
    protected function handleUpdate(?int \$modelId): void
    {
        if (!\$modelId) {
            throw new MachinjiriException('Model ID is required for update');
        }
        
        \$data = \$this->payload['data'] ?? [];
        
        if (empty(\$data)) {
            throw new MachinjiriException('No data provided for update');
        }
        
        \$data['updated_at'] = date('Y-m-d H:i:s');
        
        \$result = \$this->queryBuilder
            ->update(\$data)
            ->where('id', '=', \$modelId)
            ->execute();
        
        if (!isset(\$result['rowCount']) || \$result['rowCount'] === 0) {
            throw new MachinjiriException('Failed to update record');
        }
        
        \$this->addMetadata('updated_id', \$modelId);
        \$this->addMetadata('action', 'update');
    }

    /**
     * Handle model deletion
     */
    protected function handleDelete(?int \$modelId): void
    {
        if (!\$modelId) {
            throw new MachinjiriException('Model ID is required for deletion');
        }
        
        // Soft delete if available
        if (\$this->hasSoftDeletes()) {
            \$result = \$this->queryBuilder
                ->update(['deleted_at' => date('Y-m-d H:i:s')])
                ->where('id', '=', \$modelId)
                ->execute();
        } else {
            \$result = \$this->queryBuilder
                ->delete()
                ->where('id', '=', \$modelId)
                ->execute();
        }
        
        \$this->addMetadata('deleted_id', \$modelId);
        \$this->addMetadata('action', 'delete');
    }

    /**
     * Handle model processing
     */
    protected function handleProcess(?int \$modelId): void
    {
        if (\$modelId) {
            // Process single record
            \$record = \$this->queryBuilder
                ->where('id', '=', \$modelId)
                ->first();
                
            if (!\$record) {
                throw new MachinjiriException("Record not found: {\$modelId}");
            }
            
            \$this->processRecord(\$record);
        } else {
            // Process all records
            \$records = \$this->queryBuilder->get();
            
            foreach (\$records as \$record) {
                \$this->processRecord(\$record);
            }
        }
        
        \$this->addMetadata('processed_at', date('Y-m-d H:i:s'));
    }

    /**
     * Process a single record
     */
    protected function processRecord(array \$record): void
    {
        // TODO: Implement your record processing logic
        // Example: Validate, transform, or analyze the record
        
        // Simulate processing
        sleep(1);
    }

    /**
     * Check if table has soft deletes
     */
    protected function hasSoftDeletes(): bool
    {
        try {
            \$columns = \$this->app->resolve('database')->getColumns(\$this->tableName);
            return isset(\$columns['deleted_at']);
        } catch (MachinjiriException \$e) {
            return false;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(JobException \$exception): void
    {
        // Log failure with model context
        LoggerFactory::system("queue-worker", "queue", false)
        ->warning(sprintf(
          '{$name}Job failed after %d attempts: %s',
          \$this->getAttempts(),
          \$exception->getMessage()
        ));
        
        // Update record status if applicable
        \$modelId = \$this->payload['model_id'] ?? null;
        if (\$modelId && \$this->hasStatusColumn()) {
            \$this->queryBuilder
                ->update(['status' => 'failed', 'error' => \$exception->getMessage()])
                ->where('id', '=', \$modelId)
                ->execute();
        }
    }

    /**
     * Check if table has status column
     */
    protected function hasStatusColumn(): bool
    {
        try {
            \$columns = \$this->app->resolve('database')->getColumns(\$this->tableName);
            return isset(\$columns['status']);
        } catch (MachinjiriException \$e) {
            return false;
        }
    }
}
PHP;
    }

    /**
     * Generate queue driver
     *
     * @param string $name Queue driver name (without Queue suffix)
     * @param array $options Generation options
     * @return string Created file path
     * @throws MachinjiriException
     */
    public function generateQueueDriver(string $name, array $options = []): string
    {
        $name = $this->normalizeQueueName($name);
        
        // Validate name
        $this->validateQueueName($name);
        
        // Get options
        $type = $options['type'] ?? 'database';
        $withConfig = $options['config'] ?? true;
        
        // Ensure queues directory exists
        $this->ensureDirectoryExists($this->queuesPath);
        
        $queueFile = $this->queuesPath . $name . '.php';
        
        // Generate template based on type
        $template = $this->generateQueueTemplate($name, [
            'type' => $type,
        ]);
        
        // Write file
        if (file_put_contents($queueFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create queue driver file: {$queueFile}",
                91005
            );
        }
        
        // Create configuration if requested
        if ($withConfig) {
            $this->createQueueConfig($name, $type, $options['config_data'] ?? []);
        }
        
        // Update providers if requested
        if ($options['register'] ?? false) {
            $this->registerQueueInProviders($name, $type);
        }
        
        // Register in command for queue:work
        if ($options['command'] ?? true) {
            $this->registerQueueInCommand($name, $type);
        }
        
        return $queueFile;
    }

    /**
     * Normalize queue name
     *
     * @param string $name
     * @return string
     */
    private function normalizeQueueName(string $name): string
    {
        // Remove "Queue" suffix if present
        $name = preg_replace('/Queue$/i', '', $name);
        
        // Convert to PascalCase
        $name = str_replace(['-', '_', ' '], '', ucwords($name, '-_ '));
        
        // Add Queue suffix
        return $name . 'Queue';
    }

    /**
     * Validate queue name
     *
     * @param string $name
     * @throws MachinjiriException
     */
    private function validateQueueName(string $name): void
    {
        // Check if name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*Queue$/', $name)) {
            throw new MachinjiriException(
                "Invalid queue driver name: {$name}. Name must be in PascalCase and end with Queue.",
                91006
            );
        }
        
        // Check if queue already exists
        $queueFile = $this->queuesPath . $name . '.php';
        if (file_exists($queueFile)) {
            throw new MachinjiriException(
                "Queue driver already exists: {$name}",
                91007
            );
        }
        
        // Check if class already exists
        $className = "App\\Queue\\Drivers\\{$name}";
        if (class_exists($className)) {
            throw new MachinjiriException(
                "Queue driver class already exists: {$className}",
                91008
            );
        }
    }

    /**
     * Generate queue template
     *
     * @param string $name
     * @param array $options
     * @return string
     */
    private function generateQueueTemplate(string $name, array $options): string
    {
        $shortName = str_replace('Queue', '', $name);
        $type = $options['type'];
        
        // Different templates for different queue types
        switch ($type) {
            case 'redis':
                return $this->generateRedisQueueTemplate($shortName);
            case 'database':
                return $this->generateDatabaseQueueTemplate($shortName);
            case 'sync':
                return $this->generateSyncQueueTemplate($shortName);
            case 'file':
                return $this->generateFileQueueTemplate($shortName);
            case 'memory':
                return $this->generateMemoryQueueTemplate($shortName);
            default:
                return $this->generateCustomQueueTemplate($shortName, $type);
        }
    }

    /**
     * Generate database queue template
     */
    private function generateDatabaseQueueTemplate(string $name): string
    {
    return <<<PHP
<?php

namespace App\Queue\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\DriverException;

/**
 * {$name} Queue Driver
 *
 * Database-based queue driver for persistent job storage.
 */
class {$name}Queue extends BaseQueue
{
    protected QueryBuilder \$queryBuilder;
    protected string \$tableName = 'jobs';
    protected array \$config = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        string \$name,
        array \$config = []
    ) {
        parent::__construct(\$app, \$name, \$config);
        
        \$this->config = array_merge([
            'table' => 'jobs',
            'connection' => 'default',
            'retry_after' => 90,
            'failed_table' => 'failed_jobs',
            'processed_table' => 'processed_jobs',
        ], \$config);
        
        \$this->tableName = \$this->config['table'];
        \$this->queryBuilder = new QueryBuilder(\$this->tableName);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface \$job, string \$queue = 'default', int \$delay = 0): string
    {
        \$data = [
            'queue' => \$queue,
            'job_id' => \$job->getId(),
            'payload' => json_encode(\$job->serialize()),
            'attempts' => \$job->getAttempts(),
            'available_at' => time() + \$delay,
            'created_at' => time(),
            'reserved_at' => 0,
        ];
        
        \$result = \$this->queryBuilder->insert(\$data)->execute();
        \$jobId = \$job->getId();
        
        \$this->events->trigger('queue.job.pushed', [
            'job_id' => \$jobId,
            'queue' => \$queue,
            'job_name' => \$job->getName(),
        ]);
        
        return (string) \$jobId;
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string \$queue = 'default'): ?JobInterface
    {
        \$now = time();
        \$retryAfter = \$this->config['retry_after'];
        
        \$job = \$this->queryBuilder
            ->select()
            ->where('queue', '=', \$queue)
            ->where('available_at', '<=', \$now)
            ->where('reserved_at', '=', 0)
            ->orWhere('reserved_at', '<', \$now - \$retryAfter)
            ->orderBy('created_at', 'ASC')
            ->limit(1)
            ->first();
            
        if (!\$job) {
            return null;
        }
        
        // Mark as reserved
        \$this->queryBuilder
            ->update(['reserved_at' => \$now])
            ->where('job_id', '=', \$job['job_id'])
            ->execute();
            
        \$jobData = json_decode(\$job['payload'], true);
        
        if (!\$jobData) {
            throw new MachinjiriException('Invalid job payload');
        }
        
        \$jobClass = \$jobData['name'] ?? '';
        
        if (!class_exists(\$jobClass)) {
            \$this->markAsFailed(\$job['job_id'], 'Job class not found: ' . \$jobClass);
            return null;
        }
        
        return \$jobClass::unserialize(\$jobData, \$this->app);
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface \$job, string \$queue = 'default', int \$delay = 0): bool
    {
        \$serialized = json_encode(\$job->serialize());
        
        // check if job is not already in queue
        \$result = \$this->queryBuilder
            ->select()
            ->where('job_id', '=', \$job->getId())
            ->where('queue', '=', \$queue)
            ->first();
            
        if (\$result !== null) {
            return false;
        }
        
        \$result = \$this->queryBuilder
            ->update([
                'payload' => \$serialized,
                'attempts' => \$job->getAttempts(),
                'available_at' => time() + \$delay,
                'reserved_at' => 0,
            ])
            ->where('job_id', '=', \$job->getId())
            ->where('queue', '=', \$queue)
            ->execute();
            
        return \$result['rowCount'] > 0;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface \$job, string \$queue = 'default'): bool
    {
        \$result = \$this->queryBuilder
            ->delete()
            ->where('job_id', '=', \$job->getId())
            ->where('queue', '=', \$queue)
            ->execute();
            
        return \$result['rowCount'] > 0;
    }

    /**
     * Get the size of the queue
     */
    public function size(string \$queue = 'default'): int
    {
        \$result = \$this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->where('available_at', '<=', time())
            ->where('reserved_at', '=', 0)
            ->first();
            
        return \$result['count'] ?? 0;
    }

    /**
     * Clear the queue
     */
    public function clear(string \$queue = 'default'): int
    {
        \$result = \$this->queryBuilder
            ->delete()
            ->where('queue', '=', \$queue)
            ->execute();
            
        return \$result['rowCount'] ?? 0;
    }

    /**
     * Clear failed jobs for a queue
     */
    public function clearFailed(string \$queue = 'default'): int
    {
        \$result = (new QueryBuilder(\$this->config['failed_table']))
            ->delete()
            ->where('queue', '=', \$queue)
            ->execute();
            
        return \$result['rowCount'] ?? 0;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        \$result = \$this->queryBuilder
            ->select(['DISTINCT queue'])
            ->execute();
            
        return array_column(\$result, 'queue');
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        try {
            \$this->queryBuilder->select(['1'])->first();
            return true;
        } catch (MachinjiriException \$e) {
            return false;
        }
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$failedTable = \$this->config['failed_table'] ?? 'failed_jobs';
        \$query = new QueryBuilder(\$failedTable);
        
        \$result = \$query
            ->select()
            ->where('queue', '=', \$queue)
            ->orderBy('failed_at', 'DESC')
            ->limit(\$limit)
            ->offset(\$offset)
            ->execute();
            
        return \$result;
    }

    /**
     * Get processed (completed) jobs
     */
    public function getProcessed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$processedTable = \$this->config['processed_table'] ?? 'processed_jobs';
        \$query = new QueryBuilder(\$processedTable);
        
        \$result = \$query
            ->select()
            ->where('queue', '=', \$queue)
            ->orderBy('processed_at', 'DESC')
            ->limit(\$limit)
            ->offset(\$offset)
            ->execute();
            
        return \$result;
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string \$jobId, string \$queue = 'default'): bool
    {
        \$failedTable = \$this->config['failed_table'] ?? 'failed_jobs';
        \$failedQuery = new QueryBuilder(\$failedTable);
        
        \$failedJob = \$failedQuery
            ->select()
            ->where('job_id', '=', \$jobId)
            ->first();
            
        if (!\$failedJob) {
            return false;
        }
        
        \$jobData = json_decode(\$failedJob['payload'], true);
        
        if (!\$jobData) {
            return false;
        }
        
        \$jobClass = \$jobData['name'] ?? '';
        
        if (!class_exists(\$jobClass)) {
            return false;
        }
        
        \$job = \$jobClass::unserialize(\$jobData, \$this->app);
        \$job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        
        \$this->push(\$job, \$queue, 0);
        
        \$failedQuery
            ->delete()
            ->where('job_id', '=', \$jobId)
            ->execute();
            
        return true;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string \$jobId, string \$error): void
    {
        \$job = \$this->queryBuilder
            ->select()
            ->where('job_id', '=', \$jobId)
            ->first();
            
        if (!\$job) {
            return;
        }
        
        \$failedTable = \$this->config['failed_table'] ?? 'failed_jobs';
        \$failedQuery = new QueryBuilder(\$failedTable);
        
        // check if job already exists in failed
        \$exists = \$failedQuery->select()->where('job_id', '=', \$job['job_id'])->first();
        if (\$exists === null || !\$exists) {
            \$result = \$failedQuery->insert([
                'queue' => \$job['queue'],
                'job_id' => \$job['job_id'],
                'payload' => \$job['payload'],
                'exception' => \$error,
                'failed_at' => time(),
            ])->execute();
    
            if (\$result['lastInsertId'] || \$result['lastInsertId'] > 0) {
                \$this->queryBuilder
                    ->delete()
                    ->where('job_id', '=', \$jobId)
                    ->execute();
            }
        }
    }

    /**
     * Mark a job as completed
     */
    public function markAsCompleted(string \$jobId, array \$payload = []): void
    {
        \$job = \$this->queryBuilder
            ->select()
            ->where('job_id', '=', \$jobId)
            ->first();
            
        if (!\$job) {
            return;
        }
        
        \$processedTable = \$this->config['processed_table'] ?? 'processed_jobs';
        \$processedQuery = new QueryBuilder(\$processedTable);
        
        // check if job already exists in processed
        \$exists = \$processedQuery->select()->where('job_id', '=', \$job['job_id'])->first();
        if (\$exists === null || !\$exists) {
            \$result = \$processedQuery->insert([
                'queue' => \$job['queue'],
                'job_id' => \$job['job_id'],
                'payload' => json_encode(\$payload, true),
                'processed_at' => time(),
            ])->execute();
    
            if (\$result['lastInsertId'] || \$result['lastInsertId'] > 0) {
                \$this->queryBuilder
                    ->delete()
                    ->where('job_id', '=', \$jobId)
                    ->execute();
            }
        }
    }

    /**
     * Get queue statistics
     */
    public function getStats(string \$queue = 'default'): array
    {
        \$stats = [];
        
        // Total jobs
        \$totalResult = \$this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->first();
        \$stats['total'] = \$totalResult['count'] ?? 0;
        
        // Pending jobs
        \$pendingResult = \$this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->where('available_at', '<=', time())
            ->where('reserved_at', '=', 0)
            ->first();
        \$stats['pending'] = \$pendingResult['count'] ?? 0;
        
        // Reserved jobs
        \$reservedResult = \$this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->where('reserved_at', '>', 0)
            ->first();
        \$stats['reserved'] = \$reservedResult['count'] ?? 0;
        
        // Delayed jobs
        \$delayedResult = \$this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->where('available_at', '>', time())
            ->first();
        \$stats['delayed'] = \$delayedResult['count'] ?? 0;
        
        // Processed jobs
        \$processedTable = \$this->config['processed_table'] ?? 'processed_jobs';
        \$processedQuery = new QueryBuilder(\$processedTable);
        \$processedResult = \$processedQuery
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->first();
        \$stats['processed'] = \$processedResult['count'] ?? 0;
        
        // Failed jobs
        \$failedTable = \$this->config['failed_table'] ?? 'failed_jobs';
        \$failedQuery = new QueryBuilder(\$failedTable);
        \$failedResult = \$failedQuery
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', \$queue)
            ->first();
        \$stats['failed'] = \$failedResult['count'] ?? 0;
        
        return \$stats;
    }
    
    /**
     * Get database connection
     */
    public static function getConnection()
    {
        return DatabaseConnection::getInstance();
    }
}
PHP;
}

    /**
     * Generate Redis queue template
     */
    private function generateRedisQueueTemplate(string $name)
    {
    return <<<PHP
<?php

namespace App\Queue\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Adapters\Redis\RedisAdapter;

/**
 * Redis Queue Driver (Predis based)
 *
 * Redis-based queue driver for high-performance job processing.
 */
class RedisQueue extends BaseQueue
{
    protected RedisAdapter \$redis;
    protected array \$config = [];
    protected string \$prefix = 'queue:';

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        string \$name,
        array \$config = []
    ) {
        parent::__construct(\$app, \$name, \$config);
        
        \$this->config = array_merge([
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DATABASE', 0),
            'prefix' => 'queue:',
            'retry_after' => 90,
            'timeout' => 2.5,
        ], \$config);
        
        \$this->prefix = \$this->config['prefix'];
        \$this->connectRedis();
    }

    /**
    * Connect to Redis using the framework Redis adapter
     */
    protected function connectRedis(): void
    {
        \$this->redis = new RedisAdapter([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'host' => \$this->config['host'],
                    'port' => \$this->config['port'],
                    'password' => \$this->config['password'],
                    'database' => \$this->config['database'],
                    'timeout' => \$this->config['timeout'],
                ],
            ],
            'prefix' => trim(\$this->prefix, ':'),
            'serialize' => false,
        ]);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface \$job, string \$queue = 'default', int \$delay = 0): string
    {
        \$key = \$this->getQueueKey(\$queue);
        \$jobId = \$job->getId();
        \$serialized = json_encode(\$job->serialize());
        
        if (\$delay > 0) {
            \$delayedKey = \$this->getDelayedQueueKey(\$queue);
            \$score = time() + \$delay;
            \$this->redis->zadd(\$delayedKey, \$score, \$serialized);
        } else {
            \$this->redis->rpush(\$key, \$serialized);
        }
        
        \$metaKey = \$this->getJobMetaKey(\$jobId);
        \$this->redis->hmset(\$metaKey, [
            'queue' => \$queue,
            'created_at' => time(),
            'delay' => \$delay,
        ]);
        
        \$this->events->trigger('queue.job.pushed', [
            'job_id' => \$jobId,
            'queue' => \$queue,
            'job_name' => \$job->getName(),
        ]);
        
        return \$jobId;
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string \$queue = 'default'): ?JobInterface
    {
        \$this->migrateDelayedJobs(\$queue);
        
        \$key = \$this->getQueueKey(\$queue);
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        
        \$serialized = \$this->redis->rpoplpush(\$key, \$reservedKey);
        
        if (!\$serialized) {
            return null;
        }
        
        \$jobData = json_decode(\$serialized, true);
        
        if (!\$jobData) {
            \$this->redis->lrem(\$reservedKey, 1, \$serialized);
            return null;
        }
        
        \$jobClass = \$jobData['name'] ?? '';
        
        if (!class_exists(\$jobClass)) {
            \$this->redis->lrem(\$reservedKey, 1, \$serialized);
            \$this->moveToFailed(\$queue, \$serialized, 'Job class not found');
            return null;
        }
        
        \$jobId = \$jobData['id'] ?? '';
        if (\$jobId) {
            \$timeoutKey = \$this->getTimeoutKey(\$jobId);
            \$this->redis->setex(\$timeoutKey, \$this->config['retry_after'], '1');
        }
        
        return \$jobClass::unserialize(\$jobData, \$this->app);
    }

    /**
     * Migrate delayed jobs to active queue
     */
    protected function migrateDelayedJobs(string \$queue): void
    {
        \$delayedKey = \$this->getDelayedQueueKey(\$queue);
        \$key = \$this->getQueueKey(\$queue);
        \$now = time();
        
        \$jobs = \$this->redis->zrangebyscore(\$delayedKey, 0, \$now);
        
        if (!empty(\$jobs)) {
            foreach (\$jobs as \$job) {
                \$this->redis->rpush(\$key, \$job);
                \$this->redis->zrem(\$delayedKey, \$job);
            }
        }
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface \$job, string \$queue = 'default', int \$delay = 0): bool
    {
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        \$serialized = json_encode(\$job->serialize());
        
        \$this->redis->lrem(\$reservedKey, 1, \$serialized);
        \$timeoutKey = \$this->getTimeoutKey(\$job->getId());
        \$this->redis->del(\$timeoutKey);
        
        return (bool) \$this->push(\$job, \$queue, \$delay);
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface \$job, string \$queue = 'default'): bool
    {
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        \$serialized = json_encode(\$job->serialize());
        
        \$removed = \$this->redis->lrem(\$reservedKey, 1, \$serialized);
        \$timeoutKey = \$this->getTimeoutKey(\$job->getId());
        \$this->redis->del(\$timeoutKey);
        \$metaKey = \$this->getJobMetaKey(\$job->getId());
        \$this->redis->del(\$metaKey);
        
        return \$removed > 0;
    }

    /**
     * Get the size of the queue
     */
    public function size(string \$queue = 'default'): int
    {
        \$key = \$this->getQueueKey(\$queue);
        return \$this->redis->llen(\$key);
    }

    /**
     * Clear the queue
     */
    public function clear(string \$queue = 'default'): int
    {
        \$count = 0;
        \$key = \$this->getQueueKey(\$queue);
        \$count += \$this->redis->del(\$key);
        \$delayedKey = \$this->getDelayedQueueKey(\$queue);
        \$count += \$this->redis->del(\$delayedKey);
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        \$count += \$this->redis->del(\$reservedKey);
        return \$count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        \$pattern = \$this->prefix . '*:queue';
        \$keys = \$this->redis->keys(\$pattern);
        
        \$queues = [];
        foreach (\$keys as \$key) {
            \$parts = explode(':', \$key);
            if (isset(\$parts[1])) {
                \$queues[] = \$parts[1];
            }
        }
        
        return array_unique(\$queues);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        try {
            return \$this->redis->ping() === 'PONG';
        } catch (MachinjiriException \$e) {
            return false;
        }
    }

    /**
     * Helper methods for Redis keys
     */
    protected function getQueueKey(string \$queue): string
    {
        return \$this->prefix . \$queue . ':queue';
    }
    
    protected function getDelayedQueueKey(string \$queue): string
    {
        return \$this->prefix . \$queue . ':delayed';
    }
    
    protected function getReservedQueueKey(string \$queue): string
    {
        return \$this->prefix . \$queue . ':reserved';
    }
    
    protected function getJobMetaKey(string \$jobId): string
    {
        return \$this->prefix . 'job:' . \$jobId . ':meta';
    }
    
    protected function getTimeoutKey(string \$jobId): string
    {
        return \$this->prefix . 'job:' . \$jobId . ':timeout';
    }
    
    protected function getFailedKey(string \$queue): string
    {
        return \$this->prefix . \$queue . ':failed';
    }
    
    protected function getProcessedKey(string \$queue): string
    {
        return \$this->prefix . \$queue . ':processed';
    }

    /**
     * Move job to failed queue
     */
    protected function moveToFailed(string \$queue, string \$serialized, string \$error): void
    {
        \$failedKey = \$this->getFailedKey(\$queue);
        \$failedData = [
            'job' => \$serialized,
            'error' => \$error,
            'failed_at' => time(),
            'queue' => \$queue,
        ];
        \$this->redis->rpush(\$failedKey, json_encode(\$failedData));
    }

    /**
     * Move job to processed queue
     */
    protected function moveToProcessed(string \$queue, string \$serialized, array \$payload = []): void
    {
        \$processedKey = \$this->getProcessedKey(\$queue);
        \$processedData = [
            'job' => \$serialized,
            'result_payload' => \$payload,
            'processed_at' => time(),
            'queue' => \$queue,
        ];
        \$this->redis->rpush(\$processedKey, json_encode(\$processedData));
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$failedKey = \$this->getFailedKey(\$queue);
        \$jobs = \$this->redis->lrange(\$failedKey, \$offset, \$offset + \$limit - 1);
        
        \$result = [];
        foreach (\$jobs as \$job) {
            \$data = json_decode(\$job, true);
            if (\$data) {
                \$result[] = \$data;
            }
        }
        return \$result;
    }

    /**
     * Get processed jobs
     */
    public function getProcessed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$processedKey = \$this->getProcessedKey(\$queue);
        \$jobs = \$this->redis->lrange(\$processedKey, \$offset, \$offset + \$limit - 1);
        
        \$result = [];
        foreach (\$jobs as \$job) {
            \$data = json_decode(\$job, true);
            if (\$data) {
                \$result[] = \$data;
            }
        }
        return \$result;
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string \$jobId, string \$queue = 'default'): bool
    {
        \$failedKey = \$this->getFailedKey(\$queue);
        \$jobs = \$this->redis->lrange(\$failedKey, 0, -1);
        
        foreach (\$jobs as \$index => \$job) {
            \$data = json_decode(\$job, true);
            
            if (!\$data) {
                continue;
            }
            
            \$jobData = json_decode(\$data['job'], true);
            
            if (!\$jobData || (\$jobData['id'] ?? '') !== \$jobId) {
                continue;
            }
            
            \$this->redis->lrem(\$failedKey, 1, \$job);
            
            \$jobClass = \$jobData['name'] ?? '';
            if (class_exists(\$jobClass)) {
                \$jobInstance = \$jobClass::unserialize(\$jobData, \$this->app);
                \$jobInstance->addMetadata('retried_at', date('Y-m-d H:i:s'));
                \$this->push(\$jobInstance, \$queue, 0);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string \$jobId, string \$error): void
    {
        \$metaKey = \$this->getJobMetaKey(\$jobId);
        \$meta = \$this->redis->hgetall(\$metaKey);
        
        if (empty(\$meta) || !isset(\$meta['queue'])) {
            \$this->events->trigger('queue.warning', [
                'message' => 'Cannot mark job as failed: metadata not found',
                'job_id'  => \$jobId,
            ]);
            return;
        }
        
        \$queue = \$meta['queue'];
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        \$reservedJobs = \$this->redis->lrange(\$reservedKey, 0, -1);
        
        foreach (\$reservedJobs as \$serialized) {
            \$jobData = json_decode(\$serialized, true);
            if (\$jobData && isset(\$jobData['id']) && \$jobData['id'] === \$jobId) {
                \$this->redis->lrem(\$reservedKey, 1, \$serialized);
                \$this->moveToFailed(\$queue, \$serialized, \$error);
                \$timeoutKey = \$this->getTimeoutKey(\$jobId);
                \$this->redis->del(\$timeoutKey);
                \$this->redis->del(\$metaKey);
                \$this->events->trigger('queue.marked_failed', [
                    'job_id' => \$jobId,
                    'queue'  => \$queue,
                    'error'  => \$error,
                ]);
                return;
            }
        }
        
        \$this->events->trigger('queue.warning', [
            'message' => 'Job not found in reserved queue for marking as failed',
            'job_id'  => \$jobId,
            'queue'   => \$queue,
        ]);
    }

    /**
     * Mark a job as completed
     */
    public function markAsCompleted(string \$jobId, array \$payload = []): void
    {
        \$metaKey = \$this->getJobMetaKey(\$jobId);
        \$meta = \$this->redis->hgetall(\$metaKey);
        
        if (empty(\$meta) || !isset(\$meta['queue'])) {
            \$this->events->trigger('queue.warning', [
                'message' => 'Cannot mark job as completed: metadata not found',
                'job_id'  => \$jobId,
            ]);
            return;
        }
        
        \$queue = \$meta['queue'];
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        \$reservedJobs = \$this->redis->lrange(\$reservedKey, 0, -1);
        
        foreach (\$reservedJobs as \$serialized) {
            \$jobData = json_decode(\$serialized, true);
            if (\$jobData && isset(\$jobData['id']) && \$jobData['id'] === \$jobId) {
                \$this->redis->lrem(\$reservedKey, 1, \$serialized);
                \$this->moveToProcessed(\$queue, \$serialized, \$payload);
                \$timeoutKey = \$this->getTimeoutKey(\$jobId);
                \$this->redis->del(\$timeoutKey);
                \$this->redis->del(\$metaKey);
                \$this->events->trigger('queue.marked_completed', [
                    'job_id' => \$jobId,
                    'queue'  => \$queue,
                    'payload' => \$payload,
                ]);
                return;
            }
        }
        
        \$this->events->trigger('queue.warning', [
            'message' => 'Job not found in reserved queue for marking as completed',
            'job_id'  => \$jobId,
            'queue'   => \$queue,
        ]);
    }

    /**
     * Get queue statistics
     */
    public function getStats(string \$queue = 'default'): array
    {
        \$stats = [];
        \$stats['pending'] = \$this->size(\$queue);
        \$delayedKey = \$this->getDelayedQueueKey(\$queue);
        \$stats['delayed'] = \$this->redis->zcard(\$delayedKey);
        \$reservedKey = \$this->getReservedQueueKey(\$queue);
        \$stats['reserved'] = \$this->redis->llen(\$reservedKey);
        \$failedKey = \$this->getFailedKey(\$queue);
        \$stats['failed'] = \$this->redis->llen(\$failedKey);
        \$processedKey = \$this->getProcessedKey(\$queue);
        \$stats['processed'] = \$this->redis->llen(\$processedKey);
        return \$stats;
    }
    
    /**
     * Close Redis connection
     */
    public function __destruct()
    {
        if (isset(\$this->redis)) {
            \$this->redis->disconnect();
        }
    }
}
PHP;
}

    /**
     * Generate sync queue template (for immediate processing)
     */
    private function generateSyncQueueTemplate(string $name): string
{
    return <<<PHP
<?php

namespace App\Queue\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * {$name} Queue Driver
 *
 * Synchronous queue driver for immediate job processing (mainly for testing).
 */
class {$name}Queue extends BaseQueue
{
    protected array \$jobs = [];
    protected array \$processing = [];
    protected array \$failed = [];
    protected array \$processed = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        string \$name,
        array \$config = []
    ) {
        parent::__construct(\$app, \$name, \$config);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface \$job, string \$queue = 'default', int \$delay = 0): string
    {
        if (\$delay === 0) {
            \$this->processImmediately(\$job, \$queue);
            return \$job->getId();
        }
        
        if (!isset(\$this->jobs[\$queue])) {
            \$this->jobs[\$queue] = [];
        }
        
        \$this->jobs[\$queue][] = [
            'job' => \$job,
            'available_at' => time() + \$delay,
        ];
        
        \$this->events->trigger('queue.job.pushed', [
            'job_id' => \$job->getId(),
            'queue' => \$queue,
            'job_name' => \$job->getName(),
        ]);
        
        return \$job->getId();
    }

    /**
     * Process job immediately
     */
    protected function processImmediately(JobInterface \$job, string \$queue): void
    {
        \$this->events->trigger('job.processing', [
            'job_id' => \$job->getId(),
            'job_name' => \$job->getName(),
            'queue' => \$queue,
        ]);
        
        \$this->processing[\$job->getId()] = [
            'job' => \$job,
            'queue' => \$queue,
            'started_at' => time(),
        ];
        
        try {
            \$job->handle();
            \$this->markAsCompleted(\$job->getId(), ['status' => 'success']);
            \$this->events->trigger('job.processed', [
                'job_id' => \$job->getId(),
                'job_name' => \$job->getName(),
                'queue' => \$queue,
            ]);
        } catch (MachinjiriException \$e) {
            \$job->failed(new MachinjiriException(\$e->getMessage()));
            \$this->markAsFailed(\$job->getId(), \$e->getMessage());
            \$this->events->trigger('job.failed', [
                'job_id' => \$job->getId(),
                'job_name' => \$job->getName(),
                'queue' => \$queue,
                'exception' => \$e->getMessage(),
            ]);
        }
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string \$queue = 'default'): ?JobInterface
    {
        if (empty(\$this->jobs[\$queue])) {
            return null;
        }
        
        \$now = time();
        foreach (\$this->jobs[\$queue] as \$index => \$jobData) {
            if (\$jobData['available_at'] <= \$now) {
                \$job = \$jobData['job'];
                unset(\$this->jobs[\$queue][\$index]);
                \$this->processing[\$job->getId()] = [
                    'job' => \$job,
                    'queue' => \$queue,
                    'started_at' => \$now,
                ];
                return \$job;
            }
        }
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface \$job, string \$queue = 'default', int \$delay = 0): bool
    {
        unset(\$this->processing[\$job->getId()]);
        if (!isset(\$this->jobs[\$queue])) {
            \$this->jobs[\$queue] = [];
        }
        \$this->jobs[\$queue][] = [
            'job' => \$job,
            'available_at' => time() + \$delay,
        ];
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface \$job, string \$queue = 'default'): bool
    {
        unset(\$this->processing[\$job->getId()]);
        if (isset(\$this->jobs[\$queue])) {
            foreach (\$this->jobs[\$queue] as \$index => \$jobData) {
                if (\$jobData['job']->getId() === \$job->getId()) {
                    unset(\$this->jobs[\$queue][\$index]);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get the size of the queue
     */
    public function size(string \$queue = 'default'): int
    {
        return isset(\$this->jobs[\$queue]) ? count(\$this->jobs[\$queue]) : 0;
    }

    /**
     * Clear the queue
     */
    public function clear(string \$queue = 'default'): int
    {
        \$count = isset(\$this->jobs[\$queue]) ? count(\$this->jobs[\$queue]) : 0;
        unset(\$this->jobs[\$queue]);
        return \$count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        return array_keys(\$this->jobs);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$failedInQueue = [];
        foreach (\$this->failed as \$jobId => \$failedJob) {
            if (\$failedJob['queue'] === \$queue) {
                \$failedInQueue[] = \$failedJob;
            }
        }
        usort(\$failedInQueue, function(\$a, \$b) {
            return \$b['failed_at'] <=> \$a['failed_at'];
        });
        return array_slice(\$failedInQueue, \$offset, \$limit);
    }

    /**
     * Get processed jobs
     */
    public function getProcessed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$processedInQueue = [];
        foreach (\$this->processed as \$jobId => \$processedJob) {
            if (\$processedJob['queue'] === \$queue) {
                \$processedInQueue[] = \$processedJob;
            }
        }
        usort(\$processedInQueue, function(\$a, \$b) {
            return \$b['processed_at'] <=> \$a['processed_at'];
        });
        return array_slice(\$processedInQueue, \$offset, \$limit);
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string \$jobId, string \$queue = 'default'): bool
    {
        if (!isset(\$this->failed[\$jobId])) {
            return false;
        }
        \$failedJob = \$this->failed[\$jobId];
        \$job = \$failedJob['job'];
        \$job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        \$this->push(\$job, \$queue, 0);
        unset(\$this->failed[\$jobId]);
        return true;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string \$jobId, string \$error): void
    {
        if (isset(\$this->processing[\$jobId])) {
            \$processing = \$this->processing[\$jobId];
            \$this->failed[\$jobId] = [
                'job' => \$processing['job'],
                'queue' => \$processing['queue'],
                'failed_at' => time(),
                'error' => \$error,
            ];
            unset(\$this->processing[\$jobId]);
            \$this->events->trigger('queue.marked_failed', [
                'job_id' => \$jobId,
                'queue' => \$processing['queue'],
                'error' => \$error,
            ]);
        }
    }

    /**
     * Mark a job as completed
     */
    public function markAsCompleted(string \$jobId, array \$payload = []): void
    {
        if (isset(\$this->processing[\$jobId])) {
            \$processing = \$this->processing[\$jobId];
            \$this->processed[\$jobId] = [
                'job' => \$processing['job'],
                'queue' => \$processing['queue'],
                'processed_at' => time(),
                'result_payload' => \$payload,
            ];
            unset(\$this->processing[\$jobId]);
            \$this->events->trigger('queue.marked_completed', [
                'job_id' => \$jobId,
                'queue' => \$processing['queue'],
                'payload' => \$payload,
            ]);
        }
    }

    /**
     * Get queue statistics
     */
    public function getStats(string \$queue = 'default'): array
    {
        \$stats = [
            'queued' => \$this->size(\$queue),
            'processing' => 0,
            'delayed' => 0,
            'failed' => 0,
            'processed' => 0,
        ];
        
        foreach (\$this->processing as \$processing) {
            if (\$processing['queue'] === \$queue) {
                \$stats['processing']++;
            }
        }
        
        if (isset(\$this->jobs[\$queue])) {
            \$now = time();
            foreach (\$this->jobs[\$queue] as \$jobData) {
                if (\$jobData['available_at'] > \$now) {
                    \$stats['delayed']++;
                }
            }
        }
        
        foreach (\$this->failed as \$failedJob) {
            if (\$failedJob['queue'] === \$queue) {
                \$stats['failed']++;
            }
        }
        
        foreach (\$this->processed as \$processedJob) {
            if (\$processedJob['queue'] === \$queue) {
                \$stats['processed']++;
            }
        }
        
        return \$stats;
    }
}
PHP;
}

    /**
     * Generate file queue template
     */
    private function generateFileQueueTemplate(string $name): string
{
    return <<<PHP
<?php

namespace App\Queue\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * {$name} Queue Driver
 *
 * File-based queue driver for simple job storage without database.
 */
class {$name}Queue extends BaseQueue
{
    protected string \$storagePath;
    protected array \$config = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        string \$name,
        array \$config = []
    ) {
        parent::__construct(\$app, \$name, \$config);
        
        \$this->config = array_merge([
            'storage_path' => \$app->getStoragePath() . 'queue/',
            'retry_after' => 90,
        ], \$config);
        
        \$this->storagePath = rtrim(\$this->config['storage_path'], '/') . '/';
        \$this->ensureStorageDirectory();
    }

    /**
     * Ensure storage directory exists
     */
    protected function ensureStorageDirectory(): void
    {
        if (!is_dir(\$this->storagePath)) {
            mkdir(\$this->storagePath, 0755, true);
        }
        
        foreach (['pending', 'processing', 'failed', 'processed'] as \$subdir) {
            \$path = \$this->storagePath . \$subdir . '/';
            if (!is_dir(\$path)) {
                mkdir(\$path, 0755, true);
            }
        }
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface \$job, string \$queue = 'default', int \$delay = 0): string
    {
        \$filename = \$this->generateFilename(\$job->getId(), \$queue);
        \$filepath = \$this->storagePath . 'pending/' . \$filename;
        
        \$data = [
            'job' => \$job->serialize(),
            'queue' => \$queue,
            'created_at' => time(),
            'available_at' => time() + \$delay,
            'attempts' => \$job->getAttempts(),
        ];
        
        if (file_put_contents(\$filepath, json_encode(\$data)) === false) {
            throw new MachinjiriException('Failed to write job to file');
        }
        
        \$this->events->trigger('queue.job.pushed', [
            'job_id' => \$job->getId(),
            'queue' => \$queue,
            'job_name' => \$job->getName(),
            'filepath' => \$filepath,
        ]);
        
        return \$job->getId();
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string \$queue = 'default'): ?JobInterface
    {
        \$pattern = \$this->storagePath . 'pending/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        \$now = time();
        
        foreach (\$files as \$filepath) {
            \$data = json_decode(file_get_contents(\$filepath), true);
            
            if (!\$data) {
                \$this->moveToFailed(\$filepath, 'Corrupted job file');
                continue;
            }
            
            if (\$data['available_at'] > \$now) {
                continue;
            }
            
            \$processingPath = \$this->storagePath . 'processing/' . basename(\$filepath);
            rename(\$filepath, \$processingPath);
            
            \$jobClass = \$data['job']['name'] ?? '';
            
            if (!class_exists(\$jobClass)) {
                \$this->moveToFailed(\$processingPath, 'Job class not found');
                return null;
            }
            
            return \$jobClass::unserialize(\$data['job'], \$this->app);
        }
        
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface \$job, string \$queue = 'default', int \$delay = 0): bool
    {
        \$pattern = \$this->storagePath . 'processing/*_' . \$job->getId() . '.json';
        \$files = glob(\$pattern);
        
        if (empty(\$files)) {
            return false;
        }
        
        \$filepath = \$files[0];
        \$data = json_decode(file_get_contents(\$filepath), true);
        
        if (!\$data) {
            \$this->moveToFailed(\$filepath, 'Corrupted job file on release');
            return false;
        }
        
        \$data['job'] = \$job->serialize();
        \$data['available_at'] = time() + \$delay;
        \$data['attempts'] = \$job->getAttempts();
        
        \$pendingPath = \$this->storagePath . 'pending/' . basename(\$filepath);
        file_put_contents(\$pendingPath, json_encode(\$data));
        unlink(\$filepath);
        
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface \$job, string \$queue = 'default'): bool
    {
        \$pattern = \$this->storagePath . 'processing/*_' . \$job->getId() . '.json';
        \$files = glob(\$pattern);
        
        if (!empty(\$files)) {
            foreach (\$files as \$file) {
                unlink(\$file);
            }
            return true;
        }
        
        \$pattern = \$this->storagePath . 'pending/' . \$queue . '_' . \$job->getId() . '.json';
        \$files = glob(\$pattern);
        
        if (!empty(\$files)) {
            foreach (\$files as \$file) {
                unlink(\$file);
            }
            return true;
        }
        
        return false;
    }

    /**
     * Get the size of the queue
     */
    public function size(string \$queue = 'default'): int
    {
        \$pattern = \$this->storagePath . 'pending/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        
        if (!\$files) {
            return 0;
        }
        
        \$count = 0;
        \$now = time();
        
        foreach (\$files as \$file) {
            \$data = json_decode(file_get_contents(\$file), true);
            if (\$data && \$data['available_at'] <= \$now) {
                \$count++;
            }
        }
        
        return \$count;
    }

    /**
     * Clear the queue
     */
    public function clear(string \$queue = 'default'): int
    {
        \$count = 0;
        
        \$pattern = \$this->storagePath . 'pending/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        if (\$files) {
            foreach (\$files as \$file) {
                unlink(\$file);
                \$count++;
            }
        }
        
        \$pattern = \$this->storagePath . 'processing/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        if (\$files) {
            foreach (\$files as \$file) {
                unlink(\$file);
                \$count++;
            }
        }
        
        return \$count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        \$pattern = \$this->storagePath . 'pending/*.json';
        \$files = glob(\$pattern);
        
        \$queues = [];
        foreach (\$files as \$file) {
            \$filename = basename(\$file);
            \$parts = explode('_', \$filename);
            if (isset(\$parts[0])) {
                \$queues[] = \$parts[0];
            }
        }
        return array_unique(\$queues);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        return is_dir(\$this->storagePath) && is_writable(\$this->storagePath);
    }

    /**
     * Helper methods
     */
    protected function generateFilename(string \$jobId, string \$queue): string
    {
        return \$queue . '_' . \$jobId . '.json';
    }
    
    protected function moveToFailed(string \$filepath, string \$error): void
    {
        \$failedPath = \$this->storagePath . 'failed/' . basename(\$filepath);
        \$data = json_decode(file_get_contents(\$filepath), true);
        if (\$data) {
            \$data['failed_at'] = time();
            \$data['error'] = \$error;
            file_put_contents(\$failedPath, json_encode(\$data));
        }
        unlink(\$filepath);
    }

    protected function moveToProcessed(string \$filepath, array \$payload = []): void
    {
        \$processedPath = \$this->storagePath . 'processed/' . basename(\$filepath);
        \$data = json_decode(file_get_contents(\$filepath), true);
        if (\$data) {
            \$data['processed_at'] = time();
            \$data['result_payload'] = \$payload;
            file_put_contents(\$processedPath, json_encode(\$data));
        }
        unlink(\$filepath);
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$pattern = \$this->storagePath . 'failed/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        
        if (!\$files) {
            return [];
        }
        
        usort(\$files, function(\$a, \$b) {
            return filemtime(\$b) - filemtime(\$a);
        });
        
        \$result = [];
        \$files = array_slice(\$files, \$offset, \$limit);
        
        foreach (\$files as \$file) {
            \$data = json_decode(file_get_contents(\$file), true);
            if (\$data) {
                \$result[] = \$data;
            }
        }
        return \$result;
    }

    /**
     * Get processed jobs
     */
    public function getProcessed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$pattern = \$this->storagePath . 'processed/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        
        if (!\$files) {
            return [];
        }
        
        usort(\$files, function(\$a, \$b) {
            return filemtime(\$b) - filemtime(\$a);
        });
        
        \$result = [];
        \$files = array_slice(\$files, \$offset, \$limit);
        
        foreach (\$files as \$file) {
            \$data = json_decode(file_get_contents(\$file), true);
            if (\$data) {
                \$result[] = \$data;
            }
        }
        return \$result;
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string \$jobId, string \$queue = 'default'): bool
    {
        \$pattern = \$this->storagePath . 'failed/*_' . \$jobId . '.json';
        \$files = glob(\$pattern);
        
        if (empty(\$files)) {
            return false;
        }
        
        \$filepath = \$files[0];
        \$data = json_decode(file_get_contents(\$filepath), true);
        
        if (!\$data || !isset(\$data['job'])) {
            return false;
        }
        
        \$jobClass = \$data['job']['name'] ?? '';
        if (!class_exists(\$jobClass)) {
            return false;
        }
        
        \$job = \$jobClass::unserialize(\$data['job'], \$this->app);
        \$job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        \$this->push(\$job, \$queue, 0);
        unlink(\$filepath);
        return true;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string \$jobId, string \$error): void
    {
        \$pattern = \$this->storagePath . 'processing/*_' . \$jobId . '.json';
        \$files = glob(\$pattern);
        if (empty(\$files)) {
            return;
        }
        \$this->moveToFailed(\$files[0], \$error);
        \$this->events->trigger('queue.marked_failed', [
            'job_id' => \$jobId,
            'error' => \$error,
        ]);
    }

    /**
     * Mark a job as completed
     */
    public function markAsCompleted(string \$jobId, array \$payload = []): void
    {
        \$pattern = \$this->storagePath . 'processing/*_' . \$jobId . '.json';
        \$files = glob(\$pattern);
        if (empty(\$files)) {
            // Try pending (unlikely)
            \$pattern = \$this->storagePath . 'pending/*_' . \$jobId . '.json';
            \$files = glob(\$pattern);
            if (empty(\$files)) {
                \$this->events->trigger('queue.warning', [
                    'message' => 'Job file not found for marking as completed',
                    'job_id'  => \$jobId,
                ]);
                return;
            }
        }
        \$this->moveToProcessed(\$files[0], \$payload);
        \$this->events->trigger('queue.marked_completed', [
            'job_id' => \$jobId,
            'payload' => \$payload,
        ]);
    }

    /**
     * Get queue statistics
     */
    public function getStats(string \$queue = 'default'): array
    {
        \$stats = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'delayed' => 0, 'processed' => 0];
        \$now = time();
        
        // Pending & delayed
        \$pattern = \$this->storagePath . 'pending/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        if (\$files) {
            foreach (\$files as \$file) {
                \$data = json_decode(file_get_contents(\$file), true);
                if (\$data) {
                    if (\$data['available_at'] > \$now) {
                        \$stats['delayed']++;
                    } else {
                        \$stats['pending']++;
                    }
                }
            }
        }
        
        // Processing
        \$pattern = \$this->storagePath . 'processing/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        \$stats['processing'] = \$files ? count(\$files) : 0;
        
        // Failed
        \$pattern = \$this->storagePath . 'failed/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        \$stats['failed'] = \$files ? count(\$files) : 0;
        
        // Processed
        \$pattern = \$this->storagePath . 'processed/' . \$queue . '_*.json';
        \$files = glob(\$pattern);
        \$stats['processed'] = \$files ? count(\$files) : 0;
        
        return \$stats;
    }
    
    /**
     * Clean up old job files
     */
    public function cleanup(int \$maxAge = 86400): int
    {
        \$count = 0;
        \$now = time();
        \$directories = ['pending', 'processing', 'failed', 'processed'];
        
        foreach (\$directories as \$dir) {
            \$pattern = \$this->storagePath . \$dir . '/*.json';
            \$files = glob(\$pattern);
            if (\$files) {
                foreach (\$files as \$file) {
                    if (\$now - filemtime(\$file) > \$maxAge) {
                        unlink(\$file);
                        \$count++;
                    }
                }
            }
        }
        return \$count;
    }
}
PHP;
}

    /**
     * Generate memory queue template
     */
    private function generateMemoryQueueTemplate(string $name): string
{
    return <<<PHP
<?php

namespace App\Queue\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * {$name} Queue Driver
 *
 * In-memory queue driver for testing and development.
 */
class {$name}Queue extends BaseQueue
{
    protected array \$queues = [];
    protected array \$processing = [];
    protected array \$failed = [];
    protected array \$processed = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        string \$name,
        array \$config = []
    ) {
        parent::__construct(\$app, \$name, \$config);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface \$job, string \$queue = 'default', int \$delay = 0): string
    {
        if (!isset(\$this->queues[\$queue])) {
            \$this->queues[\$queue] = [];
        }
        
        \$jobData = [
            'job' => \$job,
            'available_at' => time() + \$delay,
            'created_at' => time(),
        ];
        
        \$this->queues[\$queue][] = \$jobData;
        usort(\$this->queues[\$queue], function(\$a, \$b) {
            return \$a['available_at'] <=> \$b['available_at'];
        });
        
        \$this->events->trigger('queue.job.pushed', [
            'job_id' => \$job->getId(),
            'queue' => \$queue,
            'job_name' => \$job->getName(),
        ]);
        
        return \$job->getId();
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string \$queue = 'default'): ?JobInterface
    {
        if (empty(\$this->queues[\$queue])) {
            return null;
        }
        
        \$now = time();
        foreach (\$this->queues[\$queue] as \$index => \$jobData) {
            if (\$jobData['available_at'] <= \$now) {
                \$job = \$jobData['job'];
                \$this->processing[\$job->getId()] = [
                    'job' => \$job,
                    'queue' => \$queue,
                    'started_at' => \$now,
                    'index' => \$index,
                ];
                unset(\$this->queues[\$queue][\$index]);
                \$this->queues[\$queue] = array_values(\$this->queues[\$queue]);
                return \$job;
            }
        }
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface \$job, string \$queue = 'default', int \$delay = 0): bool
    {
        if (!isset(\$this->processing[\$job->getId()])) {
            return false;
        }
        unset(\$this->processing[\$job->getId()]);
        if (!isset(\$this->queues[\$queue])) {
            \$this->queues[\$queue] = [];
        }
        \$this->queues[\$queue][] = [
            'job' => \$job,
            'available_at' => time() + \$delay,
            'created_at' => time(),
        ];
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface \$job, string \$queue = 'default'): bool
    {
        if (isset(\$this->processing[\$job->getId()])) {
            unset(\$this->processing[\$job->getId()]);
            return true;
        }
        
        if (isset(\$this->queues[\$queue])) {
            foreach (\$this->queues[\$queue] as \$index => \$jobData) {
                if (\$jobData['job']->getId() === \$job->getId()) {
                    unset(\$this->queues[\$queue][\$index]);
                    \$this->queues[\$queue] = array_values(\$this->queues[\$queue]);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get the size of the queue
     */
    public function size(string \$queue = 'default'): int
    {
        if (!isset(\$this->queues[\$queue])) {
            return 0;
        }
        \$count = 0;
        \$now = time();
        foreach (\$this->queues[\$queue] as \$jobData) {
            if (\$jobData['available_at'] <= \$now) {
                \$count++;
            }
        }
        return \$count;
    }

    /**
     * Clear the queue
     */
    public function clear(string \$queue = 'default'): int
    {
        \$count = isset(\$this->queues[\$queue]) ? count(\$this->queues[\$queue]) : 0;
        unset(\$this->queues[\$queue]);
        foreach (\$this->processing as \$jobId => \$processing) {
            if (\$processing['queue'] === \$queue) {
                unset(\$this->processing[\$jobId]);
                \$count++;
            }
        }
        return \$count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        return array_keys(\$this->queues);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$failedInQueue = [];
        foreach (\$this->failed as \$jobId => \$failedJob) {
            if (\$failedJob['queue'] === \$queue) {
                \$failedInQueue[] = \$failedJob;
            }
        }
        usort(\$failedInQueue, function(\$a, \$b) {
            return \$b['failed_at'] <=> \$a['failed_at'];
        });
        return array_slice(\$failedInQueue, \$offset, \$limit);
    }

    /**
     * Get processed jobs
     */
    public function getProcessed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        \$processedInQueue = [];
        foreach (\$this->processed as \$jobId => \$processedJob) {
            if (\$processedJob['queue'] === \$queue) {
                \$processedInQueue[] = \$processedJob;
            }
        }
        usort(\$processedInQueue, function(\$a, \$b) {
            return \$b['processed_at'] <=> \$a['processed_at'];
        });
        return array_slice(\$processedInQueue, \$offset, \$limit);
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string \$jobId, string \$queue = 'default'): bool
    {
        if (!isset(\$this->failed[\$jobId])) {
            return false;
        }
        \$failedJob = \$this->failed[\$jobId];
        \$job = \$failedJob['job'];
        \$job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        \$this->push(\$job, \$queue, 0);
        unset(\$this->failed[\$jobId]);
        return true;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string \$jobId, string \$error): void
    {
        if (isset(\$this->processing[\$jobId])) {
            \$processing = \$this->processing[\$jobId];
            \$this->failed[\$jobId] = [
                'job' => \$processing['job'],
                'queue' => \$processing['queue'],
                'failed_at' => time(),
                'error' => \$error,
            ];
            unset(\$this->processing[\$jobId]);
            \$this->events->trigger('queue.marked_failed', [
                'job_id' => \$jobId,
                'queue' => \$processing['queue'],
                'error' => \$error,
            ]);
        }
    }

    /**
     * Mark a job as completed
     */
    public function markAsCompleted(string \$jobId, array \$payload = []): void
    {
        if (isset(\$this->processing[\$jobId])) {
            \$processing = \$this->processing[\$jobId];
            \$this->processed[\$jobId] = [
                'job' => \$processing['job'],
                'queue' => \$processing['queue'],
                'processed_at' => time(),
                'result_payload' => \$payload,
            ];
            unset(\$this->processing[\$jobId]);
            \$this->events->trigger('queue.marked_completed', [
                'job_id' => \$jobId,
                'queue' => \$processing['queue'],
                'payload' => \$payload,
            ]);
        }
    }

    /**
     * Get queue statistics
     */
    public function getStats(string \$queue = 'default'): array
    {
        \$stats = [
            'queued' => \$this->size(\$queue),
            'processing' => 0,
            'delayed' => 0,
            'failed' => 0,
            'processed' => 0,
        ];
        
        foreach (\$this->processing as \$processing) {
            if (\$processing['queue'] === \$queue) {
                \$stats['processing']++;
            }
        }
        
        if (isset(\$this->queues[\$queue])) {
            \$now = time();
            foreach (\$this->queues[\$queue] as \$jobData) {
                if (\$jobData['available_at'] > \$now) {
                    \$stats['delayed']++;
                }
            }
        }
        
        foreach (\$this->failed as \$failedJob) {
            if (\$failedJob['queue'] === \$queue) {
                \$stats['failed']++;
            }
        }
        
        foreach (\$this->processed as \$processedJob) {
            if (\$processedJob['queue'] === \$queue) {
                \$stats['processed']++;
            }
        }
        
        return \$stats;
    }
}
PHP;
}

    /**
     * Generate custom queue template
     */
    private function generateCustomQueueTemplate(string $name, string $type): string
    {
        return <<<PHP
<?php

namespace App\Queue\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * {$name} Queue Driver
 *
 * Custom queue driver for {$type} storage backend.
 */
class {$name}Queue extends BaseQueue
{
    protected array \$config = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        string \$name,
        array \$config = []
    ) {
        parent::__construct(\$app, \$name, \$config);
        
        \$this->config = array_merge([
            // Add your default configuration here
        ], \$config);
        
        \$this->initialize();
    }

    /**
     * Initialize the queue driver
     */
    protected function initialize(): void
    {
        // TODO: Initialize your queue backend connection
        // Example: Connect to external service, create tables, etc.
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface \$job, string \$queue = 'default', int \$delay = 0): string
    {
        // TODO: Implement job storage logic
        // Store the job in your backend with the given delay
        
        \$jobId = \$job->getId();
        
        \$this->events->trigger('queue.job.pushed', [
            'job_id' => \$jobId,
            'queue' => \$queue,
            'job_name' => \$job->getName(),
        ]);
        
        return \$jobId;
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string \$queue = 'default'): ?JobInterface
    {
        // TODO: Implement job retrieval logic
        // Get the next available job from your backend
        
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface \$job, string \$queue = 'default', int \$delay = 0): bool
    {
        // TODO: Implement job release logic
        // Update the job in your backend with new delay
        
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface \$job, string \$queue = 'default'): bool
    {
        // TODO: Implement job deletion logic
        // Remove the job from your backend
        
        return true;
    }

    /**
     * Get the size of the queue
     */
    public function size(string \$queue = 'default'): int
    {
        // TODO: Implement queue size calculation
        // Return the number of pending jobs in your backend
        
        return 0;
    }

    /**
     * Clear the queue
     */
    public function clear(string \$queue = 'default'): int
    {
        // TODO: Implement queue clearing logic
        // Remove all jobs for the given queue from your backend
        
        return 0;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        // TODO: Implement queue listing logic
        // Return all queue names available in your backend
        
        return ['default'];
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        // TODO: Implement health check logic
        // Verify that your backend is accessible and functioning
        
        return true;
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string \$queue = 'default', int \$limit = 50, int \$offset = 0): array
    {
        // TODO: Implement failed jobs retrieval
        // Return failed jobs from your backend
        
        return [];
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string \$jobId, string \$queue = 'default'): bool
    {
        // TODO: Implement failed job retry logic
        // Move the job from failed state back to pending
        
        return false;
    }

    /**
     * Get queue statistics
     */
    public function getStats(string \$queue = 'default'): array
    {
        // TODO: Implement statistics gathering
        // Return queue statistics from your backend
        
        return [
            'queued' => 0,
            'processing' => 0,
            'delayed' => 0,
            'failed' => 0,
        ];
    }

    /**
     * Clean up resources
     */
    public function __destruct()
    {
        // TODO: Clean up connections or resources
        // Close connections, release locks, etc.
    }
}
PHP;
    }

    /**
     * Create queue configuration file
     */
    private function createQueueConfig(string $name, string $type, array $data = []): string
    {
        $this->ensureDirectoryExists($this->configPath);
        
        $configFile = $this->configPath . 'queue.php';
        
        // Check if config file exists
        if (!file_exists($configFile)) {
            $this->createDefaultQueueConfig();
        }
        
        // Read current configuration
        $config = require $configFile;
        
        // Add or update driver configuration
        $driverName = strtolower(str_replace('Queue', '', $name));
        
        if (!isset($config['drivers'][$driverName])) {
            $config['drivers'][$driverName] = $this->getDefaultDriverConfig($type);
        }
        
        // Merge custom data
        if (!empty($data)) {
            $config['drivers'][$driverName] = array_merge($config['drivers'][$driverName], $data);
        }
        
        // Set as default if requested
        if ($data['default'] ?? false) {
            $config['default'] = $driverName;
        }
        
        // Write updated configuration
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($configFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to update queue configuration: {$configFile}",
                91009
            );
        }
        
        return $configFile;
    }

    /**
     * Create default queue configuration
     */
    public function createDefaultQueueConfig(): void
    {
        $configFile = $this->configPath . 'queue.php';
        
        $content = <<<'PHP'
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default queue driver that will be used
    | by the application. You may change this to any supported driver.
    |
    | Supported: "sync", "database", "redis", "file", "memory"
    |
    */
    'default' => env('QUEUE_DRIVER', 'sync'),
    
    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for each queue driver
    | that is supported by the application.
    |
    */
    'drivers' => [
        'sync' => [
            'driver' => 'sync',
        ],
        
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'failed_table' => 'failed_jobs',
        ],
        
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
        ],
        
        'file' => [
            'driver' => 'file',
            'path' => __DIR__ . '/../storage/queue',
            'retry_after' => 90,
        ],
        
        'memory' => [
            'driver' => 'memory',
            'retry_after' => 90,
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging.
    |
    */
    'failed' => [
        'driver' => 'database',
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];
PHP;
        if (!file_exists($configFile)) {
            if (file_put_contents($configFile, $content) === false) {
                throw new MachinjiriException(
                    "Failed to create queue configuration: {$configFile}",
                    91010
                );
            }
        }
        
    }

    /**
     * Get default driver configuration
     */
    private function getDefaultDriverConfig(string $type): array
    {
        $configs = [
            'database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'failed_table' => 'failed_jobs',
            ],
            'redis' => [
                'driver' => 'redis',
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => null,
                'database' => 0,
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'sync' => [
                'driver' => 'sync',
            ],
            'file' => [
                'driver' => 'file',
                'path' => '',
                'retry_after' => 90,
            ],
            'memory' => [
                'driver' => 'memory',
                'retry_after' => 90,
            ],
        ];
        
        return $configs[$type] ?? [
            'driver' => 'custom',
            'type' => $type,
        ];
    }

    /**
     * Create job migration
     */
    private function createJobMigration(string $jobName): string
    {
        $this->ensureDirectoryExists($this->migrationsPath);
        
        $timestamp = date('Y_m_d_His');
        $tableName = $this->snakeCase(str_replace('Job', '', $jobName)) . 's';
        $migrationName = "create_{$tableName}_table";
        $migrationFile = $this->migrationsPath . "{$timestamp}_{$migrationName}.php";
        
        $template = <<<PHP
<?php

use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;

class {$migrationName}
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        \$query = new QueryBuilder('');
        \$sql = \$query->createTable('{$tableName}', [
            'id' => \$query->id()->primary()->autoincrement(),
            'name' => \$query->string('name', 255)->notNull(),
            'description' => \$query->text('description')->nullable(),
            'status' => \$query->string('status', 50)->default('pending'),
            'created_at' => \$query->timestamp('created_at')->default('CURRENT_TIMESTAMP'),
            'updated_at' => \$query->timestamp('updated_at')->default('CURRENT_TIMESTAMP'),
            'deleted_at' => \$query->timestamp('deleted_at')->nullable(),
        ])->compileCreateTable();
        
        DatabaseConnection::executeQuery(\$sql);
        
        // Add indexes
        DatabaseConnection::executeQuery("CREATE INDEX idx_{$tableName}_status ON {$tableName}(status)");
        DatabaseConnection::executeQuery("CREATE INDEX idx_{$tableName}_created_at ON {$tableName}(created_at)");
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        \$query = new QueryBuilder('');
        \$sql = \$query->dropTable('{$tableName}')->compileDropTable();
        DatabaseConnection::executeQuery(\$sql);
    }
}
PHP;

        if (file_put_contents($migrationFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create migration file: {$migrationFile}",
                91011
            );
        }
        
        return $migrationFile;
    }

    /**
     * Register job in configuration
     */
    private function registerJobInConfig(string $name, array $options): void
    {
        $configFile = $this->configPath . 'jobs.php';
        
        // Create jobs config if it doesn't exist
        if (!file_exists($configFile)) {
            $this->createDefaultJobsConfig();
        }
        
        // Read current configuration
        $config = require $configFile;
        
        // Add job configuration
        $jobKey = strtolower(str_replace('Job', '', $name));
        
        if (!isset($config['jobs'][$jobKey])) {
            $config['jobs'][$jobKey] = [
                'queue' => $options['queue'],
                'max_attempts' => $options['max_attempts'],
                'timeout' => $options['timeout'],
                'class' => "App\\Jobs\\{$name}",
            ];
        }
        
        // Write updated configuration
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($configFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to update jobs configuration: {$configFile}",
                91012
            );
        }
    }

    /**
     * Create default jobs configuration
     */
    private function createDefaultJobsConfig(): void
    {
        $configFile = $this->configPath . 'jobs.php';
        
        $content = <<<'PHP'
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Job Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for all jobs in the application.
    | You can specify queue, retry settings, and other job-specific options.
    |
    */
    
    'defaults' => [
        'queue' => 'default',
        'max_attempts' => 3,
        'timeout' => 60,
        'retry_delay' => 60,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Job Definitions
    |--------------------------------------------------------------------------
    |
    | Define specific configurations for each job class.
    | These settings will override the defaults.
    |
    */
    'jobs' => [
        // Example:
        // 'send_email' => [
        //     'queue' => 'emails',
        //     'max_attempts' => 5,
        //     'timeout' => 120,
        //     'class' => App\Jobs\SendEmailJob::class,
        // ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Queue Priority
    |--------------------------------------------------------------------------
    |
    | Define the priority order for queues when multiple queues are processed.
    |
    */
    'queue_priority' => [
        'high',
        'default',
        'low',
        'emails',
        'notifications',
    ],
];
PHP;

        if (file_put_contents($configFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to create jobs configuration: {$configFile}",
                91013
            );
        }
    }

    /**
     * Register queue in service providers
     */
    private function registerQueueInProviders(string $name, string $type): void
    {
        $providersConfig = $this->configPath . 'providers.php';
        
        if (!file_exists($providersConfig)) {
            return;
        }
        
        // Read current configuration
        $config = require $providersConfig;
        
        try {
          $this->ensureQueueServiceProviderExistsInConfig();
          if (!in_array($type, ['providers', 'deffered'])) {
            throw new MachinjiriException('Unknown Provider type');
          }
          if (!in_array($queueProvider, $config[$type] ?? [])) {
            $config[$type][] = $name;
            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($providersConfig, $content) === false) {
              throw new MachinjiriException(
                  "Failed to update providers configuration: {$providersConfig}",
                  91014
              );
            }
          }
        } catch (MachinjiriException $e) {
          $e->show();
        }
        
    }
    
    public function ensureQueueServiceProviderExistsInConfig() : void
    {
      $providersConfig = $this->configPath . 'providers.php';
      if (!file_exists($providersConfig)) {
          return;
      }
      $config = require $providersConfig;
      $queueProvider = "App\\Providers\\QueueServiceProvider";
      if (!in_array($queueProvider, $config['providers'] ?? [])) {
            $config['providers'][] = $queueProvider;
            // Write updated configuration
            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($providersConfig, $content) === false) {
                throw new MachinjiriException(
                    "Failed to update providers configuration: {$providersConfig}",
                    91014
                );
            }
        }
        $providerPath = $this->appBasePath . '/app/Providers/QueueServiceProvider.php';
        
        $this->generateQueueServiceProvider();
    }

    /**
     * Generate queue service provider
     */
    public function generateQueueServiceProvider(): string
    {
        $providerPath = $this->appBasePath . '/app/Providers/QueueServiceProvider.php';
        
        $template = <<<'PHP'
<?php

namespace App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;

class QueueServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Register queue bindings
        $this->bind('queue', function($app) {
            $config = $app->getConfigurations()['queue'] ?? [];
            $driver = $config['default'] ?? env('QUEUE_DRIVER');
            
            return $this->createQueueDriver($driver, $config);
        });
        
        // Register queue worker
        $this->singleton('queue.worker', function($app) {
            $queue = $app->resolve('queue');
            $processor = $app->resolve('queue.processor');
            
            return new \Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseWorker($app, $queue, $processor);
        });
        
        // Register job processor
        $this->singleton('queue.processor', function($app) {
            return new class($app) extends \Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJobProcessor {};
        });
        
        // Register job dispatcher
        $this->singleton('queue.dispatcher', function($app) {
            $queue = $app->resolve('queue');
            return new \Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJobDispatcher($app, $queue);
        });
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Load queue configuration
        $this->mergeConfigFrom($this->app->config . 'queue.php', 'queue');
        
        // Create jobs table if using database driver
        $this->createJobsTableIfNeeded();
        
    }

    /**
     * Create queue driver instance
     */
    protected function createQueueDriver(string $driver, array $config): \Mlangeni\Machinjiri\Core\Artisans\Contracts\QueueInterface
    {
        $driverConfig = $config['drivers'][$driver] ?? [];
        
        switch ($driver) {
            case 'database':
                return new \App\Queue\Drivers\DatabaseQueue($this->app, $driver, $driverConfig);
            case 'redis':
                return new \App\Queue\Drivers\RedisQueue($this->app, $driver, $driverConfig);
            case 'file':
                return new \App\Queue\Drivers\FileQueue($this->app, $driver, $driverConfig);
            case 'memory':
                return new \App\Queue\Drivers\MemoryQueue($this->app, $driver, $driverConfig);
            case 'sync':
                return new \App\Queue\Drivers\SyncQueue($this->app, $driver, $driverConfig);
            default:
                // Try to load custom driver
                $driverClass = "App\\Queue\\Drivers\\" . ucfirst($driver) . 'Queue';
                if (class_exists($driverClass)) {
                    return new $driverClass($this->app, $driver, $driverConfig);
                }
                
                throw new \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException(
                    "Queue driver not found: {$driver}"
                );
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
            
            $query = new \Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder('');
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
PHP;

        if (!file_exists($providerPath)) {
          if (file_put_contents($providerPath, $template) === false) {
              throw new MachinjiriException(
                  "Failed to create queue service provider: {$providerPath}",
                  91015
              );
          }
        }
        
        return $providerPath;
    }

    /**
     * Generate all basic job and queue templates
     *
     * @return array Created files
     * @throws MachinjiriException
     */
    public function generateAllBasic(): array
    {
        $createdFiles = [];
        
        // Generate basic job types
        $jobs = [
            'SendEmailJob' => [
                'type' => 'email',
                'queue' => 'emails',
                'max_attempts' => 5,
                'timeout' => 120,
            ],
            'ProcessImageJob' => [
                'type' => 'model',
                'queue' => 'images',
                'max_attempts' => 3,
                'timeout' => 180,
                'database' => true,
            ],
            'GenerateReportJob' => [
                'type' => 'report',
                'queue' => 'reports',
                'max_attempts' => 3,
                'timeout' => 300,
            ],
            'SendNotificationJob' => [
                'type' => 'notification',
                'queue' => 'notifications',
                'max_attempts' => 3,
                'timeout' => 60,
            ],
        ];
        
        foreach ($jobs as $name => $options) {
            try {
                $file = $this->generateJob($name, $options);
                $createdFiles[] = $file;
            } catch (MachinjiriException $e) {
                // Skip if job already exists
                if ($e->getCode() !== 91003) {
                    throw $e;
                }
            }
        }
        
        // Generate basic queue drivers
        $queues = [
            'DatabaseQueue' => ['type' => 'database'],
            'RedisQueue' => ['type' => 'redis'],
            'SyncQueue' => ['type' => 'sync'],
            'FileQueue' => ['type' => 'file'],
            'MemoryQueue' => ['type' => 'memory'],
        ];
        
        foreach ($queues as $name => $options) {
            try {
                $file = $this->generateQueueDriver($name, $options);
                $createdFiles[] = $file;
            } catch (MachinjiriException $e) {
                // Skip if queue already exists
                if ($e->getCode() !== 91007) {
                    throw $e;
                }
            }
        }
        
        // Create configuration files
        
        $this->createDefaultQueueConfig();
        
        if (!file_exists($this->configPath . 'jobs.php')) {
            $this->createDefaultJobsConfig();
            $createdFiles[] = $this->configPath . 'jobs.php';
        }
        
        // Create jobs table migration
        $migrationFile = $this->createJobsMigration();
        $createdFiles[] = $migrationFile;
        
        return $createdFiles;
    }

    /**
     * Create jobs table migration
     */
    private function createJobsMigration(): string
    {
        $this->ensureDirectoryExists($this->migrationsPath);
        
        $timestamp = date('Y_m_d_His');
        $migrationFile = $this->migrationsPath . "{$timestamp}_create_jobs_table.php";
        
        $template = <<<'PHP'
<?php

use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;

class CreateJobsTable
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        $query = new QueryBuilder('');
        $sql = $query->createTable('jobs', [
            'id' => $query->id()->primary()->autoincrement(),
            'queue' => $query->string('queue', 255)->notNull(),
            'payload' => $query->text('payload'),
            'attempts' => $query->integer('attempts')->default(0),
            'reserved_at' => $query->integer('reserved_at')->default(0),
            'available_at' => $query->integer('available_at')->notNull(),
            'created_at' => $query->integer('created_at')->notNull(),
        ])->compileCreateTable();
        
        DatabaseConnection::executeQuery($sql);
        
        // Add indexes
        DatabaseConnection::executeQuery("CREATE INDEX idx_jobs_queue ON jobs(queue)");
        DatabaseConnection::executeQuery("CREATE INDEX idx_jobs_reserved_at ON jobs(reserved_at)");
        DatabaseConnection::executeQuery("CREATE INDEX idx_jobs_available_at ON jobs(available_at)");
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        $query = new QueryBuilder('');
        $sql = $query->dropTable('jobs')->compileDropTable();
        DatabaseConnection::executeQuery($sql);
    }
}
PHP;

        if (file_put_contents($migrationFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create jobs migration file: {$migrationFile}",
                91016
            );
        }
        
        return $migrationFile;
    }

    /**
     * List existing jobs
     *
     * @return array
     */
    public function listJobs(): array
    {
        $jobs = [];
        
        if (!is_dir($this->jobsPath)) {
            return $jobs;
        }
        
        $files = scandir($this->jobsPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, 'Job.php')) {
                continue;
            }
            
            $jobName = pathinfo($file, PATHINFO_FILENAME);
            $jobClass = "App\\Jobs\\{$jobName}";
            
            $jobs[] = [
                'name' => $jobName,
                'file' => $file,
                'path' => $this->jobsPath . $file,
                'class' => $jobClass,
                'exists' => class_exists($jobClass),
            ];
        }
        
        return $jobs;
    }

    /**
     * List existing queue drivers
     *
     * @return array
     */
    public function listQueues(): array
    {
        $queues = [];
        
        if (!is_dir($this->queuesPath)) {
            return $queues;
        }
        
        $files = scandir($this->queuesPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, 'Queue.php')) {
                continue;
            }
            
            $queueName = pathinfo($file, PATHINFO_FILENAME);
            $queueClass = "App\\Queue\\Drivers\\{$queueName}";
            
            $queues[] = [
                'name' => $queueName,
                'file' => $file,
                'path' => $this->queuesPath . $file,
                'class' => $queueClass,
                'exists' => class_exists($queueClass),
            ];
        }
        
        return $queues;
    }

    /**
     * Remove a job
     *
     * @param string $name
     * @return bool
     * @throws MachinjiriException
     */
    public function removeJob(string $name): bool
    {
        $name = $this->normalizeJobName($name);
        $jobFile = $this->jobsPath . $name . '.php';
        
        if (file_exists($jobFile)) {
            if (!unlink($jobFile)) {
                throw new MachinjiriException(
                    "Failed to remove job file: {$jobFile}",
                    91017
                );
            }
            return true;
        }
        
        return false;
    }

    /**
     * Remove a queue driver
     *
     * @param string $name
     * @return bool
     * @throws MachinjiriException
     */
    public function removeQueue(string $name): bool
    {
        $name = $this->normalizeQueueName($name);
        $queueFile = $this->queuesPath . $name . '.php';
        
        if (file_exists($queueFile)) {
            if (!unlink($queueFile)) {
                throw new MachinjiriException(
                    "Failed to remove queue driver file: {$queueFile}",
                    91018
                );
            }
            return true;
        }
        
        return false;
    }

    /**
     * Ensure directory exists
     *
     * @param string $directory
     * @throws MachinjiriException
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new MachinjiriException(
                "Failed to create directory: {$directory}",
                91019
            );
        }
    }

    /**
     * Convert string to snake_case
     */
    private function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<=\\w)(?=[A-Z])/', '_$1', $value);
        return strtolower($value);
    }

    /**
     * Generate sync job template (for immediate processing)
     */
    private function generateSyncJobTemplate(string $name): string
    {
        $lowerName = strtolower($name);
        
        return <<<PHP
<?php

namespace App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * {$name} Job
 *
 * Synchronous job for immediate processing (bypasses queue).
 */
class {$name}Job extends BaseJob
{
    /**
     * Create a new job instance
     */
    public function __construct(\Mlangeni\Machinjiri\Core\Container \$app, array \$payload = [], array \$options = [])
    {
        // Sync jobs run immediately with no queue
        \$defaultOptions = [
            'maxAttempts' => 1,
            'queue' => 'sync',
            'timeout' => 30,
            'delay' => 0,
        ];
        
        parent::__construct(\$app, \$payload, array_merge(\$defaultOptions, \$options));
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        // Get payload data
        \$data = \$this->getPayload();
        
        // TODO: Implement your synchronous job logic here
        
        // Example: Immediate processing
        // \$result = \$this->processImmediately(\$data);
        
        \$this->addMetadata('processed_sync', date('Y-m-d H:i:s'));
        \$this->addMetadata('execution_mode', 'synchronous');
    }

    /**
     * Handle job failure
     */
    public function failed(MachinjiriException \$exception): void
    {
        // Sync jobs fail immediately - log and throw
        error_log('Sync job failed immediately: ' . \$exception->getMessage());
        
        // Re-throw for immediate handling
        throw \$exception;
    }

    /**
     * Process immediately
     */
    protected function processImmediately(array \$data): mixed
    {
        // Process data immediately without queue
        // This method is called directly, not through queue worker
        
        return \$data;
    }
}
PHP;
    }
    
    /**
     * Register queue driver in command configuration
     */
    private function registerQueueInCommand(string $name, string $type): void
    {
        $driverName = strtolower(str_replace('Queue', '', $name));
        
        // Read or create command config
        $commandConfigFile = $this->configPath . 'commands.php';
        
        if (!file_exists($commandConfigFile)) {
            $this->createDefaultCommandConfig();
        }
        
        $config = require $commandConfigFile;
        
        // Add queue driver to command configuration
        if (!isset($config['queue']['drivers'][$driverName])) {
            $config['queue']['drivers'][$driverName] = [
                'class' => "App\\Queue\\Drivers\\{$name}",
                'type' => $type,
                'enabled' => true,
            ];
        }
        
        // Update command config
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($commandConfigFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to update command configuration: {$commandConfigFile}",
                91020
            );
        }
        
    }

    /**
     * Register job in command configuration
     */
    private function registerJobInCommand(string $name, string $queue): void
    {
        $jobKey = strtolower(str_replace('Job', '', $name));
        
        // Read or create command config
        $commandConfigFile = $this->configPath . 'commands.php';
        
        if (!file_exists($commandConfigFile)) {
            $this->createDefaultCommandConfig();
        }
        
        $config = require $commandConfigFile;
        
        // Add job to command configuration
        if (!isset($config['jobs'][$jobKey])) {
            $config['jobs'][$jobKey] = [
                'class' => "App\\Jobs\\{$name}",
                'queue' => $queue,
                'command' => "queue:job {$jobKey}",
                'enabled' => true,
            ];
        }
        
        // Update command config
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($commandConfigFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to update command configuration: {$commandConfigFile}",
                91021
            );
        }
    }

    /**
     * Create default command configuration
     */
    private function createDefaultCommandConfig(): void
    {
        $commandConfigFile = $this->configPath . 'commands.php';
        
        $content = <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Queue Commands Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for queue-related console commands.
    |
    */
    'queue' => [
        'drivers' => [
        ],
        'worker_options' => [
            'sleep' => 3,
            'memory' => 128,
            'timeout' => 60,
            'max_jobs' => null,
            'stop_on_empty' => false,
            'restart_signal' => 'USR2',
        ],
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Job Commands
    |--------------------------------------------------------------------------
    |
    | Configuration for job-specific console commands.
    |
    */
    'jobs' => [
        // Job commands will be registered here automatically
    ],
];
PHP;

        if (file_put_contents($commandConfigFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to create command configuration: {$commandConfigFile}",
                91022
            );
        }
    }

    /**
     * Generate command line usage example
     */
    public function generateCommandUsage(string $name, string $type): string
    {
        $driverKey = strtolower(str_replace('Queue', '', $name));
        
        $usage = <<<TEXT
Queue Driver: {$name} ({$type})
        
Command Line Usage:
-------------------
1. Start worker with this driver:
   php artisan queue:work --driver={$driverKey}
   
2. Specify queue name:
   php artisan queue:work --driver={$driverKey} --queue=default
   
3. With custom options:
   php artisan queue:work --driver={$driverKey} --sleep=5 --memory=256 --timeout=120
   
4. Process specific number of jobs:
   php artisan queue:work --driver={$driverKey} --max-jobs=100
   
5. Stop when queue is empty:
   php artisan queue:work --driver={$driverKey} --stop-on-empty
   
Configuration:
--------------
Add to config/queue.php:
'drivers' => [
    '{$driverKey}' => [
        'driver' => '{$type}',
        // Add your configuration here
    ],
],

Set as default driver:
'default' => '{$driverKey}',

Service Provider:
-----------------
Register in app/Providers/QueueServiceProvider.php:

\$this->app->bind('queue.driver.{$driverKey}', function (\$app) {
    return new \\App\\Queue\\Drivers\\{$name}Queue(\$app, '{$driverKey}', \$app['config']['queue.drivers.{$driverKey}']);
});
TEXT;
        
        return $usage;
    }
  
  public function generateQueueDriverIfNotExists(string $name, array $options = []): ?string
  {
      $normalizedName = $this->normalizeQueueName($name);
      $queueFile = $this->queuesPath . $normalizedName . '.php';
  
      // If the file already exists, simply return the path without doing anything
      if (file_exists($queueFile)) {
          return $queueFile;
      }
  
      // Otherwise, delegate to the normal (potentially throwing) method
      return $this->generateQueueDriver($name, $options);
  }
  /**
   * Generate mail job template
   */
  private function generateMailJobTemplate(string $name, string $queue, int $maxAttempts, int $timeout, int $delay): string
  {
      $lowerName = strtolower($name);
      
      return <<<PHP
<?php

namespace App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * {$name} Job
 *
 * This job handles sending an email asynchronously.
 */
class {$name}Job extends BaseJob
{
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container \$app,
        array \$payload,
        array \$options = []
    ) {
        \$defaultOptions = [
            'maxAttempts' => 3,
            'queue' => 'mails',
            'timeout' => 60,
            'delay' => 0,
        ];
        
        parent::__construct(\$app, \$payload, array_merge(\$defaultOptions, \$options));
    }

    /**
     * Execute the job – send the email
     */
    public function handle(): void
    {
        \$data = \$this->getPayload();
        \$messageData = \$data['message'] ?? [];
        \$transport = \$data['transport'] ?? null;
        
        if (empty(\$messageData)) {
            throw new MachinjiriException('No email message data provided');
        }
        
        // Reconstruct MailMessage from serialized data
        \$message = MailMessage::fromArray(\$messageData);
        
        /** @var MailManager \$mailManager */
        \$mailManager = \$this->app->make(MailManager::class);
        
        \$response = \$mailManager->send(\$message, \$transport);
        
        \$this->addMetadata('message_id', \$response->getMessageId());
        \$this->addMetadata('sent_at', date('Y-m-d H:i:s'));
        
    }

    /**
     * Handle job failure
     */
    public function failed(MachinjiriException \$exception): void
    {
        parent::failed(\$exception);
        
        // Additional failure handling – e.g., log to a dead‑letter queue
        resolve(EventListener::class)->trigger('mail.job.failed', [
            'job_id' => \$this->getId(),
            'exception' => \$exception->getMessage(),
            'payload' => \$this->getPayload(),
        ]);
    }
}
PHP;
    }  
}