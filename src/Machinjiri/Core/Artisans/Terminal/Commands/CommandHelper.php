<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Mlangeni\Machinjiri\Core\Artisans\Terminal\Terminal;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\Factory as EventListener;

trait CommandHelper
{
    /** @var TERMINAL_VERSION */
    public const TERMINAL_VERSION = "2.0";

    /**
     * Execute a command with standardised setup and exception handling.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string          $title   Title for the console output.
     * @param callable        $handler Callback(SymfonyStyle $ss): int
     *
     * @return int Command exit code.
     */
    protected function executeWithStyle(
        InputInterface $input,
        OutputInterface $output,
        string $title,
        callable $handler
    ): int {
        $version = Terminal::TERMINAL_VERSION;
        $ss = new SymfonyStyle($input, $output);
        $ss->title("Machinjiri Terminal v{$version} => [{$title}]");
        $context = ['module' => $title, 'handler' => $handler];

        try {
            EventListener::artisan('command.execute.success', $context);
            return $handler($ss);
        } catch (\Throwable $e) {
            EventListener::artisan('command.execute.failed', array_merge($context, ['error' => $e->getMessage()]));
            $ss->error('Error: ' . $e->getMessage());

            if ($output->isVerbose()) {
                $ss->text('Trace: ' . $e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    protected function resolveArtisanBootstrapFile(): ?string
    {
        $cwd = getcwd();
        $candidate = $cwd . '/bootstrap/app.php';
        if (file_exists($candidate)) {
            return $candidate;
        }
        
        // Walk upwards from cwd
        $dir = $cwd;
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir . '/bootstrap/app.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        
        return null;
    }

    public function artisanContainer(): Container
    {
        $bootstrapFile = $this->resolveArtisanBootstrapFile();
        if (!is_file($bootstrapFile) || $bootstrapFile === null) {
            throw new MachinjiriException('Could not find artisan bootstrap file.', 1200);
        }

        $container = require $bootstrapFile;
        if (!Container::instancePresent()) {
            Container::setInstance($container);
        }
        return Container::getInstance();
    }

}