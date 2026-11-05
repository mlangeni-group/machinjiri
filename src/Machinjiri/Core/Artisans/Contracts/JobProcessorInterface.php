<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;

/**
 * Job Processor Interface
 */
interface JobProcessorInterface
{
    /**
     * Process a job
     */
    public function process(JobInterface $job): mixed;
    
    /**
     * Handle job failure
     */
    public function handleFailure(JobInterface $job, JobException $exception): void;
    
    /**
     * Handle job success
     */
    public function handleSuccess(JobInterface $job, mixed $result): void;
    
    /**
     * Mark job as completed
     */
    public function markAsCompleted(JobInterface $job): void;
    
    /**
     * Mark job as failed
     */
    public function markAsFailed(JobInterface $job, JobException $exception): void;
    
    /**
     * Retry a failed job
     */
    public function retry(JobInterface $job, int $delay = 0): bool;
}