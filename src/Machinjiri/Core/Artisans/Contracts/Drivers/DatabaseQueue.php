<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseQueue, JobInterface};
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\DriverException;

/**
 * Database Queue Driver
 *
 * Database-based queue driver for persistent job storage.
 */
class DatabaseQueue extends BaseQueue
{
    protected QueryBuilder $queryBuilder;
    protected string $tableName = 'jobs';
    protected array $config = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        Container $app,
        string $name,
        array $config = []
    ) {
        parent::__construct($app, $name, $config);
        
        $this->config = array_merge([
            'table' => 'jobs',
            'connection' => 'default',
            'retry_after' => 90,
            'failed_table' => 'failed_jobs',
            'processed_table' => 'processed_jobs',
        ], $config);
        
        $this->tableName = $this->config['table'];
        $this->queryBuilder = new QueryBuilder($this->tableName);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $data = [
            'queue' => $queue,
            'job_id' => $job->getId(),
            'payload' => json_encode($job->serialize()),
            'attempts' => $job->getAttempts(),
            'available_at' => time() + $delay,
            'created_at' => time(),
            'reserved_at' => 0,
        ];
        
        $result = $this->queryBuilder->insert($data)->execute();
        $jobId = $job->getId();
        
        $this->events->trigger('queue.job.pushed', [
            'job_id' => $jobId,
            'queue' => $queue,
            'job_name' => $job->getName(),
        ]);
        
        return (string) $jobId;
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue): ?JobInterface
    {
        $now = time();
        $retryAfter = $this->config['retry_after'];
        
        // Find and reserve a job
        $job = $this->queryBuilder
            ->select()
            ->where('queue', '=', $queue)
            ->orderBy('created_at', 'ASC')
            ->limit(1)
            ->first();
            
        if (!$job || $job === null) {
            return null;
        }
        
        // Mark as reserved
        $this->queryBuilder
            ->update(['reserved_at' => $now])
            ->where('job_id', '=', $job['job_id'])
            ->execute();
            
        // Unserialize job
        $jobData = json_decode($job['payload'], true);
        
        if (!$jobData) {
            throw new DriverException('Invalid job payload');
        }
        
        $jobClass = $jobData['name'] ?? '';
        
        if (!class_exists($jobClass)) {
            // Move to failed jobs
            $this->markAsFailed($job['job_id'], 'Job class not found: ' . $jobClass);
            return null;
        }
        
        return $jobClass::unserialize($jobData, $this->app);
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        $serialized = json_encode($job->serialize());

        // check if job is not already in queue 
        $result = $this->queryBuilder
            ->select()
            ->where('job_id', '=', $job->getId())
            ->where('queue', '=', $queue)
            ->first();

        if ($result !== null) {
            return false;
        }
        
        $result = $this->queryBuilder
            ->update([
                'payload' => $serialized,
                'attempts' => $job->getAttempts(),
                'available_at' => time() + $delay,
                'reserved_at' => 0,
            ])
            ->where('job_id', '=', $job->getId())
            ->where('queue', '=', $queue)
            ->execute();
            
        return $result['rowCount'] > 0;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        $result = $this->queryBuilder
            ->delete()
            ->where('job_id', '=', $job->getId())
            ->where('queue', '=', $queue)
            ->execute();
            
        return $result['rowCount'] > 0;
    }

    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int
    {
        $result = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('available_at', '<=', time())
            ->where('reserved_at', '=', 0)
            ->first();
            
        return $result['count'] ?? 0;
    }

    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int
    {
        $result = $this->queryBuilder
            ->delete()
            ->where('queue', '=', $queue)
            ->execute();
            
        return $result['rowCount'] ?? 0;
    }

    public function clearFailed(string $queue = 'default'): int
    {
        $result = (new QueryBuilder($this->config['failed_table']))
            ->delete()
            ->where('queue', '=', $queue)
            ->execute();
            
        return $result['rowCount'] ?? 0;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        $result = $this->queryBuilder
            ->select(['DISTINCT queue'])
            ->execute();
            
        return array_column($result, 'queue');
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        try {
            // Test database connection
            $this->queryBuilder->select(['1'])->first();
            return true;
        } catch (DriverException $e) {
            return false;
        }
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        $failedTable = $this->config['failed_table'] ?? 'failed_jobs';
        $query = new QueryBuilder($failedTable);
        
        $result = $query
            ->select()
            ->where('queue', '=', $queue)
            ->orderBy('failed_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->execute();
            
        return $result;
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string $jobId, string $queue = 'default'): bool
    {
        $failedTable = $this->config['failed_table'] ?? 'failed_jobs';
        $failedQuery = new QueryBuilder($failedTable);
        
        // Get failed job
        $failedJob = $failedQuery
            ->select()
            ->where('job_id', '=', $jobId)
            ->first();
            
        if (!$failedJob) {
            return false;
        }
        
        // Move back to jobs table
        $jobData = json_decode($failedJob['payload'], true);
        
        if (!$jobData) {
            return false;
        }
        
        $jobClass = $jobData['name'] ?? '';
        
        if (!class_exists($jobClass)) {
            return false;
        }
        
        $job = $jobClass::unserialize($jobData, $this->app);
        $job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        
        // Push back to queue
        $this->push($job, $queue, 0);
        
        // Remove from failed jobs
        $failedQuery
            ->delete()
            ->where('job_id', '=', $jobId)
            ->execute();
            
        return true;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string $jobId, string $error): void
    {
        // Get the job from jobs table
        $job = $this->queryBuilder
            ->select()
            ->where('job_id', '=', $jobId)
            ->first();
            
        if (!$job || $job === null) {
            return;
        }

        try {
            // Move to failed jobs table
            $failedQuery = new QueryBuilder($this->config['failed_table'] ?? 'failed_jobs');

            // check if job already exists in failed
            $exists = $failedQuery->select()->where('job_id', '=', $job['job_id'])->first();

            if ($exists === null || !$exists) {
                $result = $failedQuery->insert([
                    'queue' => $job['queue'],
                    'job_id' => $job['job_id'],
                    'payload' => $job['payload'],
                    'exception' => $error,
                    'failed_at' => time(),
                ])->execute();
            }

            // Remove from jobs table
            $this->queryBuilder
                ->delete()
                ->where('job_id', '=', $jobId)
                ->execute();
            
        } catch (\Throwable $exception) {
            dd("Failed to mark job as failed: {$exception->getMessage()}");
        }
        
    }

    /**
     * Mark a job as completed
     */
    public function markAsCompleted(string $jobId, array $payload = []): void
    {
        // Get the job from jobs table
        $job = $this->queryBuilder
            ->select()
            ->where('job_id', '=', $jobId)
            ->first();
            
        if (!$job || $job === null) {
            return;
        }
        
        // Move to processed jobs table
        $provessedQuery = new QueryBuilder($this->config['processed_table'] ?? 'processed_jobs');

        // check if job already exists in processed
        $exists = $provessedQuery->select()->where('job_id', '=', $job['job_id'])->first();
        if ($exists === null || !$exists) {
            $result = $provessedQuery->insert([
                'queue' => $job['queue'],
                'job_id' => $job['job_id'],
                'payload' => json_encode($payload, true),
                'processed_at' => time(),
            ])->execute();
    
            if ($result['lastInsertId'] || $result['lastInsertId'] > 0) {
                // Remove from jobs table
                $this->queryBuilder
                    ->delete()
                    ->where('job_id', '=', $jobId)
                    ->execute();
            }
        }
        
    }

    /**
     * Get queue statistics
     */
    public function getStats(string $queue = 'default'): array
    {
        $stats = [];
        
        // Total jobs
        $totalResult = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->first();
        $stats['total'] = $totalResult['count'] ?? 0;
        
        // Pending jobs
        $pendingResult = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('available_at', '<=', time())
            ->where('reserved_at', '=', 0)
            ->first();
        $stats['pending'] = $pendingResult['count'] ?? 0;
        
        // Reserved jobs
        $reservedResult = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('reserved_at', '>', 0)
            ->first();
        $stats['reserved'] = $reservedResult['count'] ?? 0;
        
        // Delayed jobs
        $delayedResult = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('available_at', '>', time())
            ->first();
        $stats['delayed'] = $delayedResult['count'] ?? 0;
        
        return $stats;
    }
    
    /**
     * Get database connection
     */
    public static function getConnection ()
    {
      return DatabaseConnection::getInstance();
    }

    
}
