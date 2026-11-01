<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\ProcessorException;

/**
 * Worker – supports heartbeat callbacks for supervisor monitoring.
 */
class BaseWorker implements WorkerInterface
{
    protected Container $app;
    protected QueueInterface $queue;
    protected JobProcessorInterface $processor;
    protected EventListener $events;
    protected bool $shouldStop = false;
    protected bool $paused = false;
    protected array $options = [
        'sleep' => 3,
        'maxTries' => 3,
        'memory' => 128,
        'timeout' => 60,
        'maxJobs' => null,
        'stopOnEmpty' => false,
    ];
    protected array $status = [
        'started_at' => null,
        'processed' => 0,
        'failed' => 0,
        'memory_peak' => 0,
        'last_job_at' => null,
    ];
    
    protected array $processedJobs = [];
    protected int $maxProcessedJobs = 100;

    protected array $timeoutTracking = [];
    
    // Heartbeat support for supervisor
    protected $heartbeatCallback = null;
    protected int $heartbeatInterval = 60;
    protected int $lastHeartbeatTime = 0;

    /**
     * Create a new worker instance
     */
    public function __construct(Container $app, QueueInterface $queue, JobProcessorInterface $processor)
    {
        $this->app = $app;
        $this->queue = $queue;
        $this->processor = $processor;
        $this->events = new EventListener(LoggerFactory::system('queue-worker', 'queue', true));
    }
    
    /**
     * Set a callback to be called periodically (heartbeat) for supervisor monitoring.
     *
     * @param callable $callback Function that will be called (e.g., update heartbeat file)
     * @param int $intervalSeconds How often to call the callback (default 60)
     */
    public function setHeartbeatCallback(callable $callback, int $intervalSeconds = 60): void
    {
        $this->heartbeatCallback = $callback;
        $this->heartbeatInterval = $intervalSeconds;
    }
    
    /**
     * Call the heartbeat callback if enough time has passed.
     */
    protected function maybeSendHeartbeat(): void
    {
        if (!$this->heartbeatCallback) {
            return;
        }
        
        $now = time();
        if ($now - $this->lastHeartbeatTime >= $this->heartbeatInterval) {
            call_user_func($this->heartbeatCallback);
            $this->lastHeartbeatTime = $now;
        }
    }
    
    /**
     * Start the worker
     */
    public function start(string $queue, array $options = []): void
    {
        $this->shouldStop = false;
        $this->paused = false;
        $this->options = array_merge($this->options, $options);
        $this->status['started_at'] = time();
        $this->status['processed'] = 0;
        $this->status['failed'] = 0;
        $this->status['memory_peak'] = memory_get_usage();
        
        // Send initial heartbeat immediately
        $this->maybeSendHeartbeat();
        
        $this->events->trigger('worker.started', [
            'queue' => $queue,
            'options' => $this->options,
        ]);
         
        $this->run($queue, $this->options['maxJobs']);
    }
    
    /**
     * Stop the worker
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        
        $this->events->trigger('worker.stopped', [
            'status' => $this->status,
        ]);
    }
    
    /**
     * Pause the worker
     */
    public function pause(): void
    {
        $this->paused = true;
        
        $this->events->trigger('worker.paused');
    }
    
    /**
     * Resume the worker
     */
    public function resume(): void
    {
        $this->paused = false;
        
        $this->events->trigger('worker.resumed');
    }
    
    /**
     * Check if worker is running
     */
    public function isRunning(): bool
    {
        return !$this->shouldStop;
    }
    
    /**
     * Get worker status
     */
    public function getStatus(): array
    {
        $this->status['memory_current'] = memory_get_usage();
        $this->status['memory_peak'] = max($this->status['memory_peak'], memory_get_peak_usage());
        $this->status['uptime'] = $this->status['started_at'] ? time() - $this->status['started_at'] : 0;
        
        return $this->status;
    }
     
    /**
     * Process a single job
     */
    public function processNextJob(string $queue): bool
    {
        if ($this->paused) return false;

        $job = $this->queue->pop($queue);

        if (!$job) return false;

        try {
            // Check if max attempts exceeded (after increment)
            if ($job->getAttempts() == $job->getMaxAttempts()) {
                $this->processor->markAsFailed(
                    $job, 
                    new JobException("Job {$job->getId()} has reached max attempts of {$job->getMaxAttempts()}.")
                );
                $this->status['failed']++;
                return false;
            }

            
            $result = $this->processor->process($job);

            $this->status['processed']++;
            $this->status['last_job_at'] = time();
            $this->processor->handleSuccess($job, $result);

            return true;
        } catch (\Throwable $e) {
            $this->status['failed']++;
            $this->processor->handleFailure($job, new JobException(
                $e->getMessage(),
                $e->getCode(),
                $e
            ));
            return false;
        }
    }
    
