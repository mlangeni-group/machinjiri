<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Mlangeni\Machinjiri\Core\Artisans\Terminal\Terminal;
use Mlangeni\Machinjiri\Core\Artisans\Events\Factory as EventListener;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

trait CommandHelper
{

    /**
     * Execute a command with standardised setup and exception handling.
     *
     * @param callable(SymfonyStyle): int $handler
     *
     * @return int The command exit code.
     */
    protected function executeWithStyle(
        InputInterface $input,
        OutputInterface $output,
        string $title,
        callable $handler
    ): int {
        $version = Terminal::TERMINAL_VERSION;
        $io = new SymfonyStyle($input, $output);
        $io->title($title);
        $io->text(sprintf('Machinjiri Terminal v%s', $version));
        $context = [
            'module' => $title,
            'command' => $this->getName(),
        ];

        try {
            $exitCode = $handler($io);
            if (!is_int($exitCode)) {
                throw new \UnexpectedValueException('Command handler must return an integer exit code.');
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            if ($output->isVerbose()) {
                $io->section('Exception trace');
                $io->writeln($e->getTraceAsString());
            }

            EventListener::artisan('command.execute.failed', array_merge($context, [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]), 'error');

            return Command::FAILURE;
        }

        $event = $exitCode === Command::SUCCESS
            ? 'command.execute.success'
            : 'command.execute.failed';
        $level = $exitCode === Command::SUCCESS ? 'info' : 'warning';
        EventListener::artisan($event, array_merge($context, ['exit_code' => $exitCode]), $level);

        return $exitCode;
    }

    protected function resolveArtisanBootstrapFile(): ?string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }

        do {
            $candidate = $cwd . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
            if (is_file($candidate)) {
                return $candidate;
            }

            $parent = dirname($cwd);
            if ($parent === $cwd) {
                break;
            }
            $cwd = $parent;
        } while (true);

        return null;
    }

    public function artisanContainer(): Container
    {
        if (Container::instancePresent()) {
            return Container::getInstance();
        }

        $bootstrapFile = $this->resolveArtisanBootstrapFile();
        if ($bootstrapFile === null || !is_file($bootstrapFile)) {
            throw new MachinjiriException('Could not find artisan bootstrap file.', 1200);
        }

        $container = require $bootstrapFile;
        if (!$container instanceof Container) {
            throw new MachinjiriException(
                sprintf('Artisan bootstrap file "%s" did not return a Container instance.', $bootstrapFile),
                1200
            );
        }

        Container::setInstance($container);

        return $container;
    }
}