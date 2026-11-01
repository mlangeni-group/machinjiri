<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\ProcessorException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\QueueInterface;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{LoggerFactory, Logger};
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;

/**
 * Abstract Job Processor
 */
abstract class BaseJobProcessor implements JobProcessorInterface
{
    protected Container $app;
    protected EventListener $events;
    protected Logger $logger;
    
    protected array $eventBuffer = [];
    protected int $maxEventBufferSize = 100;
    protected bool $buffering = false;
    
    /**
     * Create a new job processor
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->events = new EventListener(LoggerFactory::system('queue-processor', 'queue', true));
        $this->logger = LoggerFactory::system('queue-processor', 'queue');
    }
    
    /**
     * Process a job
     */
    public function process(JobInterface $job): mixed
    {
        $job->incrementAttempts();
        
        $this->triggerEvent('job.processing', [
            'job_id'   => $job->getId(),
            'job_name' => $job->getName(),
            'attempt'  => $job->getAttempts(),
        ]);
        
        $startTime = microtime(true);
        
        try {
            if ($job->getTimeout() > 0) {
                set_time_limit($job->getTimeout());
            }
            
            // Capture return value
            $result = $job->handle();
            
            $executionTime = microtime(true) - $startTime;
            $this->triggerEvent('job.handled', [
                'job_id'         => $job->getId(),
                'job_name'       => $job->getName(),
                'execution_time' => $executionTime,
            ]);
            return $result;
        } catch (\Throwable $e) {
            throw new JobException("Job {$job->getName()} failed: {$e->getMessage()}", 60001, $e);
        }
    }
    
    /**
     * Handle job failure
     */
    public function handleFailure(JobInterface $job, JobException $exception): void
    {   
        $this->events->trigger('job.failed', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'exception' => $exception->getMessage(),
            'attempts' => $job->getAttempts(),
        ]);

        if ($job->getAttempts() > $job->getMaxAttempts()) {
            // Mark as permanently failed
            $this->markAsFailed($job, $exception);
        } else {
            // Retry the job with proper delay
            $this->retry($job);
        }
        
    }
    
    /**
     * Handle job success
     */
    public function handleSuccess(JobInterface $job, mixed $result): void
    {
        $this->markAsCompleted($job);
        
        $this->events->trigger('job.completed', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'attempts' => $job->getAttempts(),
            'result' => $result,
        ]);

        $this->logger->info("Job {$job->getId()} completed successfully after {$job->getAttempts()} attempts.");
    }
    
    /**
     * Retry a failed job
     */
    public function retry(JobInterface $job, int $delay = 0): bool
    {
        // Calculate delay: use provided delay, or job's retry delay, or default
        $actualDelay = $delay > 0 ? $delay : $job->getRetryDelay();

        $this->events->trigger('job.retrying', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'delay' => $actualDelay,
            'next_attempt' => $job->getAttempts() + 1,
        ]);
        
        try {
            $queue = $this->getQueue();
            if ($queue) {
                // Delete the current job from the queue
                $queue->delete($job, $job->getQueue());
                
                // Push it back with delay
                $queue->push($job, $job->getQueue(), $actualDelay);
                
                $this->logger->info(sprintf(
                    'Job %s scheduled for retry (attempt %d/%d) with %d second delay',
                    $job->getId(),
                    $job->getAttempts(),
                    $job->getMaxAttempts(),
                    $actualDelay
                ));
                
                return true;
            }

        } catch (\Throwable $exception) {
            $this->events->trigger('job.retry_failed', [
                'job_id' => $job->getId(),
                'exception' => $exception->getMessage(),
            ]);
            $this->markAsFailed($job, $exception);
            $this->logger->error("Failed to retry job {$job->getId()}: {$exception->getMessage()}");
            return false;
        }

        
    }
    
    protected function triggerBuffered(string $event, array $data): void
    {
        $this->eventBuffer[] = ['event' => $event, 'data' => $data];
        
        if (count($this->eventBuffer) >= $this->maxEventBufferSize) {
            $this->flushEvents();
        }
    }
    
    protected function flushEvents(): void
    {
        if (empty($this->eventBuffer)) {
            return;
        }
        
        // Batch trigger events
        foreach ($this->eventBuffer as $eventData) {
            $this->events->trigger($eventData['event'], $eventData['data']);
        }
        
        $this->eventBuffer = [];
    }

    public function markAsCompleted(JobInterface $job): void
    {
        $this->events->trigger('job.marked_completed', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
        ]);

        if (!method_exists($this->getQueue(), 'markAsCompleted')) {
            throw new ProcessorException("Method markAsCompleted not found in default Queue Driver");
        }

        $this->getQueue()->markAsCompleted($job->getId(), $job->getPayload());
        
        $this->logger->info("Job {$job->getId()} marked as completed.");
    }
    
    /**
     * Mark job as failed
     */
    public function markAsFailed(JobInterface $job, JobException $exception): void
    {
        $this->events->trigger('job.marked_failed', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'exception' => $exception->getMessage(),
        ]);

        if (!method_exists($this->getQueue(), 'markAsFailed')) {
            throw new ProcessorException("Method markAsFailed not found in default Queue Driver");
        }

        $this->getQueue()->markAsFailed($job->getId(), $exception->getMessage());

        if (!method_exists($job, 'failed')) {
            throw new ProcessorException("Method [failed] not found in default Queue Driver");
        }

        $job->failed($exception);

        $this->logger->error("Job {$job->getId()} marked as failed: {$exception->getMessage()}");
    }
    
    private function triggerEvent(string $event, array $data): void
    {
        // If within a batch, buffer; otherwise dispatch immediately
        if ($this->buffering) {
            $this->eventBuffer[] = ['event' => $event, 'data' => $data];
            if (count($this->eventBuffer) >= $this->maxEventBufferSize) {
                $this->flushEvents();
            }
        } else {
            $this->events->trigger($event, $data);
        }
    }

    private function getQueue(): ?QueueInterface
    {
        if (!$this->app->bound('queue')) {
            throw new ProcessorException("Queue service is not bound in the container.", 60002);
        }
        return $this->app->resolve('queue');
    }
    
}