    /**
     * Process jobs until empty or stopped
     */
    public function run(string $queue, ?int $maxJobs = null): int
    {
        $processed = 0;
        $emptyCycles = 0;
        
        while (!$this->shouldStop) {
            // Memory optimization
            if ($processed % 10 === 0) {
                $this->cleanupProcessedJobs();
                gc_collect_cycles(); // Force garbage collection
            }
            
            // Adaptive sleep logic
            if ($emptyCycles > 0) {
                $sleepTime = $this->calculateAdaptiveSleep($emptyCycles);
                if ($sleepTime > 0) {
                    @sleep($sleepTime);
                }
            }
            
            $processedJob = $this->processNextJob($queue);
            
            if ($processedJob) {
                $processed++;
                $emptyCycles = 0; // Reset empty cycles
                
                // Send heartbeat after each successful job (or periodically)
                $this->maybeSendHeartbeat();
                
                if ($maxJobs && $processed >= $maxJobs) {
                    break;
                }
            } else {
                $emptyCycles++;
                
                // Even when idle, send heartbeat occasionally
                $this->maybeSendHeartbeat();
                
                if ($this->options['stopOnEmpty']) {
                    break;
                }
            }
            
            // Check memory limit
            if ($this->memoryExceeded()) {
                $this->events->trigger('worker.memory_exceeded', [
                    'memory' => memory_get_usage(),
                    'limit' => $this->options['memory'] * 1024 * 1024,
                ]);
                break;
            }
        }
        
        return $processed;
    }
    
    /**
     * Check if memory limit is exceeded
     */
    protected function memoryExceeded(): bool
    {
        return memory_get_usage() > $this->options['memory'] * 1024 * 1024;
    }
    
    protected function cleanupProcessedJobs(): void
    {
        if (count($this->processedJobs) > $this->maxProcessedJobs) {
            array_shift($this->processedJobs);
        }
    }
    
    protected function calculateAdaptiveSleep(int $emptyCycles): int
    {
        if ($emptyCycles < 3) return 0; // No sleep for recent jobs
        if ($emptyCycles < 10) return 1; // 1 second sleep
        if ($emptyCycles < 30) return 3; // 3 seconds sleep
        return 5; // Maximum 5 seconds for idle queues
    }
    
    // Add batch processing capability
    public function processBatch(string $queue = 'default', int $batchSize = 10): int
    {
        $jobs = $this->queue->popBatch($queue, $batchSize);
        $processed = 0;
        
        if (empty($jobs)) {
            return 0;
        }
        
        // Sort by priority if available
        usort($jobs, function($a, $b) {
            $priorityA = $a->getMetadata()['priority'] ?? 0;
            $priorityB = $b->getMetadata()['priority'] ?? 0;
            return $priorityB <=> $priorityA; // Higher priority first
        });
        
        foreach ($jobs as $job) {
            if ($this->processJob($job, $queue)) {
                $processed++;
            }
            $this->maybeSendHeartbeat(); // heartbeat during batch
        }
        
        return $processed;
    }
    
    protected function processJob(JobInterface $job, string $queue): bool
    {
        // Simplified job processing logic
        try {
            $result = $this->processor->process($job);
            $this->processor->handleSuccess($job, $result);
            $this->status['processed']++;
            $this->status['last_job_at'] = time();
            return true;
        } catch (\Throwable $e) {

            if ($e instanceof ProcessorException) {
                $this->processor->handleFailure($job, $e);
            } else {
                $this->processor->handleFailure($job, new ProcessorException(
                    $e->getMessage(),
                    60002,
                    $e
                ));
            }
            $this->status['failed']++;
            return false;
        }

    }

    protected function processWithTimeout(JobInterface $job, callable $callback): mixed
    {
        $jobId = $job->getId();
        $timeout = $job->getTimeout();
        
        if ($timeout > 0 && extension_loaded('pcntl')) {
            // Fork process for timeout handling
            return $this->processWithPcntl($job, $callback, $timeout);
        }
        
        // Fallback: set_time_limit
        set_time_limit($timeout > 0 ? $timeout : 0);
        return $callback($job);
    }
    
    private function processWithPcntl(JobInterface $job, callable $callback, int $timeout): mixed
    {
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            // Fork failed, use normal processing
            return $callback($job);
        } elseif ($pid) {
            // Parent process
            pcntl_waitpid($pid, $status, WNOHANG);
            sleep($timeout);
            
            if (pcntl_wifexited($status)) {
                // Child exited normally
                return pcntl_wexitstatus($status);
            } else {
                // Timeout - kill child
                posix_kill($pid, SIGKILL);
                throw new ProcessorException("Job timed out after {$timeout} seconds");
            }
        } else {
            // Child process
            $result = $callback($job);
            exit($result);
        }
    }
    
}