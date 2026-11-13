<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\{InputOption, InputArgument, InputInterface};
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\{Table, ProgressBar};
use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseWorker, BackgroundWorkerManager};
use Mlangeni\Machinjiri\Core\Artisans\Contracts\Schema\DatabaseQueueSchema;
use Mlangeni\Machinjiri\Core\Artisans\Generators\QueueJobGenerator;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\DotEnv;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Database\Config\DatabaseConfiguration;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\TerminalException;

class SignalManager
{
    public function register(callable $handler, array $signals): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ($signals as $sig) {
            pcntl_signal($sig, $handler);
        }
    }
}
trait QueueCommandHelper
{
    use CommandHelper;
    
    protected Container $appContainer;

    private function queueGenerator(): QueueJobGenerator
    {
        return new QueueJobGenerator($this->artisanContainer());
    }

    private function loadEnvironmentVariables(): void
    { 
        try {
            $dotenv = new DotEnv($this->artisanContainer(), true);
            $dotenv->load();
        } catch (\Throwable $e) {
            $this->logger->debug("Could not load .env \n{file}\n{error}", [
                'error' => $e->getMessage()
              ]);
        }
    }

    private function getContainerInstance(): Container
    {
        return $this->artisanContainer();
    }

    private function driverName(): string 
    {
        return $this->loadQueueConfig()['default'] ?? getenv("QUEUE_DRIVER", "sync");
    }

    private function loadQueueConfig(): array
    {
        $config = $this->artisanContainer()->configurations['queue'] ?? null;
        if ($config !== null) return $config;
        
        return [
            'default' => 'database',
            'drivers' => [
                'database' => [
                    'driver'      => 'database',
                    'table'       => 'jobs',
                    'queue'       => 'default',
                    'retry_after' => 90,
                ],
                'sync' => ['driver' => 'sync'],
            ],
        ];
    }

    private function validateQueueConfig(array $config): array
    {
        if (!isset($config['drivers']) || !is_array($config['drivers'])) {
            throw new TerminalException('Queue configuration must contain a "drivers" array.');
        }
        foreach ($config['drivers'] as $name => $cfg) {
            if (!is_array($cfg)) {
                throw new TerminalException("Driver configuration for '{$name}' must be an array.");
            }
        }
        return $config;
    }

    private function createBaseWorker(): ?object
    {
        $container = $this->artisanContainer();
        if (!$container->bound('queue.worker')) {
            throw new TerminalException("Queue Worker is not bound in container. Please ensure the QueueServiceProvider is loaded or run 'php artisan queue:init'.");
        }
        return $container->resolve('queue.worker');
    }

    private function getQueueDriver(): ?object 
    {
        $container = $this->artisanContainer();
        if (!$container->bound('queue')) {
            throw new TerminalException("Queue is not bound in Container. Please ensure the QueueServiceProvider is loaded or run 'php artisan queue:init'");
        }
        return $container->resolve('queue');
    }
 
    private function createJobProcessor(): ?object
    {
        $container = $this->artisanContainer();
        if (!$container->bound('queue.processor')) {
            throw new TerminalException("Queue processor is not registered in the container. Please ensure the QueueServiceProvider is loaded or run 'php artisan queue:init'.");
        }
        return $container->resolve('queue.processor');
    }

    protected function executeSafely(InputInterface $input, OutputInterface $output, callable $callback): int
    {
        try {
            
            return $callback($input, $output);
        } catch (\Throwable $e) {
            $io = new SymfonyStyle($input, $output);
            $io->error($e->getMessage() . " in: [" . $e->getFile() . "] on line: ". $e->getLine());
            if ($output->isVerbose()) {
                $io->writeln("<error>{$e->getTraceAsString()}</error>");
            }
            return Command::FAILURE;
        }
    }

    private function getDbCfg(): DatabaseConfiguration
    {
        return new DatabaseConfiguration($this->artisanContainer());
    }

    
    private function loadDatabaseConfig(): array
    {
        return $this->getDbCfg()->getConnCfg();
    }
    
    private function ensureDatabaseQueueTables(): void
    {
        $defaultDriver = $this->queueConfig['default'] ?? 'database';
        if ($defaultDriver !== 'database') {
            return;
        }

        $queueDriver = $this->driverResolver->resolve('database');
        if (!$queueDriver || !method_exists($queueDriver, 'getConnection')) {
            $this->logger->warning('Cannot create queue tables: database driver not available or missing getConnection()');
            return;
        }

        try {
            $pdo = $queueDriver->getConnection();
            $table = $this->queueConfig['drivers']['database']['table'] ?? 'queue_jobs';
            $failedTable = $this->queueConfig['drivers']['database']['failed_table'] ?? 'queue_failed_jobs';
            $this->ensureQueueTablesExist($table, $failedTable);
            $this->logger->info('Queue tables verified/created successfully');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to create queue tables: ' . $e->getMessage());
        }
    }
    
