<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;

/**
 * Job Interface
 * 
 * All jobs must implement this interface
 */
interface JobInterface
{
    /**
     * Get the job ID
     */
    public function getId(): string;
    
    /**
     * Get the job name/class
     */
    public function getName(): string;
    
    /**
     * Get the job payload
     */
    public function getPayload(): array;
    
    /**
     * Get the number of attempts
     */
    public function getAttempts(): int;
    
    /**
     * Increment the number of attempts
     */
    public function incrementAttempts(): void;
    
    /**
     * Get the maximum number of attempts
     */
    public function getMaxAttempts(): int;
    
    /**
     * Get the queue name
     */
    public function getQueue(): string;
    
    /**
     * Get the job delay in seconds
     */
    public function getDelay(): int;
    
    /**
     * Get the job timeout in seconds
     */
    public function getTimeout(): int;
    
    /**
     * Get the job retry delay in seconds
     */
    public function getRetryDelay(): int;
    
    /**
     * Handle the job
     */
    public function handle(): void;
    
    /**
     * Called when the job fails
     */
    public function failed(JobException $exception): void;

    /**
     * Serialize the job for storage
     */
    public function serialize(): array;
    
    /**
     * Create a job from serialized data
     * @param array $data The serialized job data
     * @param Container $app The application container
     */
    public static function unserialize(array $data, ?Container $app = null): self;

    public function getNextRetryDelay(): int;
    
}