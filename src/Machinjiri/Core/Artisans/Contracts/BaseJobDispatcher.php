<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\QueueException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

/**
 * Job Dispatcher
 */
class BaseJobDispatcher implements JobDispatcherInterface
{
    protected Container $app;
    protected QueueInterface $queue;
    protected string $defaultQueue = 'default';
    protected ?BackgroundWorkerManager $workerManager = null;
    protected EventListener $eventListener;
    
    /**
     * Create a new job dispatcher
     */
    public function __construct(Container $app, QueueInterface $queue)
    {
        $this->app = $app;
        $this->queue = $queue;
        $this->eventListener = new EventListener(LoggerFactory::system('queue-dispatcher', 'queue', true));
    }
    
    /**
     * Dispatch a job to the queue
     */
    public function dispatch(JobInterface $job): string
    {
        return $this->dispatchToQueue($job, $this->getDefaultQueue());
    }
    
    /**
     * Dispatch a job to a specific queue
     */
    public function dispatchToQueue(JobInterface $job, string $queue): string
    {
        // Validate queue name before using it
        if (!$this->isValidQueueName($queue)) {
            throw new QueueException("Invalid queue name: {$queue}", 60020);
        }
        
        $jobId = $this->queue->push($job, $queue, $job->getDelay());
        
        $this->eventListener->trigger('job.dispatched - {queue}'. $queue, [
            'job_id' => $jobId,
            'job_name' => $job->getName(),
            'queue' => $queue,
            'delay' => $job->getDelay(),
        ]);
        
        return $jobId;
    }
    
    private function isValidQueueName(string $queue): bool
    {
        return preg_match('/^[a-zA-Z0-9_\-]+$/', $queue) === 1;
    }
    
    /**
     * Dispatch a job with a delay
     */
    public function dispatchWithDelay(JobInterface $job, int $delay): string
    {
        $job->addMetadata('delay', $delay);
        return $this->queue->push($job, $job->getQueue(), $delay);
    }
    
    /**
     * Dispatch a job immediately (sync)
     */
    public function dispatchNow(JobInterface $job): mixed
    {
        if (!$job instanceof JobInterface) {
            throw new JobException('Invalid job instance', 60011);
        }
        
        $processor = $this->app->resolve('queue.processor');
        if (!$processor) {
            throw new JobException('Job processor not configured', 60012);
        }
        
        $this->eventListener->trigger('job.processing', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'queue' => 'sync',
        ]);
        
        try {
            $result = $processor->process($job);
            $processor->handleSuccess($job, $result);
            return $result;
        } catch (JobException $e) {
            $processor->handleFailure($job, $e);
            throw $e;
        } catch (\Throwable $e) {
            $exception = new JobException('Sync job execution failed: ' . $e->getMessage(), 60013, $e);
            $processor->handleFailure($job, $exception);
            throw $exception;
        }
    }
    
    /**
     * Dispatch multiple jobs
     */
    public function dispatchBulk(array $jobs): array
    {
        if (empty($jobs)) {
            throw new JobException('Cannot dispatch empty jobs array', 60014);
        }
        
        $jobIds = [];
        
        foreach ($jobs as $job) {
            if (!($job instanceof JobInterface)) {
                throw new JobException('All items must implement JobInterface', 60015);
            }
            $jobIds[] = $this->dispatch($job);
        }
        
        $this->eventListener->trigger('job.bulk_dispatched', [
            'count' => count($jobIds),
            'queue' => $this->getDefaultQueue(),
        ]);
        
        return $jobIds;
    }
    
    /**
     * Get the default queue name
     */
    public function getDefaultQueue(): string
    {
        return $this->defaultQueue;
    }
    
    /**
     * Set the default queue name
     */
    public function setDefaultQueue(string $queue): void
    {
        $this->defaultQueue = $queue;
    }
}