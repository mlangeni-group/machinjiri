<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Database\Utility;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\CommandHelper;
use Mlangeni\Machinjiri\Core\Container;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DatabaseBackupCommands
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('db:backup');
                    $this->setDescription('Back up the configured database');
                }

                protected function configure(): void
                {
                    $this->addArgument('path', InputArgument::OPTIONAL, 'Backup destination (defaults to storage/database-backups)');
                    $this->addOption('queue', null, InputOption::VALUE_NONE, 'Queue the backup job');
                    $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Replace an existing backup file');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Backup Utility', function (SymfonyStyle $io) use ($input): int {
                        $app = $this->artisanContainer();
                        $path = $input->getArgument('path');

                        if ($input->getOption('queue')) {
                            $dispatcher = $this->dispatcher($app);
                            $job = new DatabaseBackupJob($app, [
                                'path' => $path,
                                'overwrite' => (bool) $input->getOption('overwrite'),
                            ]);
                            $jobId = $dispatcher->dispatch($job);
                            $io->success("Database backup queued.");
                            $io->definitionList(["Job Id" => $jobId]);
                            return Command::SUCCESS;
                        }

                        $backupPath = DatabaseBackupManager::fromContainer($app)->backup(
                            $path,
                            (bool) $input->getOption('overwrite')
                        );
                        $io->success("Database backup completed successfully");
                        $io->definitionList(["Path" => $backupPath]);
                        return Command::SUCCESS;
                    });
                }

                private function dispatcher(Container $app): JobDispatcherInterface
                {
                    if (!$app->bound('queue.dispatcher')) {
                        throw new RuntimeException('queue.dispatcher is not bound; configure the queue provider to use --queue.');
                    }

                    $dispatcher = $app->resolve('queue.dispatcher');
                    if (!$dispatcher instanceof JobDispatcherInterface) {
                        throw new RuntimeException('queue.dispatcher must implement JobDispatcherInterface.');
                    }

                    return $dispatcher;
                }
            },
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('db:restore');
                    $this->setDescription('Restore the configured database from a backup');
                }

                protected function configure(): void
                {
                    $this->addArgument('path', InputArgument::REQUIRED, 'Backup file to restore');
                    $this->addOption('queue', null, InputOption::VALUE_NONE, 'Queue the restore job');
                    $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the destructive-operation confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Restore Utility', function (SymfonyStyle $io) use ($input): int {
                        $path = $input->getArgument('path');
                        $filename = basename($path);
                        if (!$input->getOption('force') && !$io->confirm(
                            "Restoring '{$filename}' will replace data in the configured database. Continue?",
                            false
                        )) {
                            $io->warning('Database restore cancelled.');
                            return Command::FAILURE;
                        }

                        $app = $this->artisanContainer();
                        if ($input->getOption('queue')) {
                            if (!$app->bound('queue.dispatcher')) {
                                throw new RuntimeException('queue.dispatcher is not bound; configure the queue provider to use --queue.');
                            }
                            $dispatcher = $app->resolve('queue.dispatcher');
                            if (!$dispatcher instanceof JobDispatcherInterface) {
                                throw new RuntimeException('queue.dispatcher must implement JobDispatcherInterface.');
                            }

                            $jobId = $dispatcher->dispatch(new DatabaseRestoreJob($app, ['path' => $path]));
                            $io->success("Database restore queued.");
                            $io->definitionList(["Job Id" => $jobId, "Path" => $path]);
                            return Command::SUCCESS;
                        }

                        DatabaseBackupManager::fromContainer($app)->restore($path);
                        $io->success('Database restore completed.');
                        $io->definitionList(["Path" => $path]);
                        return Command::SUCCESS;
                    });
                }
            },
        ];
    }
}