    protected function requireDatabaseTables(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->ensureDatabaseQueueTables();
        } catch (\Throwable $e) {
            $io->error('Database queue tables could not be created. Please run "php artisan queue:init" first.');
            $io->writeln('Error: ' . $e->getMessage());
            exit(Command::FAILURE);
        }
    }

    private function testDatabaseConnection(): bool 
    {
        return $this->getDatabaseConnection() instanceof \PDO;    
    }

    private function getDatabaseConnection(): ?\PDO 
    {
        $container = $this->artisanContainer();
        if (!$container->bound("db.kernel.connection")) {
            throw new TerminalException("Database not bound in container");
        }
        return $container->resolve("db.kernel.connection");
    }
    
    private function ensureQueueTablesExist(string $table, string $failedTable): void
    {
        $logger = LoggerFactory::system('queue-setup', 'queue');
        $driverName = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $driver = $this->normalizeDriver($driverName);
        $connection = $this->getDatabaseConnection();
        
        $tableExists = function (string $tableName) use ($connection, $driverName): bool {
            try {
                switch ($driverName) {
                    case 'sqlite':
                        $stmt = $connection->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table");
                        $stmt->execute([':table' => $tableName]);
                        return $stmt->fetch() !== false;
                    case 'mysql':
                        $stmt = $connection->prepare("SHOW TABLES LIKE :table");
                        $stmt->execute([':table' => $tableName]);
                        return $stmt->rowCount() > 0;
                    case 'pgsql':
                        $stmt = $connection->prepare("SELECT tablename FROM pg_tables WHERE tablename = :table");
                        $stmt->execute([':table' => $tableName]);
                        return $stmt->fetch() !== false;
                    default:
                        try {
                            $stmt = $connection->prepare("SELECT 1 FROM \"$tableName\" LIMIT 1");
                            $stmt->execute();
                            return true;
                        } catch (\PDOException $e) {
                            return false;
                        }
                }
            } catch (\PDOException $e) {
                return false;
            }
        };
        
        $mainMissing = !$tableExists($table);
        $failedMissing = !$tableExists($failedTable);
        if (!$mainMissing && !$failedMissing) {
            return;
        }
        
        $logger->info("Essential queue tables missing, auto-creating for driver {$driver}...");
        $schema = $this->getEssentialSchema($driver, $table, $failedTable);
        $this->executeStatements($connection, $schema);
        $logger->info('Essential queue tables created successfully');
    }
    
    private function createFullSchema(): void
    {
        $logger = LoggerFactory::system('queue-setup', 'queue');
        $driverName = $this->loadDatabaseConfig()['driver'] ?? "mysql";
        if (strtolower($driverName) == "mongodb") {
            $logger->info('MongoDB detected – no relational tables to create.');
            return;
        }
        $driver = $this->normalizeDriver($driverName);
        
        $logger->info("Creating full queue schema for driver: {$driver}");
        $schema = $this->getFullSchema($driver);
        $this->executeStatements($schema);
        $logger->info('Full queue schema created successfully');
    }
    
    private function normalizeDriver(string $driverName): string
    {
        return match ($driverName) {
            'mysql' => 'mysql',
            'pgsql', 'postgresql' => 'pgsql',
            'sqlite' => 'sqlite',
            default => ''  // fallback
        };
    }
    
    private function getEssentialSchema(string $driver, string $jobsTable, string $failedTable): string
    {
        return match ($driver) {
            'mysql' => DatabaseQueueSchema::getMySqlEssentialSchema($jobsTable, $failedTable),
            'pgsql' => DatabaseQueueSchema::getPgsqlEssentialSchema($jobsTable, $failedTable),
            'sqlite' => DatabaseQueueSchema::getSqliteEssentialSchema($jobsTable, $failedTable),
            default => DatabaseQueueSchema::getMySqlEssentialSchema($jobsTable, $failedTable),
        };
    }
    
    private function getFullSchema(string $driver): string
    {
        return match ($driver) {
            'mysql' => DatabaseQueueSchema::getMySqlFullSchema(),
            'pgsql' => DatabaseQueueSchema::getPgsqlFullSchema(),
            'sqlite' => DatabaseQueueSchema::getSqliteFullSchema(),
            default => DatabaseQueueSchema::getMySqlFullSchema(),
        };
    }
    
    private function executeStatements(string $sql): void
    {
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (empty($statement)) {
                continue;
            }
            $this->getDatabaseConnection()->exec($statement);
        }
    }
}

trait QueueWorkerValidationTrait
{
    private function validateQueue(string $queue, string $driver, SymfonyStyle $ss, bool $checkDriverExists = true): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $queue)) {
            $ss->error("Invalid queue name: {$queue}");
            return false;
        }
        if (!preg_match('/^[a-z]+$/', $driver)) {
            $ss->error("Invalid driver name: {$driver}");
            return false;
        }
        return true;
    }
}

