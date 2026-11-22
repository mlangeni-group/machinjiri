<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal;

use Symfony\Component\Console\Application;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\{
    App,
    DatabaseCommands,
    NotificationCommand,
    Network,
    PHPServerCommands,
    QueueWorkerCommand,
    ServiceProviderCommand,
    TaskSchedulerCommand,
    ViewCommands,
    WebhookCommand
};

class Terminal extends Application
{
    public const TERMINAL_VERSION = '2.1';

    /** @var list<class-string> */
    protected $commandClasses = [
        App::class,
        PHPServerCommands::class,
        ServiceProviderCommand::class,
        QueueWorkerCommand::class,
        TaskSchedulerCommand::class,
        ViewCommands::class,
        WebhookCommand::class,
        Network::class,
        DatabaseCommands::class,
        NotificationCommand::class,
    ];

    public function __construct()
    {
        parent::__construct('Machinjiri Terminal', self::TERMINAL_VERSION);
        $this->registerCommands();
    }

    protected function registerCommands(): void
    {
        foreach ($this->commandClasses as $commandClass) {
            $this->registerCommandsFromClass($commandClass);
        }
    }

    public function addCommandClass(string $commandClass): void
    {
        if (in_array($commandClass, $this->commandClasses, true)) {
            return;
        }

        $this->registerCommandsFromClass($commandClass);
        $this->commandClasses[] = $commandClass;
    }

    private function registerCommandsFromClass(string $commandClass): void
    {
        if (!class_exists($commandClass) || !is_callable([$commandClass, 'getCommands'])) {
            throw new \InvalidArgumentException(sprintf(
                'Command provider "%s" must be an existing class with a public static getCommands() method.',
                $commandClass
            ));
        }

        $commands = $commandClass::getCommands();
        if (!is_array($commands)) {
            throw new \UnexpectedValueException(sprintf(
                'Command provider "%s" must return an array of Symfony Console commands.',
                $commandClass
            ));
        }

        foreach ($commands as $command) {
            if (!$command instanceof \Symfony\Component\Console\Command\Command) {
                throw new \UnexpectedValueException(sprintf(
                    'Command provider "%s" returned a value that is not a Symfony Console command.',
                    $commandClass
                ));
            }
        }

        foreach ($commands as $command) {
            $this->add($command);
        }
    }
}