<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseQueue, JobInterface};
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\DriverException;

/**
 * File Queue Driver
 *
 * File-based queue driver for simple job storage without database.
 */
class FileQueue extends BaseQueue
{
    protected string $storagePath;
    protected array $config = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        Container $app,
        string $name,
        array $config = []
    ) {
        parent::__construct($app, $name, $config);
        
        $this->config = array_merge([
            'storage_path' => $app->getStoragePath() . 'queue/',
            'retry_after' => 90,
        ], $config);
        
        $this->storagePath = rtrim($this->config['storage_path'], '/') . '/';
        $this->ensureStorageDirectory();
    }

    /**
     * Ensure storage directory exists
     */
    protected function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        
        // Create queue subdirectories
        foreach (['pending', 'processing', 'failed', 'processed'] as $subdir) {
            $path = $this->storagePath . $subdir . '/';
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        $filename = $this->generateFilename($job->getId(), $queue);
        $filepath = $this->storagePath . 'pending/' . $filename;
        
        $data = [
            'job' => $job->serialize(),
            'queue' => $queue,
            'created_at' => time(),
            'available_at' => time() + $delay,
            'attempts' => $job->getAttempts(),
        ];
        
        if (file_put_contents($filepath, json_encode($data)) === false) {
            throw new DriverException('Failed to write job to file');
        }
        
        $this->events->trigger('queue.job.pushed', [
            'job_id' => $job->getId(),
            'queue' => $queue,
            'job_name' => $job->getName(),
            'filepath' => $filepath,
        ]);
        
        return $job->getId();
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        // Find next available job
        $pattern = $this->storagePath . 'pending/' . $queue . '_*.json';
        $files = glob($pattern);
        
        $now = time();
        
        foreach ($files as $filepath) {
            $data = json_decode(file_get_contents($filepath), true);
            
            if (!$data) {
                // Corrupted file, move to failed
                $this->moveToFailed($filepath, 'Corrupted job file');
                continue;
            }
            
            // Check if job is available (not delayed)
            if ($data['available_at'] > $now) {
                continue;
            }
            
            // Move to processing directory
            $processingPath = $this->storagePath . 'processing/' . basename($filepath);
            rename($filepath, $processingPath);
            
            // Create job instance
            $jobClass = $data['job']['name'] ?? '';
            
            if (!class_exists($jobClass)) {
                $this->moveToFailed($processingPath, 'Job class not found');
                return null;
            }
            
            return $jobClass::unserialize($data['job'], $this->app);
        }
        
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        // Find the job in processing directory
        $pattern = $this->storagePath . 'processing/*_' . $job->getId() . '.json';
        $files = glob($pattern);
        
        if (empty($files)) {
            return false;
        }
        
        $filepath = $files[0];
        $data = json_decode(file_get_contents($filepath), true);
        
        if (!$data) {
            $this->moveToFailed($filepath, 'Corrupted job file on release');
            return false;
        }
        
        // Update data
        $data['job'] = $job->serialize();
        $data['available_at'] = time() + $delay;
        $data['attempts'] = $job->getAttempts();
        
        // Move back to pending
        $pendingPath = $this->storagePath . 'pending/' . basename($filepath);
        file_put_contents($pendingPath, json_encode($data));
        unlink($filepath);
        
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        // Check in processing directory
        $pattern = $this->storagePath . 'processing/*_' . $job->getId() . '.json';
        $files = glob($pattern);
        
        if (!empty($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
            return true;
        }
        
        // Check in pending directory
        $pattern = $this->storagePath . 'pending/' . $queue . '_' . $job->getId() . '.json';
        $files = glob($pattern);
        
        if (!empty($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
            return true;
        }
        
        return false;
    }

    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int
    {
        $pattern = $this->storagePath . 'pending/' . $queue . '_*.json';
        $files = glob($pattern);
        
        if (!$files) {
            return 0;
        }
        
        $count = 0;
        $now = time();
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['available_at'] <= $now) {
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
        $count = 0;

        // Clear processing
        $pattern = $this->storagePath . 'processing/' . $queue . '_*.json';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
                $count++;
            }
        }
        
        // Clear pending
        $pattern = $this->storagePath . 'pending/' . $queue . '_*.json';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Clear failed jobs
     */
    public function clearFailed(string $queue = 'default'): int
    {
        $count = 0;

        // Clear failed
        $pattern = $this->storagePath . 'failed/' . $queue . '_*.json';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
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
        $pattern = $this->storagePath . 'pending/*.json';
        $files = glob($pattern);
        
        $queues = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $parts = explode('_', $filename);
            if (isset($parts[0])) {
                $queues[] = $parts[0];
            }
        }
        
        return array_unique($queues);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        return is_dir($this->storagePath) && is_writable($this->storagePath);
    }

    /**
     * Helper methods
     */
    protected function generateFilename(string $jobId, string $queue): string
    {
        return $queue . '_' . $jobId . '.json';
    }
    
    protected function moveToFailed(string $filepath, string $error): void
    {
        $failedPath = $this->storagePath . 'failed/' . basename($filepath);
        
        $data = json_decode(file_get_contents($filepath), true);
        if ($data) {
            $data['failed_at'] = time();
            $data['error'] = $error;
            file_put_contents($failedPath, json_encode($data));
        }
        
        unlink($filepath);
    }

    /**
     * Move a job file to the processed directory with completion data.
     */
    protected function moveToProcessed(string $filepath, array $payload = []): void
    {
        $processedPath = $this->storagePath . 'processed/' . basename($filepath);
        
        $data = json_decode(file_get_contents($filepath), true);
        if ($data) {
            $data['processed_at'] = time();
            $data['result_payload'] = $payload; // optional result data
            file_put_contents($processedPath, json_encode($data));
        }
        
        unlink($filepath);
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        $pattern = $this->storagePath . 'failed/' . $queue . '_*.json';
        $files = glob($pattern);
        
        if (!$files) {
            return [];
        }
        
        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $result = [];
        $files = array_slice($files, $offset, $limit);
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
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
        $pattern = $this->storagePath . 'failed/*_' . $jobId . '.json';
        $files = glob($pattern);
        
        if (empty($files)) {
            return false;
        }
        
        $filepath = $files[0];
        $data = json_decode(file_get_contents($filepath), true);
        
        if (!$data || !isset($data['job'])) {
            return false;
        }
        
        // Create job instance
        $jobClass = $data['job']['name'] ?? '';
        
        if (!class_exists($jobClass)) {
            return false;
        }
        
        $job = $jobClass::unserialize($data['job'], $this->app);
        $job->addMetadata('retried_at', date('Y-m-d H:i:s'));
        
        // Push back to queue
        $this->push($job, $queue, 0);
        
        // Remove from failed
        unlink($filepath);
        
        return true;
    }

    /**
     * Get queue statistics
     */
    public function getStats(string $queue = 'default'): array
    {
        $stats = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'delayed' => 0];
        $now = time();
        
        // Check pending directory
        $pattern = $this->storagePath . 'pending/' . $queue . '_*.json';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    if ($data['available_at'] > $now) {
                        $stats['delayed']++;
                    } else {
                        $stats['pending']++;
                    }
                }
            }
        }
        
        // Check processing directory
        $pattern = $this->storagePath . 'processing/' . $queue . '_*.json';
        $files = glob($pattern);
        $stats['processing'] = $files ? count($files) : 0;
        
        // Check failed directory
        $pattern = $this->storagePath . 'failed/' . $queue . '_*.json';
        $files = glob($pattern);
        $stats['failed'] = $files ? count($files) : 0;
        
        return $stats;
    }
    
    /**
     * Clean up old job files
     */
    public function cleanup(int $maxAge = 86400): int
    {
        $count = 0;
        $now = time();
        
        $directories = ['pending', 'processing', 'failed'];
        
        foreach ($directories as $dir) {
            $pattern = $this->storagePath . $dir . '/*.json';
            $files = glob($pattern);
            
            if ($files) {
                foreach ($files as $file) {
                    if ($now - filemtime($file) > $maxAge) {
                        unlink($file);
                        $count++;
                    }
                }
            }
        }
        
        return $count;
    }

    /**
     * Mark a job as failed, moving it from processing to failed.
     */
    public function markAsFailed(string $jobId, string $errorMessage): void 
    {
        $pattern = $this->storagePath . 'processing/*_' . $jobId . '.json';
        
        $files = glob($pattern);
        
        if (empty($files)) {
            return;
        }
        
        $this->moveToFailed($files[0], $errorMessage);
    }

    /**
     * Mark a job as completed, moving it from processing to processed.
     *
     * @param string $jobId
     * @param array $payload Optional result payload to store with the completed job.
     */
    public function markAsCompleted(string $jobId, array $payload = []): void
    {
        // Look for the job in the processing directory
        $pattern = $this->storagePath . 'processing/*_' . $jobId . '.json';
        $files = glob($pattern);
        
        if (empty($files)) {
            // If not found in processing, it might still be pending (shouldn't happen, but handle gracefully)
            $pattern = $this->storagePath . 'pending/*_' . $jobId . '.json';
            $files = glob($pattern);
            if (empty($files)) {
                // Job not found; log or ignore
                $this->events->trigger('queue.marked_completed.failed', [
                    'job_id' => $jobId,
                    'error' => 'Job file not found in processing or pending',
                ]);
                return;
            }
        }
        
        $filepath = $files[0];
        $this->moveToProcessed($filepath, $payload);
        
        $this->events->trigger('queue.marked_completed', [
            'job_id' => $jobId,
            'payload' => $payload,
        ]);
    }
}