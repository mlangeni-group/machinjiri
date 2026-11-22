<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class TestCommand
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('test');
                    $this->setDescription('Run tests with optional parallel execution, coverage, and watch mode');
                }

                protected function configure(): void
                {
                    $this->addOption('parallel', 'p', InputOption::VALUE_NONE, 'Run tests in parallel using Paratest');
                    $this->addOption('coverage', 'c', InputOption::VALUE_NONE, 'Generate a code coverage report');
                    $this->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Run tests from a specific group');
                    $this->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Filter tests by name');
                    $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for file changes and rerun tests');
                    $this->addOption('testsuite', 's', InputOption::VALUE_REQUIRED, 'Run a specific test suite');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Test runner', function (SymfonyStyle $io) use ($input): int {
                        if ($input->getOption('watch')) {
                            $io->note('Watch mode is not implemented yet. Use an external tool such as entr or nodemon.');
                            return Command::SUCCESS;
                        }

                        $command = $input->getOption('parallel')
                            ? $this->getParallelCommand($input)
                            : $this->getStandardCommand($input);

                        $io->section('Test execution');
                        $io->definitionList(['Command' => implode(' ', $command)]);

                        $process = new Process($command);
                        $process->setTty(true);
                        $process->setTimeout(null);
                        $process->run(static function (string $type, string $buffer) use ($io): void {
                            $io->write($buffer);
                        });

                        if ($process->getExitCode() === 0) {
                            $io->success('Tests passed');
                            return Command::SUCCESS;
                        }

                        $io->error('Tests failed');
                        return Command::FAILURE;
                    });
                }

                private function getStandardCommand(InputInterface $input): array
                {
                    $command = [PHP_BINARY, 'vendor/bin/phpunit'];

                    if ($input->getOption('coverage')) {
                        $command[] = '--coverage-html=build/coverage';
                    }

                    if ($group = $input->getOption('group')) {
                        $command[] = "--group={$group}";
                    }

                    if ($filter = $input->getOption('filter')) {
                        $command[] = "--filter={$filter}";
                    }

                    if ($suite = $input->getOption('testsuite')) {
                        $command[] = "--testsuite={$suite}";
                    }

                    return $command;
                }

                private function getParallelCommand(InputInterface $input): array
                {
                    $command = [PHP_BINARY, 'vendor/bin/paratest', '--processes=auto'];

                    if ($input->getOption('coverage')) {
                        $command[] = '--coverage-html=build/coverage';
                    }

                    if ($group = $input->getOption('group')) {
                        $command[] = "--group={$group}";
                    }

                    if ($filter = $input->getOption('filter')) {
                        $command[] = "--filter={$filter}";
                    }

                    if ($suite = $input->getOption('testsuite')) {
                        $command[] = "--testsuite={$suite}";
                    }

                    return $command;
                }
            },
        ];
    }
}
