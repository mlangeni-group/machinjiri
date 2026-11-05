<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;

/**
 * Queue Interface
 * 
 * All queue drivers must implement this interface
 */
interface QueueInterface
{
    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string;
    
    /**
     * Push multiple jobs onto the queue
     */
    public function bulk(array $jobs, string $queue = 'default', int $delay = 0): array;
    
    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue): ?JobInterface;
    
    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool;
    
    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool;
    
    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int;
    
    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int;
    
    /**
     * Get all available queues
     */
    public function getQueues(): array;
    
    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool;

    /**
     * Get queue health metrics
     */
    public function getHealthMetrics(string $queue = 'default'): array;
}