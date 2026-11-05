<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{LoggerFactory, Logger};
use Symfony\Component\Process\Process;

/**
 * @author Mlangeni Machinjiri
 */
class BackgroundWorkerManager
{
    private Container $app;
    private Logger $logger;
    private string $storagePath;
    private string $basePath;
    
    // Dynamic configuration (reloaded each operation)
    private int $maxWorkerMemory;
    private int $maxJobsPerWorker;
    private int $checkInterval;
    private int $heartbeatTtl;
    private int $gracePeriod;
    private bool $stopOnEmpty;

    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->logger = LoggerFactory::system('queue-supervisor', "queue");
        $this->basePath = $this->detectBasePath();
        $this->storagePath = $this->basePath . '/storage/queue';
        
        if (!is_dir($this->storagePath)) {
            $oldUmask = umask(0077);
            mkdir($this->storagePath, 0750, true);
            umask($oldUmask);
        }
        
        $this->reloadConfig();
    }

    /**
     * Reload configuration from environment variables.
     */
    public function reloadConfig(): void
    {
        $this->maxWorkerMemory = (int) (env('QUEUE_WORKER_MAX_MEMORY', 128));
        $this->maxJobsPerWorker = (int) (env('QUEUE_WORKER_MAX_JOBS', 500));
        $this->checkInterval = (int) (env('QUEUE_WORKER_CHECK_INTERVAL', 5));
        $this->heartbeatTtl = (int) (env('QUEUE_WORKER_HEARTBEAT_TTL', 15));
        $this->gracePeriod = (int) (env('QUEUE_WORKER_GRACE_PERIOD', 10));
        $this->stopOnEmpty = (bool) (env('QUEUE_WORKER_STOP_ON_EMPTY', false));
    }

    /**
     * Start one or more workers for a queue/driver.
     *
     * @param string $queue
     * @param string $driver
     * @param int $concurrency Number of parallel workers
     * @return int Number of successfully started workers
     */
    public function startWorker(string $queue, string $driver = 'default', int $concurrency = 1): int
    {
        $this->reloadConfig(); // always fresh config
        
        if (!$this->isValidQueueName($queue) || !$this->isValidDriverName($driver)) {
            $this->logger->error('Invalid queue or driver name', ['queue' => $queue, 'driver' => $driver]);
            return 0;
        }

        $started = 0;
        for ($i = 1; $i <= $concurrency; $i++) {
            if ($this->startSingleWorker($queue, $driver, $concurrency > 1 ? $i : null)) {
                $started++;
            }
        }
        return $started;
    }

    /**
     * Start a single worker instance.
     */
    private function startSingleWorker(string $queue, string $driver, ?int $instance = null): bool
    {
        $pidFile = $this->getPidFile($queue, $driver, $instance);
        if ($this->isWorkerRunning($pidFile)) {
            $this->logger->warning('Worker already running', [
                'queue' => $queue,
                'driver' => $driver,
                'instance' => $instance ?? 1,
            ]);
            return false;
        }

        $artisan = $this->basePath . '/artisan';
        if (!$this->ensureArtisanReadable($artisan)) {
            $this->logger->error('Artisan not usable', ['path' => $artisan]);
            return false;
        }

        $phpBinary = $this->getValidPhpBinary();
        if (!$phpBinary) {
            $this->logger->error('No valid PHP binary found');
            return false;
        }

        // Build command
        $command = [
            $phpBinary,
            'artisan',
            'queue:work',
            "--driver={$driver}",
            "--queue={$queue}",
            "--memory={$this->maxWorkerMemory}",
            "--max-jobs={$this->maxJobsPerWorker}",
        ];

        if ($this->stopOnEmpty) {
            $command[] = '--stop-on-empty';
        }

        // Let worker write its own PID file (for health checks)
        $workerPidFile = $this->getWorkerPidFile($queue, $driver, $instance);
        $command[] = "--pid-file={$workerPidFile}";

        // Prepare log file for stdout/stderr
        $logFile = $this->storagePath . "/worker-{$driver}-{$queue}" . ($instance ? "-{$instance}" : "") . ".log";
        $logHandle = fopen($logFile, 'a');
        if (!$logHandle) {
            $this->logger->error('Cannot open log file for worker', ['logfile' => $logFile]);
            return false;
        }

        $process = new Process($command, $this->basePath);
        $process->setWorkingDirectory($this->basePath);
        $process->setEnv(array_merge($_ENV, $_SERVER ?? []));
        $process->setTimeout(null); // no timeout, runs forever
        $process->setIdleTimeout(null);
        
        // Redirect output to log file
        /*$process->setOutput($logHandle);
        $process->setErrorOutput($logHandle);*/
        $process->disableOutput();
        
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $process->setOptions(['create_new_console' => true]);
        }

        try {
            $process->start();
            
            // Wait up to 5 seconds for worker to write its PID file
            $maxWait = 5;
            $waited = 0;
            $workerPid = null;
            while ($waited < $maxWait && $process->isRunning()) {
                usleep(200000);
                $waited += 0.2;
                if (file_exists($workerPidFile) && ($pid = @file_get_contents($workerPidFile)) && (int)$pid > 0) {
                    $workerPid = (int)$pid;
                    break;
                }
            }
            
            if (!$process->isRunning()) {
                $error = $process->getErrorOutput() ?: $process->getOutput();
                $this->logger->error('Worker process died during startup', [
                    'queue' => $queue,
                    'driver' => $driver,
                    'error' => $error,
                ]);
                fclose($logHandle);
                return false;
            }
            
            // If worker didn't write PID file, use process PID as fallback
            if (!$workerPid) {
                $workerPid = $process->getPid();
                $this->logger->warning('Worker did not write PID file, using process PID', ['pid' => $workerPid]);
            }
            
            // Store supervisor PID file with the actual worker PID
            file_put_contents($pidFile, $workerPid);
            chmod($pidFile, 0600);
            
            // Write heartbeat initial timestamp
            $heartbeatFile = $this->getHeartbeatFile($queue, $driver, $instance);
            file_put_contents($heartbeatFile, time());
            
            $this->logger->info('Worker started', [
                'queue' => $queue,
                'driver' => $driver,
                'instance' => $instance ?? 1,
                'pid' => $workerPid,
                'log' => $logFile,
            ]);
            
            fclose($logHandle);
            return true;
            
        } catch (\Throwable $e) {
            $this->logger->error('Exception starting worker', [
                'queue' => $queue,
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);
            fclose($logHandle);
            return false;
        }
    }

    /**
     * Stop a worker (or all instances) gracefully.
     *
     * @param string $queue
     * @param string $driver
     * @param int|null $instance If null, stop all instances for this queue/driver.
     * @return bool|int True if single stopped, number stopped if multiple.
     */
    public function stopWorker(string $queue, string $driver = 'default', ?int $instance = null)
    {
        if ($instance !== null) {
            return $this->stopSingleWorker($queue, $driver, $instance);
        }
        
        // Stop all instances
        $pattern = $this->storagePath . "/worker_{$driver}_{$queue}*.pid";
        $files = glob($pattern);
        $stopped = 0;
        foreach ($files as $pidFile) {
            // Extract instance number from filename
            if (preg_match('/worker_' . preg_quote($driver, '/') . '_' . preg_quote($queue, '/') . '_(\d+)\.pid$/', $pidFile, $matches)) {
                $inst = (int)$matches[1];
                if ($this->stopSingleWorker($queue, $driver, $inst)) {
                    $stopped++;
                }
            } elseif (preg_match('/worker_' . preg_quote($driver, '/') . '_' . preg_quote($queue, '/') . '\.pid$/', $pidFile)) {
                if ($this->stopSingleWorker($queue, $driver, null)) {
                    $stopped++;
                }
            }
        }
        return $stopped;
    }

    private function stopSingleWorker(string $queue, string $driver, ?int $instance): bool
    {
        $pidFile = $this->getPidFile($queue, $driver, $instance);
        if (!file_exists($pidFile)) {
            $this->logger->debug('No PID file found, worker not running', [
                'queue' => $queue,
                'driver' => $driver,
                'instance' => $instance ?? 1,
            ]);
            return false;
        }
        
        $pid = (int) file_get_contents($pidFile);
        if ($pid <= 0) {
            @unlink($pidFile);
            return false;
        }
        
        $sent = false;
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            // Windows: use taskkill
            exec("taskkill /PID {$pid} 2>NUL", $output, $exitCode);
            $sent = ($exitCode === 0);
        } else {
            // Unix: send SIGTERM
            if (function_exists('posix_kill')) {
                $sent = posix_kill($pid, SIGTERM);
            } else {
                $this->logger->error('Cannot send signals on this platform');
                return false;
            }
        }
        
        if ($sent) {
            // Wait for graceful shutdown
            $waited = 0;
            while ($waited < $this->gracePeriod) {
                sleep(1);
                $waited++;
                if (!$this->isProcessAlive($pid)) {
                    break;
                }
            }
            
            // Force kill if still alive
            if ($this->isProcessAlive($pid)) {
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    exec("taskkill /F /PID {$pid} 2>NUL");
                } else {
                    posix_kill($pid, SIGKILL);
                }
                $this->logger->info('Worker force killed', ['pid' => $pid]);
            }
        }
        
        // Clean up files
        @unlink($pidFile);
        $heartbeatFile = $this->getHeartbeatFile($queue, $driver, $instance);
        @unlink($heartbeatFile);
        $workerPidFile = $this->getWorkerPidFile($queue, $driver, $instance);
        @unlink($workerPidFile);
        
        $this->logger->info('Worker stopped', [
            'queue' => $queue,
            'driver' => $driver,
            'instance' => $instance ?? 1,
            'pid' => $pid,
        ]);
        
        return true;
    }

    /**
     * Restart a worker (or all instances).
     */
    public function restartWorker(string $queue, string $driver = 'default', ?int $instance = null, int $concurrency = 1): int
    {
        $stopped = $this->stopWorker($queue, $driver, $instance);
        if ($instance !== null) {
            return $this->startWorker($queue, $driver, 1) ? 1 : 0;
        }
        return $this->startWorker($queue, $driver, $concurrency);
    }

    /**
     * Get status of a worker (or all instances).
     */
    public function workerStatus(string $queue, string $driver = 'default', ?int $instance = null): array
    {
        if ($instance !== null) {
            return $this->singleWorkerStatus($queue, $driver, $instance);
        }
        
        $statuses = [];
        $pattern = $this->storagePath . "/worker_{$driver}_{$queue}*.pid";
        $files = glob($pattern);
        foreach ($files as $pidFile) {
            if (preg_match('/worker_' . preg_quote($driver, '/') . '_' . preg_quote($queue, '/') . '_(\d+)\.pid$/', $pidFile, $matches)) {
                $inst = (int)$matches[1];
                $statuses[] = $this->singleWorkerStatus($queue, $driver, $inst);
            } elseif (preg_match('/worker_' . preg_quote($driver, '/') . '_' . preg_quote($queue, '/') . '\.pid$/', $pidFile)) {
                $statuses[] = $this->singleWorkerStatus($queue, $driver, null);
            }
        }
        return $statuses;
    }

    private function singleWorkerStatus(string $queue, string $driver, ?int $instance): array
    {
        $pidFile = $this->getPidFile($queue, $driver, $instance);
        $running = $this->isWorkerRunning($pidFile);
        $pid = $running ? (int) file_get_contents($pidFile) : null;
        
        $status = [
            'queue' => $queue,
            'driver' => $driver,
            'instance' => $instance ?? 1,
            'running' => $running,
            'pid' => $pid,
            'healthy' => false,
        ];
        
        if ($running && $pid) {
            // Check heartbeat
            $heartbeatFile = $this->getHeartbeatFile($queue, $driver, $instance);
            if (file_exists($heartbeatFile)) {
                $lastHeartbeat = (int) file_get_contents($heartbeatFile);
                $status['last_heartbeat'] = $lastHeartbeat;
                $status['healthy'] = (time() - $lastHeartbeat) <= $this->heartbeatTtl;
            } else {
                $status['healthy'] = false;
            }
            
            // Memory usage (Linux only)
            if ($this->maxWorkerMemory > 0) {
                $memory = $this->getProcessMemory($pid);
                if ($memory !== null) {
                    $status['memory_mb'] = round($memory, 2);
                    $status['memory_limit_mb'] = $this->maxWorkerMemory;
                }
            }
        }
        
        return $status;
    }

    /**
     * Monitor a worker and restart it if it dies, becomes unhealthy, or exceeds memory.
     * This runs forever; use a process supervisor (systemd/supervisord) to keep it alive.
     */
    public function monitorWorker(string $queue, string $driver = 'default', int $concurrency = 1, ?callable $output = null): void
    {
        $this->reloadConfig();
        
        $lockFile = $this->storagePath . "/monitor_{$driver}_{$queue}.lock";
        $fp = fopen($lockFile, 'c');
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            $this->logger->error('Another monitor is already running for this worker', [
                'queue' => $queue,
                'driver' => $driver,
            ]);
            if ($output) $output("Another monitor is already running for {$driver}:{$queue}\n");
            return;
        }
        
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $shouldRun = true;
            pcntl_signal(SIGINT, function() use (&$shouldRun, $output) {
                $shouldRun = false;
                if ($output) $output("\nReceived SIGINT, shutting down monitor...\n");
            });
            pcntl_signal(SIGTERM, function() use (&$shouldRun, $output) {
                $shouldRun = false;
                if ($output) $output("\nReceived SIGTERM, shutting down monitor...\n");
            });
        } else {
            $shouldRun = true;
        }
        
        $this->logger->info('Starting worker monitor', [
            'queue' => $queue,
            'driver' => $driver,
            'concurrency' => $concurrency,
        ]);
        if ($output) $output("Monitoring {$driver}:{$queue} with {$concurrency} worker(s)...\n");
        
        while ($shouldRun) {
            // Ensure desired number of workers are running
            $currentStatus = $this->workerStatus($queue, $driver);
            $runningCount = count(array_filter($currentStatus, fn($s) => $s['running'] === true));
            
            for ($i = 1; $i <= $concurrency; $i++) {
                $instanceStatus = $this->singleWorkerStatus($queue, $driver, $i);
                $shouldStart = false;
                
                if (!$instanceStatus['running']) {
                    $shouldStart = true;
                    $reason = 'not running';
                } elseif (!$instanceStatus['healthy']) {
                    $shouldStart = true;
                    $reason = 'unhealthy (heartbeat stale)';
                } elseif ($this->maxWorkerMemory > 0 && isset($instanceStatus['memory_mb']) && $instanceStatus['memory_mb'] > $this->maxWorkerMemory) {
                    $shouldStart = true;
                    $reason = sprintf('memory limit exceeded (%.2fMB > %dMB)', $instanceStatus['memory_mb'], $this->maxWorkerMemory);
                }
                
                if ($shouldStart) {
                    $this->logger->info('Restarting worker', [
                        'queue' => $queue,
                        'driver' => $driver,
                        'instance' => $i,
                        'reason' => $reason,
                    ]);
                    if ($output) $output("Restarting worker {$i} ({$reason})...\n");
                    $this->restartWorker($queue, $driver, $i, 1);
                }
            }
            
            // Sleep interval, but check for shutdown periodically
            for ($i = 0; $i < $this->checkInterval && $shouldRun; $i++) {
                sleep(1);
            }
        }
        
        flock($fp, LOCK_UN);
        fclose($fp);
        $this->logger->info('Monitor stopped', ['queue' => $queue, 'driver' => $driver]);
        if ($output) $output("Monitor stopped.\n");
    }

    /**
     * Update heartbeat for a worker (called from within the worker process).
     */
    public function updateHeartbeat(string $queue, string $driver = 'default', ?int $instance = null): void
    {
        $heartbeatFile = $this->getHeartbeatFile($queue, $driver, $instance);
        file_put_contents($heartbeatFile, time());
    }

    /**
     * Clean up stale PID and heartbeat files.
     */
    public function cleanupOrphanedPids(): void
    {
        $files = glob($this->storagePath . '/worker_*.pid');
        foreach ($files as $file) {
            $pid = (int) file_get_contents($file);
            if ($pid > 0 && !$this->isProcessAlive($pid)) {
                @unlink($file);
                $this->logger->debug('Removed orphaned PID file', ['file' => $file]);
            }
        }
        
        $heartbeats = glob($this->storagePath . '/heartbeat_*.txt');
        $now = time();
        foreach ($heartbeats as $hb) {
            if (filemtime($hb) < ($now - $this->heartbeatTtl * 2)) {
                @unlink($hb);
                $this->logger->debug('Removed stale heartbeat file', ['file' => $hb]);
            }
        }
    }

    
    private function detectBasePath(): string
    {
        if (method_exists($this->app, 'getBasePath')) {
            $path = $this->app->getBasePath();
            if (file_exists($path . '/artisan')) {
                return $path;
            }
        }
        
        $cwd = getcwd();
        $dir = $cwd;
        for ($i = 0; $i < 10; $i++) {
            if (file_exists($dir . '/artisan')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        
        $this->logger->warning('Could not find artisan root, using CWD', ['cwd' => $cwd]);
        return $cwd;
    }

    private function ensureArtisanReadable(string $artisanPath): bool
    {
        if (!file_exists($artisanPath)) {
            return false;
        }
        // Only check readability; do NOT attempt to change permissions.
        return is_readable($artisanPath);
    }

    private function getValidPhpBinary(): ?string
    {
        $phpBinary = PHP_BINARY;
        if ($this->isExecutablePath($phpBinary)) {
            return $phpBinary;
        }
        
        // Fallback search only if PHP_BINARY is unusable
        $candidates = defined('PHP_WINDOWS_VERSION_BUILD')
            ? ['php.exe', 'C:\php\php.exe', 'C:\xampp\php\php.exe']
            : ['/usr/bin/php', '/usr/local/bin/php', 'php'];
        
        foreach ($candidates as $candidate) {
            if ($this->isExecutablePath($candidate)) {
                return $candidate;
            }
        }
        
        // Try `which`/`where`
        $cmd = defined('PHP_WINDOWS_VERSION_BUILD') ? 'where php' : 'which php';
        $output = [];
        exec("{$cmd} 2>NUL", $output, $exitCode);
        if ($exitCode === 0 && !empty($output) && $this->isExecutablePath($output[0])) {
            return trim($output[0]);
        }
        
        return null;
    }

    private function isExecutablePath(string $path): bool
    {
        if (empty($path) || !file_exists($path)) return false;
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            return in_array($ext, ['exe', 'bat', 'cmd']) || is_readable($path);
        }
        return is_executable($path);
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        // Windows fallback
        $output = [];
        exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output, $exitCode);
        return ($exitCode === 0 && count($output) > 1);
    }

    private function getProcessMemory(int $pid): ?float
    {
        if (!function_exists('posix_kill') || !is_readable("/proc/{$pid}/statm")) {
            return null;
        }
        $statm = @file_get_contents("/proc/{$pid}/statm");
        if ($statm === false) return null;
        $pages = explode(' ', $statm);
        $rssPages = (int) ($pages[1] ?? 0);
        // Assume 4KB page size
        return ($rssPages * 4096) / (1024 * 1024);
    }

    private function isWorkerRunning(string $pidFile): bool
    {
        if (!file_exists($pidFile)) return false;
        $pid = (int) file_get_contents($pidFile);
        if ($pid <= 0) {
            @unlink($pidFile);
            return false;
        }
        return $this->isProcessAlive($pid);
    }

    private function isValidQueueName(string $queue): bool
    {
        return preg_match('/^[a-zA-Z0-9_\-]+$/', $queue) === 1;
    }

    private function isValidDriverName(string $driver): bool
    {
        return preg_match('/^[a-z]+$/', $driver) === 1;
    }

    private function getPidFile(string $queue, string $driver, ?int $instance = null): string
    {
        $base = $this->storagePath . "/worker_{$driver}_{$queue}";
        return $instance ? $base . "_{$instance}.pid" : $base . ".pid";
    }

    private function getWorkerPidFile(string $queue, string $driver, ?int $instance = null): string
    {
        // This is the file the worker itself writes (via --pid-file)
        $base = $this->storagePath . "/worker_running_{$driver}_{$queue}";
        return $instance ? $base . "_{$instance}.pid" : $base . ".pid";
    }

    private function getHeartbeatFile(string $queue, string $driver, ?int $instance = null): string
    {
        $base = $this->storagePath . "/heartbeat_{$driver}_{$queue}";
        return $instance ? $base . "_{$instance}.txt" : $base . ".txt";
    }
}