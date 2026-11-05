<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseQueue, JobInterface};

/**
 * Memory Queue Driver
 *
 * In-memory queue driver for testing and development.
 */
class MemoryQueue extends BaseQueue
{
    protected array $queues = [];
    protected array $processing = [];
    protected array $failed = [];
    protected array $processed = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        \Mlangeni\Machinjiri\Core\Container $app,
        string $name,
        array $config = []
    ) {
        parent::__construct($app, $name, $config);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        if (!isset($this->queues[$queue])) {
            $this->queues[$queue] = [];
        }
        
        $jobData = [
            'job' => $job,
            'available_at' => time() + $delay,
            'created_at' => time(),
        ];
        
        $this->queues[$queue][] = $jobData;
        
        // Sort by available time
        usort($this->queues[$queue], function($a, $b) {
            return $a['available_at'] <=> $b['available_at'];
        });
        
        $this->events->trigger('queue.job.pushed', [
            'job_id' => $job->getId(),
            'queue' => $queue,
            'job_name' => $job->getName(),
        ]);
        
        return $job->getId();
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        if (empty($this->queues[$queue])) {
            return null;
        }
        
        $now = time();
        
        foreach ($this->queues[$queue] as $index => $jobData) {
            if ($jobData['available_at'] <= $now) {
                $job = $jobData['job'];
                
                // Move to processing
                $this->processing[$job->getId()] = [
                    'job' => $job,
                    'queue' => $queue,
                    'started_at' => $now,
                    'index' => $index,
                ];
                
                // Remove from queue
                unset($this->queues[$queue][$index]);
                $this->queues[$queue] = array_values($this->queues[$queue]);
                
                return $job;
            }
        }
        
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        // Remove from processing
        if (!isset($this->processing[$job->getId()])) {
            return false;
        }
        
        unset($this->processing[$job->getId()]);
        
        // Add back to queue
        if (!isset($this->queues[$queue])) {
            $this->queues[$queue] = [];
        }
        
        $this->queues[$queue][] = [
            'job' => $job,
            'available_at' => time() + $delay,
            'created_at' => time(),
        ];
        
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        // Check in processing
        if (isset($this->processing[$job->getId()])) {
            unset($this->processing[$job->getId()]);
            return true;
        }
        
        // Check in queue
        if (isset($this->queues[$queue])) {
            foreach ($this->queues[$queue] as $index => $jobData) {
                if ($jobData['job']->getId() === $job->getId()) {
                    unset($this->queues[$queue][$index]);
                    $this->queues[$queue] = array_values($this->queues[$queue]);
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int
    {
        if (!isset($this->queues[$queue])) {
            return 0;
        }
        
        $count = 0;
        $now = time();
        
        foreach ($this->queues[$queue] as $jobData) {
            if ($jobData['available_at'] <= $now) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int
    {
        $count = isset($this->queues[$queue]) ? count($this->queues[$queue]) : 0;
        unset($this->queues[$queue]);
        
        // Also clear processing jobs for this queue
        foreach ($this->processing as $jobId => $processing) {
            if ($processing['queue'] === $queue) {
                unset($this->processing[$jobId]);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        return array_keys($this->queues);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        // Memory queue is always healthy
        return true;
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        $failedInQueue = [];
        
        foreach ($this->failed as $jobId => $failedJob) {
            if ($failedJob['queue'] === $queue) {
                $failedInQueue[] = $failedJob;
            }
        }
        
        // Sort by failed time (newest first)
        usort($failedInQueue, function($a, $b) {
            return $b['failed_at'] <=> $a['failed_at'];
        });
        
        return array_slice($failedInQueue, $offset, $limit);
    }

    /**
     * Get processed (completed) jobs
     */
    public function getProcessed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        $processedInQueue = [];
        
        foreach ($this->processed as $jobId => $processedJob) {
            if ($processedJob['queue'] === $queue) {
                $processedInQueue[] = $processedJob;
            }
        }
        
        // Sort by processed time (newest first)
        usort($processedInQueue, function($a, $b) {
            return $b['processed_at'] <=> $a['processed_at'];
        });
        
        return array_slice($processedInQueue, $offset, $limit);
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string $jobId, string $queue = 'default'): bool
    {
        if (!isset($this->failed[$jobId])) {
            return false;
        }
        
        $failedJob = $this->failed[$jobId];
        $job = $failedJob['job'];
        
        $job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        
        // Push back to queue
        $this->push($job, $queue, 0);
        
        // Remove from failed
        unset($this->failed[$jobId]);
        
        return true;
    }

    /**
     * Mark a job as failed
     */
    public function markAsFailed(string $jobId, string $error): void
    {
        if (isset($this->processing[$jobId])) {
            $processing = $this->processing[$jobId];
            
            $this->failed[$jobId] = [
                'job' => $processing['job'],
                'queue' => $processing['queue'],
                'failed_at' => time(),
                'error' => $error,
            ];
            
            unset($this->processing[$jobId]);
            
            $this->events->trigger('queue.marked_failed', [
                'job_id' => $jobId,
                'queue' => $processing['queue'],
                'error' => $error,
            ]);
        }
    }

    /**
     * Mark a job as completed, moving it from processing to processed.
     */
    public function markAsCompleted(string $jobId, array $payload = []): void
    {
        if (isset($this->processing[$jobId])) {
            $processing = $this->processing[$jobId];
            
            $this->processed[$jobId] = [
                'job' => $processing['job'],
                'queue' => $processing['queue'],
                'processed_at' => time(),
                'result_payload' => $payload,
            ];
            
            unset($this->processing[$jobId]);
            
            $this->events->trigger('queue.marked_completed', [
                'job_id' => $jobId,
                'queue' => $processing['queue'],
                'payload' => $payload,
            ]);
        }
    }

    /**
     * Get queue statistics
     */
    public function getStats(string $queue = 'default'): array
    {
        $stats = [
            'queued' => $this->size($queue),
            'processing' => 0,
            'delayed' => 0,
            'failed' => 0,
            'processed' => 0,
        ];
        
        // Count processing jobs
        foreach ($this->processing as $processing) {
            if ($processing['queue'] === $queue) {
                $stats['processing']++;
            }
        }
        
        // Count delayed jobs
        if (isset($this->queues[$queue])) {
            $now = time();
            foreach ($this->queues[$queue] as $jobData) {
                if ($jobData['available_at'] > $now) {
                    $stats['delayed']++;
                }
            }
        }
        
        // Count failed jobs
        foreach ($this->failed as $failedJob) {
            if ($failedJob['queue'] === $queue) {
                $stats['failed']++;
            }
        }
        
        // Count processed jobs
        foreach ($this->processed as $processedJob) {
            if ($processedJob['queue'] === $queue) {
                $stats['processed']++;
            }
        }
        
        return $stats;
    }
}