<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal;

use Symfony\Component\Console\Application;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\{
    App,
    PHPServerCommands,
    ServiceProviderCommand,
    QueueWorkerCommand,
    ViewCommands,
    WebhookCommand,
    Network,
    DatabaseCommands,
    NotificationCommand,
    CommandHelper
};

class Terminal extends Application
{
    /** @var TERMINAL_VERSION */
    public const TERMINAL_VERSION = "2.0";
    
    protected $commandClasses = [
        App::class,
        PHPServerCommands::class,
        ServiceProviderCommand::class,
        QueueWorkerCommand::class,
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
            $commands = $commandClass::getCommands();
            foreach ($commands as $command) {
                $this->add($command);
            }
        }
    }

    public function addCommandClass(string $commandClass): void
    {
        if (!in_array($commandClass, $this->commandClasses)) {
            $this->commandClasses[] = $commandClass;
            $commands = $commandClass::getCommands();
            foreach ($commands as $command) {
                $this->add($command);
            }
        }
    }
}