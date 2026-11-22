<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\PHPServerManager;

class PHPServerCommands
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('run:dev');
                    $this->setDescription('Start a developmental Server for Machinjiri');
                }

                protected function configure(): void
                {
                    $this->addOption("port", null, InputOption::VALUE_OPTIONAL, 'Port Number. In case current port is occupied');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Development Server', function (SymfonyStyle $ss) use ($input) {
                        $userPort = $input->getOption('port');
                        $port = ($userPort !== null) ? $userPort : 3000;
                        $dir = getcwd() . '/storage/dev-server/';
                        $options = [];
                        if (is_dir($dir)) {
                            $options['log_file'] = $dir . 'dev_server_' . date('Y_m_d') . '.log';
                        }

                        $serverMgr = new PHPServerManager($port, $options);
                        $result = $serverMgr->start();

                        if (isset($result['success']) && $result['success']) {
                            $ss->success('Development server started');
                            $ss->section('Server information');
                            $ss->definitionList(
                                ['Details' => $result['message']],
                                ['PID' => $result['pid']],
                                ['Address' => $result['address']],
                                ['Document root' => $result['document_root']],
                                ['URL' => 'http://' . $result['address'] . '/']
                            );
                            return Command::SUCCESS;
                        } else {
                            $ss->error($result['message']);
                            $ss->section('Available commands');
                            $ss->table(
                                ['Command', 'Action'],
                                [
                                    ['php artisan server:stop', 'Stop the server'],
                                    ['php artisan server:restart', 'Restart the server'],
                                ]
                            );
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('stop:dev');
                    $this->setDescription('Stop a developmental Server for Machinjiri');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Development Server', function (SymfonyStyle $ss) {
                        $serverMgr = new PHPServerManager();
                        $result = $serverMgr->stop();

                        if (isset($result['success']) && $result['success']) {
                            $ss->success($result['message']);
                            return Command::SUCCESS;
                        } else {
                            $ss->error($result['message']);
                            return Command::FAILURE;
                        }
                    });
                }
            },

        ];
    }
}