class ArtisanBackgroundWorker {
    use CommandHelper, QueueCommandHelper;
    
    public function __construct(
        private Container $container,
        private $queueDriver, 
        private $processor, 
        private SymfonyStyle $ss,
    ) {}

    public function work ($queue, $sleep, $memory, $timeout, $maxJobs, $tries, $stopOnEmpty, $daemon, $force, $instance, $pidFile): bool 
    {
        
        if (!$force && file_exists($this->container->config . 'framework/down')) {
            $this->ss->error('Application is in maintenance mode. Use --force to override.');
            return false;
        }

        $workerOptions = [
            'sleep'       => $sleep,
            'memory'      => $memory,
            'timeout'     => $timeout,
            'maxTries'    => $tries,
            'maxJobs'     => $maxJobs,
            'stopOnEmpty' => ($stopOnEmpty === null) 
                                ? filter_var(getenv("QUEUE_WORKER_STOP_ON_EMPTY", false), FILTER_VALIDATE_BOOLEAN) 
                                : $stopOnEmpty,
        ];

        $manager = new BackgroundWorkerManager($this->container);
        $heartbeatInterval = (int) getenv('QUEUE_WORKER_HEARTBEAT_INTERVAL', 60);
        $lastHeartbeat = 0;

        $this->ss->title("Queue Worker");
        $this->ss->text([
            "Queue: <info>{$queue}</info>",
            "Sleep: <info>{$sleep}s</info>",
            "Memory: <info>{$memory}MB</info>",
            "Timeout: <info>{$timeout}s</info>",
            "Max Tries: <info>{$tries}</info>",
            $maxJobs ? "Max Jobs: <info>{$maxJobs}</info>" : "Max Jobs: <info>unlimited</info>",
            $stopOnEmpty ? "Stop on Empty: <info>yes</info>" : "Stop on Empty: <info>no</info>",
            $daemon ? "Mode: <info>daemon</info>" : "Mode: <info>single run</info>",
        ]);

        $signalManager = new SignalManager();
        $worker = null;

        if ($pidFile) {
            file_put_contents($pidFile, getmypid());
            register_shutdown_function(function() use ($pidFile) {
                if (file_exists($pidFile)) unlink($pidFile);
            });
        }

        do {
            $worker = $this->createBaseWorker();
            
            if (extension_loaded('pcntl')) {
                $signalManager->register(fn() => $worker->stop(), [SIGINT, SIGTERM]);
            }

            $this->ss->newLine();
            $this->ss->writeln("Starting worker... Press Ctrl+C to stop.");

            $startTime = time();
            try {
              $this->runWorkerWithHeartbeat($worker, $queue, $workerOptions, $manager, $this->driverName(), $queue, $instance, $heartbeatInterval, $lastHeartbeat);
            } catch (\Throwable $e) {
                $this->ss->error('Worker crashed due to: ' . $e->getMessage());
                return false;
            }
            
            $endTime = time();
            $status  = $worker->getStatus();

            $this->ss->newLine(2);
            $this->ss->section("Worker Statistics");
            $this->ss->text([
                "Runtime: <info>" . ($endTime - $startTime) . "s</info>",
                "Jobs Processed: <info>{$status['processed']}</info>",
                "Jobs Failed: <info>{$status['failed']}</info>",
                "Memory Peak: <info>" . round($status['memory_peak'] / 1024 / 1024, 2) . "MB</info>",
                "Last Job: <info>" . ($status['last_job_at'] ? date('Y-m-d H:i:s', $status['last_job_at']) : 'Never') . "</info>",
            ]);

            if ($daemon && ($status['processed'] ?? 0) === 0) {
                sleep(1);
            }

            if ($daemon && $status['memory_peak'] > $memory * 1024 * 1024) {
                $this->ss->warning("Memory limit exceeded, restarting worker...");
                unset($worker);
            }
        } while ($daemon);

        return true;
    }

    private function runWorkerWithHeartbeat(BaseWorker $worker, string $queue, array $options, BackgroundWorkerManager $manager, string $driver, string $queueName, int $instance, int $interval, int &$lastHeartbeat): void
    {
        if (method_exists($worker, 'setHeartbeatCallback')) {
            $worker->setHeartbeatCallback(function() use ($manager, $driver, $queueName, $instance) {
                $manager->updateHeartbeat($queueName, $driver, $instance);
            });
        }
        $worker->start($queue, $options);
    }
    
}

