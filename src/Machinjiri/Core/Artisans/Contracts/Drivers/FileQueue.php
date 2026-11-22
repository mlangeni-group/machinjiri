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

        $lock = $this->acquireQueueLock();
        if (!$lock) {
            throw new DriverException('Failed to lock queue');
        }

        try {
            if (!$this->atomicWriteJson($filepath, $data)) {
                throw new DriverException('Failed to write job to file');
            }
        } finally {
            $this->releaseQueueLock($lock);
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
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return null;
        }

        try {
            $this->recoverStaleProcessingJobs($queue);

            $pattern = $this->storagePath . 'pending/' . $queue . '_*.json';
            $files = glob($pattern) ?: [];
            $now = time();

            foreach ($files as $filepath) {
                $contents = file_get_contents($filepath);
                $data = $contents === false ? null : json_decode($contents, true);

                if (!is_array($data) || !isset($data['available_at']) || !is_numeric($data['available_at'])) {
                    $this->moveToFailed($filepath, 'Corrupted job file');
                    continue;
                }

                if ((int) $data['available_at'] > $now) {
                    continue;
                }

                $processingPath = $this->storagePath . 'processing/' . basename($filepath);
                if (!@rename($filepath, $processingPath)) {
                    continue;
                }

                $data['reserved_at'] = $now;
                if (!$this->atomicWriteJson($processingPath, $data)) {
                    @rename($processingPath, $filepath);
                    continue;
                }

                $jobClass = is_array($data['job'] ?? null) ? ($data['job']['name'] ?? '') : '';
                if (!is_string($jobClass) || !class_exists($jobClass)) {
                    $this->moveToFailed($processingPath, 'Job class not found');
                    return null;
                }

                return $jobClass::unserialize($data['job'], $this->app);
            }

            return null;
        } finally {
            $this->releaseQueueLock($lock);
        }
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        $pattern = $this->storagePath . 'processing/*_' . $job->getId() . '.json';
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return false;
        }

        try {
            $files = glob($pattern) ?: [];
            if (empty($files)) {
                return false;
            }

            $filepath = $files[0];
            $contents = file_get_contents($filepath);
            $data = $contents === false ? null : json_decode($contents, true);

            if (!is_array($data)) {
                $this->moveToFailed($filepath, 'Corrupted job file on release');
                return false;
            }

            $data['job'] = $job->serialize();
            $data['available_at'] = time() + $delay;
            $data['attempts'] = $job->getAttempts();
            unset($data['reserved_at']);

            $pendingPath = $this->storagePath . 'pending/' . basename($filepath);
            if (!$this->atomicWriteJson($pendingPath, $data)) {
                return false;
            }

            if (!unlink($filepath)) {
                @unlink($pendingPath);
                return false;
            }

            return true;
        } finally {
            $this->releaseQueueLock($lock);
        }
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return false;
        }

        try {
            $pattern = $this->storagePath . 'processing/*_' . $job->getId() . '.json';
            $files = glob($pattern) ?: [];

            if (!empty($files)) {
                $deleted = false;
                foreach ($files as $file) {
                    $deleted = unlink($file) || $deleted;
                }
                return $deleted;
            }

            $pattern = $this->storagePath . 'pending/' . $queue . '_' . $job->getId() . '.json';
            $files = glob($pattern) ?: [];

            if (!empty($files)) {
                $deleted = false;
                foreach ($files as $file) {
                    $deleted = unlink($file) || $deleted;
                }
                return $deleted;
            }

            return false;
        } finally {
            $this->releaseQueueLock($lock);
        }
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
            $contents = file_get_contents($file);
            $data = $contents === false ? null : json_decode($contents, true);
            if (is_array($data) && isset($data['available_at']) && is_numeric($data['available_at']) && (int) $data['available_at'] <= $now) {
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
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return 0;
        }

        try {
            foreach (['processing', 'pending'] as $directory) {
                $pattern = $this->storagePath . $directory . '/' . $queue . '_*.json';
                $files = glob($pattern) ?: [];
                foreach ($files as $file) {
                    if (unlink($file)) {
                        $count++;
                    }
                }
            }

            return $count;
        } finally {
            $this->releaseQueueLock($lock);
        }
    }

    /**
     * Clear failed jobs
     */
    public function clearFailed(string $queue = 'default'): int
    {
        $count = 0;
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return 0;
        }

        try {
            $pattern = $this->storagePath . 'failed/' . $queue . '_*.json';
            $files = glob($pattern) ?: [];
            foreach ($files as $file) {
                if (unlink($file)) {
                    $count++;
                }
            }

            return $count;
        } finally {
            $this->releaseQueueLock($lock);
        }
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

    protected function acquireQueueLock()
    {
        $lock = @fopen($this->storagePath . '.queue.lock', 'c');
        if ($lock === false) {
            return null;
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return null;
        }

        return $lock;
    }

    protected function releaseQueueLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    protected function atomicWriteJson(string $filepath, array $data): bool
    {
        $json = json_encode($data);
        if ($json === false) {
            return false;
        }

        $directory = dirname($filepath);
        $tempPath = tempnam($directory, '.queue-');
        if ($tempPath === false) {
            return false;
        }

        if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
            @unlink($tempPath);
            return false;
        }

        @chmod($tempPath, 0644);

        if (!@rename($tempPath, $filepath)) {
            @unlink($tempPath);
            return false;
        }

        return true;
    }

    protected function recoverStaleProcessingJobs(string $queue): void
    {
        $retryAfter = (int) ($this->config['retry_after'] ?? 90);
        if ($retryAfter < 0) {
            return;
        }

        $pattern = $this->storagePath . 'processing/' . $queue . '_*.json';
        $files = glob($pattern) ?: [];
        $now = time();

        foreach ($files as $filepath) {
            $modifiedAt = filemtime($filepath);
            if ($modifiedAt === false || $now - $modifiedAt < $retryAfter) {
                continue;
            }

            $contents = file_get_contents($filepath);
            $data = $contents === false ? null : json_decode($contents, true);

            if (!is_array($data)) {
                $this->moveToFailed($filepath, 'Corrupted processing job file');
                continue;
            }

            $data['available_at'] = $now;
            $data['released_at'] = $now;
            $data['release_reason'] = 'Processing timeout';
            unset($data['reserved_at']);

            $pendingPath = $this->storagePath . 'pending/' . basename($filepath);
            if ($this->atomicWriteJson($pendingPath, $data)) {
                @unlink($filepath);
            }
        }
    }
    
    protected function moveToFailed(string $filepath, string $error): void
    {
        $failedPath = $this->storagePath . 'failed/' . basename($filepath);

        $contents = file_get_contents($filepath);
        $data = $contents === false ? null : json_decode($contents, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($this->atomicWriteJson($failedPath, array_merge($data, [
            'failed_at' => time(),
            'error' => $error,
        ]))) {
            @unlink($filepath);
        }
    }

    /**
     * Move a job file to the processed directory with completion data.
     */
    protected function moveToProcessed(string $filepath, array $payload = []): void
    {
        $processedPath = $this->storagePath . 'processed/' . basename($filepath);

        $contents = file_get_contents($filepath);
        $data = $contents === false ? null : json_decode($contents, true);
        if (is_array($data)) {
            $data['processed_at'] = time();
            $data['result_payload'] = $payload; // optional result data
            if ($this->atomicWriteJson($processedPath, $data)) {
                @unlink($filepath);
            }
        }
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

        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return false;
        }

        try {
            $files = glob($pattern) ?: [];
            if (empty($files)) {
                return false;
            }

            $filepath = $files[0];
            $contents = file_get_contents($filepath);
            $data = $contents === false ? null : json_decode($contents, true);

            if (!is_array($data) || !isset($data['job']) || !is_array($data['job'])) {
                return false;
            }

            $jobClass = $data['job']['name'] ?? '';
            if (!is_string($jobClass) || !class_exists($jobClass)) {
                return false;
            }

            $job = $jobClass::unserialize($data['job'], $this->app);
            $job->addMetadata('retried_at', date('Y-m-d H:i:s'));

            $filename = $this->generateFilename($job->getId(), $queue);
            $pendingData = [
                'job' => $job->serialize(),
                'queue' => $queue,
                'created_at' => time(),
                'available_at' => time(),
                'attempts' => $job->getAttempts(),
            ];

            if (!$this->atomicWriteJson($this->storagePath . 'pending/' . $filename, $pendingData)) {
                return false;
            }

            if (!unlink($filepath)) {
                @unlink($this->storagePath . 'pending/' . $filename);
                return false;
            }

            return true;
        } finally {
            $this->releaseQueueLock($lock);
        }
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
                $contents = file_get_contents($file);
                $data = $contents === false ? null : json_decode($contents, true);
                if (is_array($data) && isset($data['available_at']) && is_numeric($data['available_at'])) {
                    if ((int) $data['available_at'] > $now) {
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

        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return 0;
        }

        try {
            foreach (['pending', 'processing', 'failed'] as $dir) {
                $pattern = $this->storagePath . $dir . '/*.json';
                $files = glob($pattern) ?: [];
                foreach ($files as $file) {
                    $modifiedAt = filemtime($file);
                    if ($modifiedAt !== false && $now - $modifiedAt > $maxAge) {
                        if (unlink($file)) {
                            $count++;
                        }
                    }
                }
            }

            return $count;
        } finally {
            $this->releaseQueueLock($lock);
        }
    }

    /**
     * Mark a job as failed, moving it from processing to failed.
     */
    public function markAsFailed(string $jobId, string $errorMessage): void 
    {
        $pattern = $this->storagePath . 'processing/*_' . $jobId . '.json';
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return;
        }

        try {
            $files = glob($pattern) ?: [];
            if (!empty($files)) {
                $this->moveToFailed($files[0], $errorMessage);
            }
        } finally {
            $this->releaseQueueLock($lock);
        }
    }

    /**
     * Mark a job as completed, moving it from processing to processed.
     *
     * @param string $jobId
     * @param array $payload Optional result payload to store with the completed job.
     */
    public function markAsCompleted(string $jobId, array $payload = []): void
    {
        $pattern = $this->storagePath . 'processing/*_' . $jobId . '.json';
        $lock = $this->acquireQueueLock();
        if (!$lock) {
            return;
        }

        try {
            $files = glob($pattern) ?: [];

            if (empty($files)) {
                $pattern = $this->storagePath . 'pending/*_' . $jobId . '.json';
                $files = glob($pattern) ?: [];
                if (empty($files)) {
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
        } finally {
            $this->releaseQueueLock($lock);
        }
    }
}