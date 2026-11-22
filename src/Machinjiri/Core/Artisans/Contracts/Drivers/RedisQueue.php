<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Adapters\Redis\RedisAdapter;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseQueue, JobInterface};

/**
 * Redis Queue Driver (Predis based)
 *
 * Redis-based queue driver for high-performance job processing.
 */
class RedisQueue extends BaseQueue
{
    protected RedisAdapter $redis;
    protected array $config = [];
    protected string $prefix = 'queue:';

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container $app,
        string $name,
        array $config = []
    ) {
        parent::__construct($app, $name, $config);
        
        $this->config = array_merge([
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => env('REDIS_DATABASE', 0),
            'prefix' => 'queue:',
            'retry_after' => 90,
            'timeout' => 2.5,
        ], $config);
        
        $this->prefix = $this->config['prefix'];
        $this->connectRedis();
    }

    /**
    * Connect to Redis using the framework Redis adapter
     */
    protected function connectRedis(): void
    {
        $this->redis = new RedisAdapter([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'host' => $this->config['host'],
                    'port' => $this->config['port'],
                    'password' => $this->config['password'],
                    'database' => $this->config['database'],
                    'timeout' => $this->config['timeout'],
                ],
            ],
            'prefix' => '',
            'serialize' => false,
        ]);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $key = $this->getQueueKey($queue);
        $jobId = $job->getId();
        $serialized = json_encode($job->serialize());
        
        if ($delay > 0) {
            // Delayed queue
            $delayedKey = $this->getDelayedQueueKey($queue);
            $score = time() + $delay;
            $this->redis->zadd($delayedKey, $score, $serialized);
        } else {
            // Immediate queue
            $this->redis->rpush($key, $serialized);
        }
        
        // Store job metadata
        $metaKey = $this->getJobMetaKey($jobId);
        $this->redis->hmset($metaKey, [
            'queue' => $queue,
            'created_at' => time(),
            'delay' => $delay,
        ]);
        
        $this->events->trigger('queue.job.pushed', [
            'job_id' => $jobId,
            'queue' => $queue,
            'job_name' => $job->getName(),
        ]);
        
        return $jobId;
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        // Check delayed queue for ready jobs
        $this->migrateDelayedJobs($queue);
        $this->recoverTimedOutJobs($queue);
        
        $key = $this->getQueueKey($queue);
        $reservedKey = $this->getReservedQueueKey($queue);
        
        // Atomically move the oldest job from the active queue to the reserved queue.
        $serialized = $this->redis->eval(
            "local job = redis.call('LPOP', KEYS[1]) " .
            "if job then redis.call('RPUSH', KEYS[2], job) end " .
            "return job",
            2,
            $key,
            $reservedKey
        );
        
        if (!$serialized) {
            return null;
        }
        
        $jobData = json_decode($serialized, true);
        
        if (!$jobData) {
            $this->redis->lrem($reservedKey, 1, $serialized);
            return null;
        }
        
        $jobClass = $jobData['name'] ?? '';
        
        if (!class_exists($jobClass)) {
            $this->redis->lrem($reservedKey, 1, $serialized);
            $this->moveToFailed($queue, $serialized, 'Job class not found');
            return null;
        }
        
        // Set reservation timeout
        $jobId = $jobData['id'] ?? '';
        if ($jobId) {
            $timeoutKey = $this->getTimeoutKey($jobId);
            $this->redis->setex($timeoutKey, (int) $this->config['retry_after'], '1');
        }

        try {
            return $jobClass::unserialize($jobData, $this->app);
        } catch (\Throwable $e) {
            $this->redis->lrem($reservedKey, 1, $serialized);
            if ($jobId) {
                $this->redis->del($this->getTimeoutKey($jobId));
            }
            $this->moveToFailed($queue, $serialized, 'Job unserialization failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Migrate delayed jobs to active queue
     */
    protected function migrateDelayedJobs(string $queue): void
    {
        $delayedKey = $this->getDelayedQueueKey($queue);
        $key = $this->getQueueKey($queue);
        $now = time();

        // The ZSET lookup and list/ZSET mutations must be atomic so concurrent
        // workers cannot migrate the same delayed job more than once.
        $this->redis->eval(
            "local jobs = redis.call('ZRANGEBYSCORE', KEYS[1], 0, ARGV[1]) " .
            "for _, job in ipairs(jobs) do " .
            "redis.call('RPUSH', KEYS[2], job) " .
            "redis.call('ZREM', KEYS[1], job) " .
            "end " .
            "return #jobs",
            2,
            $delayedKey,
            $key,
            $now
        );
    }

    /**
     * Move reservations back to the active queue after their timeout expires.
     */
    protected function recoverTimedOutJobs(string $queue): void
    {
        $reservedKey = $this->getReservedQueueKey($queue);
        $activeKey = $this->getQueueKey($queue);
        $jobs = $this->redis->lrange($reservedKey, 0, -1);

        foreach ($jobs as $serialized) {
            $jobData = json_decode($serialized, true);
            $jobId = is_array($jobData) ? ($jobData['id'] ?? '') : '';

            if (!$jobId) {
                $this->redis->lrem($reservedKey, 1, $serialized);
                $this->moveToFailed($queue, $serialized, 'Invalid reserved job');
                continue;
            }

            $timeoutKey = $this->getTimeoutKey($jobId);
            if (!$this->redis->exists($timeoutKey)) {
                $removed = $this->redis->lrem($reservedKey, 1, $serialized);
                if ($removed > 0) {
                    $this->redis->rpush($activeKey, $serialized);
                }
            }
        }
    }

    /**
     * Remove all reserved entries for a job ID, rather than relying on a
     * regenerated serialized payload being byte-for-byte identical.
     */
    protected function removeReservedJob(string $queue, string $jobId): bool
    {
        $reservedKey = $this->getReservedQueueKey($queue);
        $removed = false;

        foreach ($this->redis->lrange($reservedKey, 0, -1) as $serialized) {
            $jobData = json_decode($serialized, true);
            if (is_array($jobData) && ($jobData['id'] ?? '') === $jobId) {
                $removed = $this->redis->lrem($reservedKey, 1, $serialized) > 0 || $removed;
            }
        }

        return $removed;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        $removed = $this->removeReservedJob($queue, $job->getId());
        if (!$removed) {
            return false;
        }
        
        // Clear timeout
        $timeoutKey = $this->getTimeoutKey($job->getId());
        $this->redis->del($timeoutKey);
        
        // Push back to queue
        return (bool) $this->push($job, $queue, $delay);
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        $removed = $this->removeReservedJob($queue, $job->getId());
        
        // Clear timeout and metadata
        $timeoutKey = $this->getTimeoutKey($job->getId());
        $this->redis->del($timeoutKey);
        
        $metaKey = $this->getJobMetaKey($job->getId());
        $this->redis->del($metaKey);
        
        return $removed;
    }

    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int
    {
        $key = $this->getQueueKey($queue);
        return $this->redis->llen($key);
    }

    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int
    {
        $count = 0;
        
        // Clear main queue
        $key = $this->getQueueKey($queue);
        $count += $this->redis->del($key);
        
        // Clear delayed queue
        $delayedKey = $this->getDelayedQueueKey($queue);
        $count += $this->redis->del($delayedKey);
        
        // Clear reserved queue
        $reservedKey = $this->getReservedQueueKey($queue);
        $count += $this->redis->del($reservedKey);
        
        return $count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        $pattern = $this->prefix . '*:queue';
        $keys = $this->redis->keys($pattern);
        
        $queues = [];
        foreach ($keys as $key) {
            if (str_starts_with($key, $this->prefix) && str_ends_with($key, ':queue')) {
                $queue = substr($key, strlen($this->prefix), -strlen(':queue'));
                if ($queue !== '') {
                    $queues[] = $queue;
                }
            }
        }
        
        return array_unique($queues);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        try {
            return $this->redis->ping();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Helper methods for Redis keys
     */
    protected function getQueueKey(string $queue): string
    {
        return $this->prefix . $queue . ':queue';
    }
    
    protected function getDelayedQueueKey(string $queue): string
    {
        return $this->prefix . $queue . ':delayed';
    }
    
    protected function getReservedQueueKey(string $queue): string
    {
        return $this->prefix . $queue . ':reserved';
    }
    
    protected function getJobMetaKey(string $jobId): string
    {
        return $this->prefix . 'job:' . $jobId . ':meta';
    }
    
    protected function getTimeoutKey(string $jobId): string
    {
        return $this->prefix . 'job:' . $jobId . ':timeout';
    }
    
    protected function getFailedKey(string $queue): string
    {
        return $this->prefix . $queue . ':failed';
    }
    
    protected function getProcessedKey(string $queue): string
    {
        return $this->prefix . $queue . ':processed';
    }

    /**
     * Move job to failed queue
     */
    protected function moveToFailed(string $queue, string $serialized, string $error): void
    {
        $failedKey = $this->getFailedKey($queue);
        $failedData = [
            'job' => $serialized,
            'error' => $error,
            'failed_at' => time(),
            'queue' => $queue,
        ];
        
        $this->redis->rpush($failedKey, json_encode($failedData));
    }

    /**
     * Move job to processed queue
     */
    protected function moveToProcessed(string $queue, string $serialized, array $payload = []): void
    {
        $processedKey = $this->getProcessedKey($queue);
        $processedData = [
            'job' => $serialized,
            'result_payload' => $payload,
            'processed_at' => time(),
            'queue' => $queue,
        ];
        
        $this->redis->rpush($processedKey, json_encode($processedData));
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        $failedKey = $this->getFailedKey($queue);
        $jobs = $this->redis->lrange($failedKey, $offset, $offset + $limit - 1);
        
        $result = [];
        foreach ($jobs as $job) {
            $data = json_decode($job, true);
            if ($data) {
                $result[] = $data;
            }
        }
        
        return $result;
    }

    /**
     * Get processed (completed) jobs
     */
    public function getProcessed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        $processedKey = $this->getProcessedKey($queue);
        $jobs = $this->redis->lrange($processedKey, $offset, $offset + $limit - 1);
        
        $result = [];
        foreach ($jobs as $job) {
            $data = json_decode($job, true);
            if ($data) {
                $result[] = $data;
            }
        }
        
        return $result;
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string $jobId, string $queue = 'default'): bool
    {
        $failedKey = $this->getFailedKey($queue);
        $jobs = $this->redis->lrange($failedKey, 0, -1);
        
        foreach ($jobs as $index => $job) {
            $data = json_decode($job, true);
            
            if (!$data) {
                continue;
            }
            
            $jobData = json_decode($data['job'], true);
            
            if (!$jobData || ($jobData['id'] ?? '') !== $jobId) {
                continue;
            }
            
            // Remove from failed
            $this->redis->lrem($failedKey, 1, $job);
            
            // Push back to queue
            $jobClass = $jobData['name'] ?? '';
            if (class_exists($jobClass)) {
                $jobInstance = $jobClass::unserialize($jobData, $this->app);
                $jobInstance->addMetadata('retried_at', date('Y-m-d H:i:s'));
                $this->push($jobInstance, $queue, 0);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get queue statistics
     */
    public function getStats(string $queue = 'default'): array
    {
        $stats = [];
        
        // Active queue size
        $stats['pending'] = $this->size($queue);
        
        // Delayed queue size
        $delayedKey = $this->getDelayedQueueKey($queue);
        $stats['delayed'] = $this->redis->zcard($delayedKey);
        
        // Reserved queue size
        $reservedKey = $this->getReservedQueueKey($queue);
        $stats['reserved'] = $this->redis->llen($reservedKey);
        
        // Failed queue size
        $failedKey = $this->getFailedKey($queue);
        $stats['failed'] = $this->redis->llen($failedKey);
        
        // Processed queue size
        $processedKey = $this->getProcessedKey($queue);
        $stats['processed'] = $this->redis->llen($processedKey);
        
        return $stats;
    }
    
    /**
     * Close Redis connection
     */
    public function __destruct()
    {
        if (isset($this->redis)) {
            $this->redis->disconnect();
        }
    }

    /**
     * Mark a job as failed, moving it from reserved to failed.
     */
    public function markAsFailed(string $jobId, string $error): void
    {
        // Retrieve queue name from job metadata
        $metaKey = $this->getJobMetaKey($jobId);
        $meta = $this->redis->hgetall($metaKey);
        
        if (empty($meta) || !isset($meta['queue'])) {
            $this->events->trigger('queue.warning', [
                'message' => 'Cannot mark job as failed: metadata not found',
                'job_id'  => $jobId,
            ]);
            return;
        }
        
        $queue = $meta['queue'];
        $reservedKey = $this->getReservedQueueKey($queue);
        
        // Fetch all reserved jobs for this queue
        $reservedJobs = $this->redis->lrange($reservedKey, 0, -1);
        
        foreach ($reservedJobs as $serialized) {
            $jobData = json_decode($serialized, true);
            if ($jobData && isset($jobData['id']) && $jobData['id'] === $jobId) {
                // Remove from reserved
                $this->redis->lrem($reservedKey, 1, $serialized);
                
                // Move to failed queue
                $this->moveToFailed($queue, $serialized, $error);
                
                // Clean up timeout and metadata
                $timeoutKey = $this->getTimeoutKey($jobId);
                $this->redis->del($timeoutKey);
                $this->redis->del($metaKey);
                
                // Notify listeners
                $this->events->trigger('queue.marked_failed', [
                    'job_id' => $jobId,
                    'queue'  => $queue,
                    'error'  => $error,
                ]);
                
                return;
            }
        }
        
        // Job not found in reserved queue – log a warning
        $this->events->trigger('queue.warning', [
            'message' => 'Job not found in reserved queue for marking as failed',
            'job_id'  => $jobId,
            'queue'   => $queue,
        ]);
    }

    /**
     * Mark a job as completed, moving it from reserved to processed.
     *
     * @param string $jobId
     * @param array $payload Optional result payload to store with the completed job.
     */
    public function markAsCompleted(string $jobId, array $payload = []): void
    {
        // Retrieve queue name from job metadata
        $metaKey = $this->getJobMetaKey($jobId);
        $meta = $this->redis->hgetall($metaKey);
        
        if (empty($meta) || !isset($meta['queue'])) {
            $this->events->trigger('queue.warning', [
                'message' => 'Cannot mark job as completed: metadata not found',
                'job_id'  => $jobId,
            ]);
            return;
        }
        
        $queue = $meta['queue'];
        $reservedKey = $this->getReservedQueueKey($queue);
        
        // Fetch all reserved jobs for this queue
        $reservedJobs = $this->redis->lrange($reservedKey, 0, -1);
        
        foreach ($reservedJobs as $serialized) {
            $jobData = json_decode($serialized, true);
            if ($jobData && isset($jobData['id']) && $jobData['id'] === $jobId) {
                // Remove from reserved
                $this->redis->lrem($reservedKey, 1, $serialized);
                
                // Move to processed queue
                $this->moveToProcessed($queue, $serialized, $payload);
                
                // Clean up timeout and metadata
                $timeoutKey = $this->getTimeoutKey($jobId);
                $this->redis->del($timeoutKey);
                $this->redis->del($metaKey);
                
                // Notify listeners
                $this->events->trigger('queue.marked_completed', [
                    'job_id' => $jobId,
                    'queue'  => $queue,
                    'payload' => $payload,
                ]);
                
                return;
            }
        }
        
        // Job not found in reserved queue – log a warning
        $this->events->trigger('queue.warning', [
            'message' => 'Job not found in reserved queue for marking as completed',
            'job_id'  => $jobId,
            'queue'   => $queue,
        ]);
    }
}