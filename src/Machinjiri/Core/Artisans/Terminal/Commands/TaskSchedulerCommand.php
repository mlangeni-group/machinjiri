<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Components\Task\TaskManager;
use Mlangeni\Machinjiri\Core\Components\Task\TaskScheduler;
use Mlangeni\Machinjiri\Core\Artisans\Generators\TaskGenerator;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

// TaskScheduler Command
class TaskSchedulerCommand 
{
    public static function getCommands(): array 
    {
        return [
            // List tasks command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:list');
                    $this->setDescription('List all scheduled tasks');
                    $this->setHelp('Display a list of all registered scheduled tasks.');
                }

                protected function configure(): void
                {
                    $this->addOption('enabled', 'e', InputOption::VALUE_NONE, 'Show only enabled tasks')
                         ->addOption('group', 'g', InputOption::VALUE_OPTIONAL, 'Filter by task group');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - List', function (SymfonyStyle $io) use ($input) {
                        $container = $this->artisanContainer();
                        $taskManager = $container->resolve(TaskManager::class);
                        $onlyEnabled = $input->getOption('enabled');
                        $group = $input->getOption('group');
                        $tasks = $taskManager->listTasks($onlyEnabled);
                        
                        // Filter by group if specified
                        if ($group) {
                            $tasks = array_filter($tasks, function($task) use ($group) {
                                $options = json_decode($task['options'] ?? '{}', true);
                                return ($options['group'] ?? 'default') === $group;
                            });
                        }

                        if (count($tasks) === 0) {
                            $io->note('No scheduled tasks found.');
                            return Command::SUCCESS;
                        }

                        $rows = [];
                        foreach ($tasks as $task) {
                            $options = json_decode($task['options'] ?? '{}', true);
                            $rows[] = [
                                $task['id'],
                                $task['name'],
                                $task['cron_expression'],
                                $options['priority'] ?? 0,
                                $options['group'] ?? 'default',
                                $task['enabled'] ? 'Yes' : 'No',
                                $task['next_run'] ? date('Y-m-d H:i:s', $task['next_run']) : 'N/A',
                            ];
                        }
                
                        $io->section('Scheduled Tasks');
                        $io->table(['ID', 'Name', 'Cron', 'Priority', 'Group', 'Enabled', 'Next Run'], $rows);
                        $io->comment(sprintf('Total: %d task(s)', count($tasks)));
                        return Command::SUCCESS;
                    });
                }
            },
            
            // Create task command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:create-task');
                    $this->setDescription('Create a new scheduled task');
                    $this->setHelp('Create a new task class in the Scheduler/Tasks directory.');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The class name of the task (e.g., SendReport)')
                         ->addOption('register', 'r', InputOption::VALUE_NONE, 'Register task in configuration')
                         ->addOption('cron', 'c', InputOption::VALUE_OPTIONAL, 'Cron expression', '0 * * * *')
                         ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Task type (standard, simple)', 'standard');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Create Task', function (SymfonyStyle $io) use ($input) {
                        $taskGenerator = new TaskGenerator($this->artisanContainer());
                        $taskName = $input->getArgument('name');
                        $register = $input->getOption('register');
                        $cron = $input->getOption('cron');
                        $type = $input->getOption('type');
                        
                        try {
                            $taskFile = $taskGenerator->generateTask($taskName, $type, $register, $cron);
                            $io->success('Task created successfully.');
                            $io->definitionList(
                                ['Task file' => $taskFile],
                                ['Type' => $type],
                                ['Cron' => $cron],
                                ['Registered' => $register ? 'Yes' : 'No']
                            );
                            
                            if (!$register) {
                                $io->note("Task was not registered. Run with --register to add it to configuration.");
                            }
                            
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to create task: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },
            
            // Run scheduler command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:run');
                    $this->setDescription('Run all due scheduled tasks');
                    $this->setHelp('Execute all scheduled tasks that are due to run.');
                }

                protected function configure(): void
                {
                    $this->addOption('task', 't', InputOption::VALUE_OPTIONAL, 'Run a specific task by ID')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force run even if disabled');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Run', function (SymfonyStyle $io) use ($input) {
                        $container = $this->artisanContainer();
                        $scheduler = $container->resolve(TaskScheduler::class);
                        $taskId = $input->getOption('task');
                        $force = $input->getOption('force');
                        
                        try {
                            if ($taskId) {
                                if ($force) {
                                    // Enable task temporarily
                                    $taskManager = $container->resolve(TaskManager::class);
                                    $taskManager->setTaskEnabled((int)$taskId, true);
                                }
                                $scheduler->runTask((int) $taskId);
                                $io->success("Task {$taskId} executed successfully");
                            } else {
                                $scheduler->run();
                                $io->success("All due tasks executed");
                            }
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to run tasks: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },
            
            // Status command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:status');
                    $this->setDescription('Show scheduler status');
                    $this->setHelp('Display detailed information about the scheduler.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Status', function (SymfonyStyle $io) {
                        $container = $this->artisanContainer();
                        $scheduler = $container->resolve(TaskScheduler::class);
                        $status = $scheduler->getStatus();
                        
                        $io->section('Scheduler Status');
                        $io->definitionList(
                            ['Status' => $status['is_running'] ? 'Running' : 'Idle'],
                            ['Total tasks' => $status['total_tasks']],
                            ['Enabled tasks' => $status['enabled_tasks']],
                            ['Due tasks' => $status['due_tasks']],
                            ['Active locks' => $status['active_locks']],
                            ['Default queue' => $status['default_queue']],
                            ['Memory usage' => $this->formatBytes($status['memory_usage'])],
                            ['Peak memory' => $this->formatBytes($status['peak_memory'])]
                        );
                        
                        return Command::SUCCESS;
                    });
                }
                
                protected function formatBytes(int $bytes): string
                {
                    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                    $i = 0;
                    while ($bytes >= 1024 && $i < count($units) - 1) {
                        $bytes /= 1024;
                        $i++;
                    }
                    return round($bytes, 2) . ' ' . $units[$i];
                }
            },
            
            // Enable task command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:enable');
                    $this->setDescription('Enable a scheduled task');
                }

                protected function configure(): void
                {
                    $this->addArgument('id', InputArgument::REQUIRED, 'The task ID to enable');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Enable', function (SymfonyStyle $io) use ($input) {
                        $container = $this->artisanContainer();
                        $taskManager = $container->resolve(TaskManager::class);
                        $taskId = (int) $input->getArgument('id');
                        
                        try {
                            $taskManager->setTaskEnabled($taskId, true);
                            $io->success("Task {$taskId} enabled successfully");
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to enable task: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },
            
            // Disable task command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:disable');
                    $this->setDescription('Disable a scheduled task');
                }

                protected function configure(): void
                {
                    $this->addArgument('id', InputArgument::REQUIRED, 'The task ID to disable');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Disable', function (SymfonyStyle $io) use ($input) {
                        $container = $this->artisanContainer();
                        $taskManager = $container->resolve(TaskManager::class);
                        $taskId = (int) $input->getArgument('id');
                        
                        try {
                            $taskManager->setTaskEnabled($taskId, false);
                            $io->success("Task {$taskId} disabled successfully");
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to disable task: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },
            
            // Clean history command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:clean');
                    $this->setDescription('Clean old task execution history');
                }

                protected function configure(): void
                {
                    $this->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Days of history to keep', 30);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Clean History', function (SymfonyStyle $io) use ($input) {
                        $container = $this->artisanContainer();
                        $scheduler = $container->resolve(TaskScheduler::class);
                        $days = (int) $input->getOption('days');
                        
                        try {
                            $scheduler->cleanHistory($days);
                            $io->success("Cleaned execution history older than {$days} days");
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to clean history: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },
            
            // Stats command
            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:stats');
                    $this->setDescription('Show task statistics');
                }

                protected function configure(): void
                {
                    $this->addArgument('id', InputArgument::OPTIONAL, 'Task ID to show stats for');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Task Scheduler - Statistics', function (SymfonyStyle $io) use ($input) {
                        $container = $this->artisanContainer();
                        $taskManager = $container->resolve(TaskManager::class);
                        $taskId = $input->getArgument('id');
                        
                        if ($taskId) {
                            $stats = $taskManager->getTaskStats((int)$taskId);
                            $task = $taskManager->getTask((int)$taskId);
                            
                            $io->section("Statistics: {$task['name']}");
                            $io->definitionList(
                                ['Total executions' => $stats['total_executions']],
                                ['Successful' => $stats['success_count']],
                                ['Failed' => $stats['failed_count']],
                                ['Success rate' => number_format($stats['success_rate'], 2) . '%'],
                                ['Average duration' => number_format($stats['average_duration'], 2) . 's'],
                                ['Last execution' => $stats['last_execution_time'] ? date('Y-m-d H:i:s', $stats['last_execution_time']) : 'Never']
                            );
                        } else {
                            $allStats = $taskManager->getAllStats();
                            if (empty($allStats)) {
                                $io->note('No task statistics available.');
                                return Command::SUCCESS;
                            }

                            $rows = [];
                            foreach ($allStats as $taskId => $stats) {
                                $task = $taskManager->getTask($taskId);
                                $rows[] = [
                                    $taskId,
                                    $task['name'],
                                    $stats['total_executions'],
                                    $stats['success_count'],
                                    $stats['failed_count'],
                                    number_format($stats['success_rate'], 2) . '%',
                                    number_format($stats['average_duration'], 2) . 's',
                                ];
                            }
                            
                            $io->section('Task Statistics');
                            $io->table(['ID', 'Name', 'Total', 'Success', 'Failed', 'Rate', 'Avg Duration'], $rows);
                        }
                        
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:cache-preload');
                    $this->setDescription('Preload scheduler data into cache');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Preloading Scheduler Cache', function (SymfonyStyle $io) {
                        $container = $this->artisanContainer();
                        $scheduler = $container->resolve(TaskScheduler::class);
                        
                        try {
                            $scheduler->preloadCache();
                            $io->success("Scheduler cache preloaded successfully");
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to preload cache: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper;
                
                public function __construct()
                {
                    parent::__construct('scheduler:cache-clear');
                    $this->setDescription('Clear scheduler cache');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Clearing Scheduler Cache', function (SymfonyStyle $io) {
                        $container = $this->artisanContainer();
                        $scheduler = $container->resolve(TaskScheduler::class);
                        
                        try {
                            $scheduler->clearCache();
                            $io->success("Scheduler cache cleared successfully");
                            return Command::SUCCESS;
                        } catch (MachinjiriException  $e) {
                            $io->error("Failed to clear cache: " . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }
            },
        ];
    }
}
