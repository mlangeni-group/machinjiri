<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseQueue, JobInterface};
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\DriverException;

/**
 * Sync Queue Driver
 *
 * Synchronous queue driver for immediate job processing (mainly for testing).
 */
class SyncQueue extends BaseQueue
{
    protected array $jobs = [];
    protected array $processing = [];
    protected array $failed = [];
    protected array $processed = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        Container $app,
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
        // For sync queue, process immediately if no delay
        if ($delay === 0) {
            $this->processImmediately($job, $queue);
            return $job->getId();
        }
        
        // Store for delayed processing (simulate with sleep in real usage)
        if (!isset($this->jobs[$queue])) {
            $this->jobs[$queue] = [];
        }
        
        $this->jobs[$queue][] = [
            'job' => $job,
            'available_at' => time() + $delay,
        ];
        
        $this->events->trigger('queue.job.pushed', [
            'job_id' => $job->getId(),
            'queue' => $queue,
            'job_name' => $job->getName(),
        ]);
        
        return $job->getId();
    }

    /**
     * Process job immediately
     */
    protected function processImmediately(JobInterface $job, string $queue): void
    {
        $this->events->trigger('job.processing', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'queue' => $queue,
        ]);
        
        // Mark as processing
        $this->processing[$job->getId()] = [
            'job' => $job,
            'queue' => $queue,
            'started_at' => time(),
        ];
        
        try {
            $job->handle();
            
            // Move to processed on success
            $this->markAsCompleted($job->getId(), ['status' => 'success']);
            
            $this->events->trigger('job.processed', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'queue' => $queue,
            ]);
        } catch (\Throwable $e) {
            $job->failed(new DriverException($e->getMessage()));
            
            // Move to failed
            $this->markAsFailed($job->getId(), $e->getMessage());
            
            $this->events->trigger('job.failed', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'queue' => $queue,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        if (empty($this->jobs[$queue])) {
            return null;
        }
        
        $now = time();
        foreach ($this->jobs[$queue] as $index => $jobData) {
            if ($jobData['available_at'] <= $now) {
                $job = $jobData['job'];
                unset($this->jobs[$queue][$index]);
                
                // Mark as processing
                $this->processing[$job->getId()] = [
                    'job' => $job,
                    'queue' => $queue,
                    'started_at' => $now,
                ];
                
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
        unset($this->processing[$job->getId()]);
        
        // Add back to queue with delay
        if (!isset($this->jobs[$queue])) {
            $this->jobs[$queue] = [];
        }
        
        $this->jobs[$queue][] = [
            'job' => $job,
            'available_at' => time() + $delay,
        ];
        
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        // Remove from processing
        unset($this->processing[$job->getId()]);
        
        // Remove from jobs array
        if (isset($this->jobs[$queue])) {
            foreach ($this->jobs[$queue] as $index => $jobData) {
                if ($jobData['job']->getId() === $job->getId()) {
                    unset($this->jobs[$queue][$index]);
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
        return isset($this->jobs[$queue]) ? count($this->jobs[$queue]) : 0;
    }

    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int
    {
        $count = isset($this->jobs[$queue]) ? count($this->jobs[$queue]) : 0;
        unset($this->jobs[$queue]);
        return $count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        return array_keys($this->jobs);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        // Sync queue is always healthy
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
     * Mark a job as completed
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
        if (isset($this->jobs[$queue])) {
            $now = time();
            foreach ($this->jobs[$queue] as $jobData) {
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