class QueueWorkerCommand
{
    public static function getCommands(): array
    {
        return [
            // queue:make
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:make');
                    $this->setDescription('Creates a Queue Driver');
                }

                protected function configure(): void {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the queue driver')
                         ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Queue type (database, redis, sync, file, memory)', 'database')
                         ->addOption('config', null, InputOption::VALUE_NONE, 'Create configuration file')
                         ->addOption('register', null, InputOption::VALUE_NONE, 'Register in service providers')
                         ->addOption('command', null, InputOption::VALUE_NONE, 'Register in queue:work command');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);

                        $name = $input->getArgument('name');
                        $options = [
                            'type'     => $input->getOption('type'),
                            'config'   => $input->getOption('config'),
                            'register' => $input->getOption('register'),
                            'command'  => $input->getOption('command'),
                        ];

                        $file = $this->queueGenerator()->generateQueueDriver($name, $options);
                        $ss->success('Queue driver created successfully!');
                        $ss->text(['File: ' . $file, 'Type: ' . $options['type']]);

                        $usage = $this->queueGenerator()->generateCommandUsage($name, $options['type']);
                        $ss->section('Command Line Usage');
                        $ss->text(explode("\n", $usage));

                        return Command::SUCCESS;
                    });
                }
            },

            // queue:init
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:init');
                    $this->setDescription('Initialize all required queue driver files if they do not exist');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);

                        $driverName = $this->driverName();

                        $ss->text("Queue Driver : " . strtoupper($driverName));

                        if (strtolower($driverName) !== "database") {
                            $ss->success('Initialization successfull');
                            return Command::SUCCESS;
                        }
                        
                        $ss->section('Database Setup');
                        try {
                            if (!$this->testDatabaseConnection()) {
                                throw new \RuntimeException('Database connection not available or not PDO');
                            }
                            $this->createFullSchema();
                            $ss->success('Full queue database schema created successfully.');
                        } catch (\Throwable $e) {
                            $ss->error('Failed to create database schema: ' . $e->getMessage());
                            $ss->note('Please check your database configuration in .env and ensure the connection works.');
                            return Command::FAILURE;
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            // queue:work
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
            
                public function __construct() {
                    parent::__construct('queue:work');
                    $this->setDescription('Start processing jobs from the queue');
                }
            
                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name to process', 'default')
                         ->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Seconds to sleep when no jobs are available', 3)
                         ->addOption('memory', 'm', InputOption::VALUE_OPTIONAL, 'Memory limit in MB', 128)
                         ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Job timeout in seconds', 60)
                         ->addOption('max-jobs', null, InputOption::VALUE_OPTIONAL, 'Maximum number of jobs to process before exiting')
                         ->addOption('stop-on-empty', null, InputOption::VALUE_NONE, 'Stop when the queue is empty')
                         ->addOption('tries', null, InputOption::VALUE_OPTIONAL, 'Number of times to attempt a job', 3)
                         ->addOption('daemon', null, InputOption::VALUE_NONE, 'Run the worker in daemon mode')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the worker to run even in maintenance mode')
                          ->addOption('pid-file', null, InputOption::VALUE_OPTIONAL, 'Write process ID to this file')
                          ->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Worker instance number (when managed by supervisor)', 1);
                }
            
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        
                        $queue       = $input->getOption('queue');
                        $sleep       = (int) $input->getOption('sleep');
                        $memory      = (int) $input->getOption('memory');
                        $timeout     = (int) $input->getOption('timeout');
                        $maxJobs     = $input->getOption('max-jobs') ? (int) $input->getOption('max-jobs') : null;
                        $tries       = (int) $input->getOption('tries');
                        $stopOnEmpty = $input->getOption('stop-on-empty');
                        $daemon      = $input->getOption('daemon');
                        $force       = $input->getOption('force');
                        $instance    = (int) $input->getOption('instance');
                        $pidFile     = $input->getOption('pid-file');

                        $artisanBackgroundWorker = new ArtisanBackgroundWorker(
                            $this->artisanContainer(),
                            $this->getQueueDriver(),
                            $this->createJobProcessor(),
                            new SymfonyStyle($input, $output)
                        );

                        $result = $artisanBackgroundWorker->work(
                            $queue, $sleep, $memory, $timeout, $maxJobs, $tries, $stopOnEmpty, $daemon, $force, $instance, $pidFile  
                        );

                        return ($result) ? Command::SUCCESS : Command::FAILURE;
            
                    });
                }
            },
            // queue:supervisor
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:supervisor');
                    $this->setDescription('Run a supervisor that keeps the specified number of workers alive');
                }
                
                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Number of worker instances', 1)
                         ->addOption('daemon', null, InputOption::VALUE_NONE, 'Run as a daemon (keep monitoring forever)');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver      = $this->driverName();
                        $queue       = $input->getOption('queue');
                        $concurrency = (int) $input->getOption('concurrency');
                        $daemon      = $input->getOption('daemon');
                        
                        $manager = new BackgroundWorkerManager($this->artisanContainer());
                        
                        if ($daemon) {
                            $ss->note("Starting supervisor for {$driver}:{$queue} with {$concurrency} workers. Press Ctrl+C to stop.");
                            $manager->monitorWorker($queue, $driver, $concurrency, function($msg) use ($output) {
                                $output->writeln($msg);
                            });
                            return Command::SUCCESS;
                        }
                        
                        // One-time check and start if needed
                        $statuses = $manager->workerStatus($queue, $driver);
                        $running = count(array_filter($statuses, fn($s) => $s['running']));
                        if ($running >= $concurrency) {
                            $ss->success("Already have {$running} worker(s) running.");
                        } else {
                            $needed = $concurrency - $running;
                            $ss->text("Starting {$needed} worker(s)...");
                            $started = $manager->startWorker($queue, $driver, $needed);
                            $ss->success("Started {$started} worker(s).");
                        }
                        
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-start
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-start');
                    $this->setDescription('Start one or more queue workers');
                }
                
                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Number of worker instances to start', 1);
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver      = $this->driverName();
                        $queue       = $input->getOption('queue');
                        $concurrency = (int) $input->getOption('concurrency');
                        
                        $manager = new BackgroundWorkerManager($this->artisanContainer());
                        $started = $manager->startWorker($queue, $driver, $concurrency);
                        
                        if ($started > 0) {
                            $ss->success("Started {$started} worker(s) for {$driver}:{$queue}");
                        } else {
                            $ss->warning("No workers started. They may already be running.");
                        }
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-stop
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-stop');
                    $this->setDescription('Stop queue workers');
                }
                
                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Specific instance number (omit to stop all)');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver   = $this->driverName();
                        $queue    = $input->getOption('queue');
                        $instance = $input->getOption('instance') ? (int) $input->getOption('instance') : null;
                        
                        $manager = new BackgroundWorkerManager($this->artisanContainer());
                        $stopped = $manager->stopWorker($queue, $driver, $instance);
                        
                        if ($stopped === 0) {
                            $ss->warning("No running workers found for {$driver}:{$queue}");
                        } else {
                            $ss->success("Stopped {$stopped} worker(s).");
                        }
                        return Command::SUCCESS;
                    });
                }
            },
            
            // queue:worker-restart
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-restart');
                    $this->setDescription('Restart queue workers');
                }
                
                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Number of workers to keep after restart', 1);
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver      = $this->driverName();
                        $queue       = $input->getOption('queue');
                        $concurrency = (int) $input->getOption('concurrency');
                        
                        $manager = new BackgroundWorkerManager($this->artisanContainer());
                        $manager->stopWorker($queue, $driver);
                        $started = $manager->startWorker($queue, $driver, $concurrency);
                        
                        $ss->success("Restarted {$started} worker(s) for {$driver}:{$queue}");
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-status
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-status');
                    $this->setDescription('Show status of managed workers');
                }
                
                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver = $this->driverName();
                        $queue  = $input->getOption('queue');
                        
                        $manager = new BackgroundWorkerManager($this->artisanContainer());
                        $statuses = $manager->workerStatus($queue, $driver);
                        
                        if (empty($statuses)) {
                            $ss->warning("No workers found for {$driver}:{$queue}");
                            return Command::SUCCESS;
                        }
                        
                        $table = new Table($output);
                        $table->setHeaders(['Instance', 'PID', 'Running', 'Healthy', 'Memory (MB)', 'Last Heartbeat']);
                        foreach ($statuses as $status) {
                            $table->addRow([
                                $status['instance'],
                                $status['pid'] ?? '-',
                                $status['running'] ? 'Yes' : 'No',
                                $status['healthy'] ? 'Yes' : ($status['running'] ? 'No' : '-'),
                                isset($status['memory_mb']) ? round($status['memory_mb'], 2) : '-',
                                isset($status['last_heartbeat']) ? date('Y-m-d H:i:s', $status['last_heartbeat']) : '-',
                            ]);
                        }
                        $table->render();
                        
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-cleanup
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-cleanup');
                    $this->setDescription('Remove orphaned PID files and stale heartbeats');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        $manager = new BackgroundWorkerManager($this->artisanContainer());
                        $manager->cleanupOrphanedPids();
                        $ss->success("Orphaned PID files and stale heartbeats cleaned.");
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:make-job
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:make-job');
                    $this->setDescription('Create a new job class');
                }

                protected function configure(): void {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the job')
                         ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Job type (standard, email, model)', 'standard')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('max-attempts', null, InputOption::VALUE_OPTIONAL, 'Maximum attempts', 3)
                         ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in seconds', 60)
                         ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Default delay in seconds', 0)
                         ->addOption('database', null, InputOption::VALUE_NONE, 'Create database migration for model jobs')
                         ->addOption('command', null, InputOption::VALUE_NONE, 'Register job command');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Create a Job', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->queueGenerator();

                        $name    = $input->getArgument('name');
                        $options = [
                            'type'          => $input->getOption('type'),
                            'queue'         => $input->getOption('queue'),
                            'max_attempts'  => (int) $input->getOption('max-attempts'),
                            'timeout'       => (int) $input->getOption('timeout'),
                            'delay'         => (int) $input->getOption('delay'),
                            'database'      => $input->getOption('database'),
                            'command'       => $input->getOption('command'),
                        ];

                        $file = $generator->generateJob($name, $options);
                        $ss->success('Job created successfully!');
                        $ss->text([
                            'File: ' . $file,
                            'Type: ' . $options['type'],
                            'Queue: ' . $options['queue'],
                            'Max Attempts: ' . $options['max_attempts'],
                            'Timeout: ' . $options['timeout'] . 's',
                        ]);

                        $ss->section('Usage Example');
                        $ss->text([
                            'Dispatch job:',
                            '  $job = new ' . str_replace('Job', '', $name) . 'Job($data);',
                            '  $dispatcher->dispatch($job);',
                            '',
                            'Or use the command:',
                            '  php artisan queue:work --queue=' . $options['queue'],
                        ]);

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:status
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:status');
                    $this->setDescription('Display the status of queues');
                }

                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Specific queue to check', 'all');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        $queueDriver = $this->getQueueDriver();

                        $specificQueue = $input->getOption('queue');

                        if ($specificQueue === 'all') {
                            $queues = $queueDriver->getQueues();
                        } else {
                            $queues = [$specificQueue];
                        }

                        $stats = [];
                        foreach ($queues as $queue) {
                            $stats[] = $queueDriver->getStats($queue);
                        }

                        if (empty($stats)) {
                            $ss->warning('No queues found.');
                        } else {
                            $table = new Table($output);
                            $table->setHeaders(['Pending', 'Reserved', 'Delayed', 'Total', 'Health']);
                            foreach ($stats as $stat) {
                                $table->addRow([
                                    $stat['pending'] ?? 0,
                                    $stat['reserved'] ?? 0,
                                    $stat['delayed'] ?? 0,
                                    $stat['total'] ?? $stat['pending'] + $stat['reserved'] + $stat['delayed'],
                                    $queueDriver->isHealthy() ? '<info>Healthy</info>' : '<error>Unhealthy</error>'
                                ]);
                            }
                            $table->render();
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:failed
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:failed');
                    $this->setDescription('List all failed queue jobs');
                }

                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of failed jobs to display', 50)
                         ->addOption('offset', 'o', InputOption::VALUE_OPTIONAL, 'Offset for failed jobs', 0);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Failed Queue Jobs', function (SymfonyStyle $ss) use ($input, $output) {
                        $queueDriver = $this->getQueueDriver();

                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found after initialization.");
                            return Command::FAILURE;
                        }

                        $queue  = $input->getOption('queue');
                        $limit  = (int) $input->getOption('limit');
                        $offset = (int) $input->getOption('offset');

                        $failedJobs = $queueDriver->getFailed($queue, $limit, $offset);

                        if (empty($failedJobs)) {
                            $ss->success('No failed jobs found.');
                        } else {
                            $table = new Table($output);
                            $table->setHeaders(['No', 'Job ID', 'Queue', 'Failed At', 'Error']);
                            $rows = [];$count = 0;
                            foreach ($failedJobs as $job) {
                                $count++;
                                $rows[] = [
                                    $count,
                                    $job['job_id'] ?? 'N/A',
                                    $job['queue'] ?? 'default',
                                    isset($job['failed_at']) ? date('Y-m-d H:i:s', $job['failed_at']) : 'N/A',
                                    substr($job['exception'] ?? 'No error message', 0, 15) . '...'
                                ];
                            }
                            $table->setRows($rows);
                            $table->render();
                            $ss->newLine();
                            $ss->text("Total failed jobs: " . count($failedJobs));
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:retry
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:retry');
                    $this->setDescription('Retry a failed queue job');
                }

                protected function configure(): void {
                    $this->addArgument('id', InputArgument::OPTIONAL, 'The ID of the failed job (use "all" to retry all)')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Retry Failed Queue Job', function (SymfonyStyle $ss) use ($input, $output) {
                        $queueDriver = $this->getQueueDriver();

                        $jobId = $input->getArgument('id');
                        $queue = $input->getOption('queue');

                        if (!$jobId) {
                            $ss->error('Job ID is required. Use "all" to retry all failed jobs.');
                            return Command::FAILURE;
                        }

                        if ($jobId === 'all') {
                            $failedJobs = $queueDriver->getFailed($queue, 1000, 0);
                            $successCount = 0;
                            $totalCount = count($failedJobs);

                            if ($totalCount === 0) {
                                $ss->success('No failed jobs to retry.');
                                return Command::SUCCESS;
                            }

                            $ss->text("Retrying {$totalCount} failed jobs...");
                            $progressBar = new ProgressBar($output, $totalCount);
                            $progressBar->start();

                            foreach ($failedJobs as $job) {
                                if ($queueDriver->retryFailed($job['id'] ?? '', $queue)) {
                                    $successCount++;
                                }
                                $progressBar->advance();
                            }
                            $progressBar->finish();
                            $ss->newLine(2);

                            if ($successCount === $totalCount) {
                                $ss->success("All {$totalCount} jobs retried successfully.");
                            } else {
                                $ss->warning("Retried {$successCount} out of {$totalCount} jobs.");
                            }
                        } else {
                            if ($queueDriver->retryFailed($jobId, $queue)) {
                                $ss->success("Job {$jobId} retried successfully.");
                            } else {
                                $ss->error("Failed to retry job {$jobId}.");
                                return Command::FAILURE;
                            }
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:forget
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:forget');
                    $this->setDescription('Remove a failed queue job from the failed jobs list');
                }

                protected function configure(): void {
                    $this->addArgument('id', InputArgument::REQUIRED, 'The ID of the failed job')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Remove Failed Queue Job', function (SymfonyStyle $ss) use ($input) {
                        $queueDriver = $this->getQueueDriver();

                        $jobId = $input->getArgument('id');
                        $queue = $input->getOption('queue');

                        if ($queueDriver->forgetFailed($jobId, $queue)) {
                            $ss->success("Job {$jobId} removed from failed jobs list.");
                        } else {
                            $ss->error("Failed to remove job {$jobId}.");
                            return Command::FAILURE;
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:flush
            new class extends Command {
                use CommandHelper, QueueCommandHelper;

                public function __construct() {
                    parent::__construct('queue:flush');
                    $this->setDescription('Flush all failed queue jobs');
                }

                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force flush without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Flush Failed Queue Jobs', function (SymfonyStyle $ss) use ($input) {
                        $queueDriver = $this->getQueueDriver();

                        $queue = $input->getOption('queue');
                        $force = $input->getOption('force');

                        if (!$force) {
                            $confirmed = $ss->confirm(
                                'Are you sure you want to flush all failed jobs? This action cannot be undone.',
                                false
                            );
                            if (!$confirmed) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }

                        $count = $queueDriver->clearFailed($queue);
                        if ($count > 0) {
                            $ss->success("Flushed {$count} failed jobs.");
                        } else {
                            $ss->info('No failed jobs to flush.');
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:clear
            new class extends Command {
                use CommandHelper, QueueCommandHelper;

                public function __construct() {
                    parent::__construct('queue:clear');
                    $this->setDescription('Clear all jobs from a queue');
                }

                protected function configure(): void {
                    $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name to clear', 'default')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force clear without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Clear Queue', function (SymfonyStyle $ss) use ($input) {
                        $queueDriver = $this->getQueueDriver();
                        $queue = $input->getOption('queue');
                        $force = $input->getOption('force');

                        $queueSize = $queueDriver->size($queue);
                        if ($queueSize === 0) {
                            $ss->info("Queue '{$queue}' is already empty.");
                            return Command::SUCCESS;
                        }

                        if (!$force) {
                            $confirmed = $ss->confirm(
                                "Are you sure you want to clear {$queueSize} jobs from queue '{$queue}'? This action cannot be undone.",
                                false
                            );
                            if (!$confirmed) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }

                        $clearedCount = $queueDriver->clear($queue);
                        $ss->success("Cleared {$clearedCount} jobs from queue '{$queue}'.");

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:health
            new class extends Command {
                use CommandHelper, QueueCommandHelper;

                public function __construct() {
                    parent::__construct('queue:health');
                    $this->setDescription('Check the health of queue connections');
                }

                protected function configure(): void {
                    $this->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Timeout for health check in seconds', 5);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Queue Health Check', function (SymfonyStyle $ss) use ($input, $output) {
                        $timeout        = (int) $input->getOption('timeout');

                        $results = [];
                        $queueDriver = $this->getQueueDriver();
                        if (!$queueDriver) {
                            $results[] = [
                                'driver'  => $driverName,
                                'status'  => 'NOT FOUND',
                                'message' => 'Driver not configured'
                            ];
                            return Command::FAILURE;
                        }

                        $startTime = microtime(true);
                        $isHealthy = false;
                        $message   = '';

                        try {
                            if (function_exists('set_time_limit')) {
                                @set_time_limit($timeout);
                            }
                            $isHealthy = $queueDriver->isHealthy();
                            $message   = $isHealthy ? 'Connection successfull' : 'Connection failed';
                        } catch (\Exception $e) {
                            $message = 'Error: ' . $e->getMessage();
                        }

                        $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                        $driverName = $this->driverName();
                        $results[] = [
                            'driver'        => $driverName,
                            'status'        => $isHealthy ? 'HEALTHY' : 'UNHEALTHY',
                            'response_time' => $responseTime . 'ms',
                            'message'       => $message
                        ];

                        $table = new Table($output);
                        $table->setHeaders(['Driver name', 'Status', 'Response Time', 'Message']);
                        $rows = [];
                        foreach ($results as $result) {
                            $statusColor = $result['status'] === 'HEALTHY' ? 'info' : ($result['status'] === 'NOT FOUND' ? 'comment' : 'error');
                            $rows[] = [
                                $result['driver'],
                                "<{$statusColor}>" . $result['status'] . "</{$statusColor}>",
                                $result['response_time'] ?? 'N/A',
                                $result['message']
                            ];
                        }
                        $table->setRows($rows);
                        $table->render();

                        $healthyCount = count(array_filter($results, fn($r) => $r['status'] === 'HEALTHY'));
                        $totalCount   = count($results);

                        $ss->newLine();
                        if ($healthyCount === $totalCount) {
                            $ss->success("{$driverName} queue driver is healthy.");
                        } else {
                            $ss->warning("{$driverName} queue driver is unhealthy.");
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:db:info
            new class extends Command {
                use CommandHelper, QueueCommandHelper;
            
                public function __construct() {
                    parent::__construct('queue:db-info');
                    $this->setDescription('Display current database driver information and test connection');
                }
            
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Driver Info', function (SymfonyStyle $ss) {
                        $connectionCfg = $this->getDbCfg();
                        
                        $ss->section('Database Configuration');
                        foreach($connectionCfg->getConnCfg() as $k => $v) {
                            $ss->writeln($k . " - " . $v);
                        }
            
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:job 
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
            
                public function __construct() {
                    parent::__construct('queue:job');
                    $this->setDescription('Process jobs of a specific type defined in the jobs configuration');
                }
            
                protected function configure(): void {
                    $this->addArgument('job', InputArgument::REQUIRED, 'The job command as defined commands.php')
                         ->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Seconds to sleep when no jobs are available', 3)
                         ->addOption('memory', 'm', InputOption::VALUE_OPTIONAL, 'Memory limit in MB', 128)
                         ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Job timeout in seconds', 60)
                         ->addOption('max-jobs', null, InputOption::VALUE_OPTIONAL, 'Maximum number of jobs to process before exiting')
                         ->addOption('stop-on-empty', null, InputOption::VALUE_NONE, 'Stop when the queue is empty')
                         ->addOption('tries', null, InputOption::VALUE_OPTIONAL, 'Number of times to attempt a job', 3)
                         ->addOption('daemon', null, InputOption::VALUE_NONE, 'Run the worker in daemon mode')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the worker to run even in maintenance mode')
                         ->addOption('pid-file', null, InputOption::VALUE_OPTIONAL, 'Write process ID to this file')
                         ->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Worker instance number (when managed by supervisor)', 1);
                }
            
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);

                        $jobName     = strtolower($input->getArgument('job'));
                        $sleep       = (int) $input->getOption('sleep');
                        $memory      = (int) $input->getOption('memory');
                        $timeout     = (int) $input->getOption('timeout');
                        $maxJobs     = $input->getOption('max-jobs') ? (int) $input->getOption('max-jobs') : null;
                        $tries       = (int) $input->getOption('tries');
                        $stopOnEmpty = $input->getOption('stop-on-empty');
                        $daemon      = $input->getOption('daemon');
                        $force       = $input->getOption('force');
                        $instance    = (int) $input->getOption('instance');
                        $pidFile     = $input->getOption('pid-file');

                        $jobs = $this->getJobCommands($ss);
                        if (!$jobs) {
                            return Command::FAILURE;
                        }

                        if (!isset($jobs[$jobName])) {
                            $ss->error("Job '{$jobName}' not found in jobs configuration.");
                            $ss->writeln("Available jobs: " . implode(', ', array_keys($jobs)));
                            return Command::FAILURE;
                        }

                        $jobConfig = $jobs[$jobName];
                        $enabled = $jobConfig['enabled'] ?? false;
                        if (!$enabled) {
                            $ss->error("Command '{$jobName}' is currently not enabled");
                            return Command::FAILURE;
                        }
                        
                        $queue = $jobConfig['queue'] ?? 'default';

                        $artisanBackgroundWorker = new ArtisanBackgroundWorker(
                            $this->artisanContainer(),
                            $this->getQueueDriver(),
                            $this->createJobProcessor(),
                            $ss
                        );

                        $result = $artisanBackgroundWorker->work(
                            $queue, $sleep, $memory, $timeout, $maxJobs, $tries, $stopOnEmpty, $daemon, $force, $instance, $pidFile  
                        );

                        return ($result) ? Command::SUCCESS : Command::FAILURE;
            
                    });
                }

                private function getJobCommands(SymfonyStyle $ss): array|false 
                {
                    $path = $this->artisanContainer()->config . 'commands.php';
                    if (!is_file($path)) {
                        $ss->error("Job Command configuration does not exists");
                        return false;
                    }
                    
                    $config = require $path;
                    if (!is_array($config) || !isset($config['jobs'])) {
                        $ss->error("Could not find commands in Command configurations");
                        return false;
                    }

                    return $config['jobs'];
                }
            },
        ];
    }
}