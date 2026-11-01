<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

/**
 * Worker Interface
 */
interface WorkerInterface
{
    /**
     * Start the worker
     */
    public function start(string $queue, array $options = []): void;
    
    /**
     * Stop the worker
     */
    public function stop(): void;
    
    /**
     * Pause the worker
     */
    public function pause(): void;
    
    /**
     * Resume the worker
     */
    public function resume(): void;
    
    /**
     * Check if worker is running
     */
    public function isRunning(): bool;
    
    /**
     * Get worker status
     */
    public function getStatus(): array;
    
    /**
     * Process a single job
     */
    public function processNextJob(string $queue): bool;
    
    /**
     * Process jobs until empty or stopped
     */
    public function run(string $queue, ?int $maxJobs = null): int;
}