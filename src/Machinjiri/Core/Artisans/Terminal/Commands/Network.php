<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Components\Network\Tools\{Manager, NetworkConfig};
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

trait NetworkTrait 
{
    use CommandHelper;
    
    protected function networkManager(): Manager 
    {
        $container = $this->artisanContainer();
        if (!$container->bound("network.manager")) {
            throw new MachinjiriException("Network Manager not bound in AppServiceProvider", 1201);
        }
        return $container->resolve("network.manager");
    }

}

class Network
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper, NetworkTrait;

                public function __construct()
                {
                    parent::__construct('network:ping');
                    $this->setDescription('Ping a host or IP address.');
                }

                protected function configure(): void
                {
                    $this->addArgument('host', InputArgument::REQUIRED, 'Hostname or IP address to ping.');
                    $this->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Timeout in seconds.', 2);
                    $this->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of pings (currently only one).', 1);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Network Ping', function (SymfonyStyle $ss) use ($input) {
                        $host = $input->getArgument('host');
                        $timeout = (int) $input->getOption('timeout');

                        $manager = $this->networkManager();
                        $reachable = $manager->ping($host, $timeout);

                        if ($reachable) {
                            $ss->success("Host {$host} is reachable.");
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Host {$host} is unreachable.");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper, NetworkTrait;

                public function __construct()
                {
                    parent::__construct('network:scan');
                    $this->setDescription('Scan a subnet for active hosts (ICMP ping).');
                }

                protected function configure(): void
                {
                    $this->addArgument('subnet', InputArgument::REQUIRED, 'Subnet in CIDR notation (e.g., 192.168.1.0/24).');
                    $this->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Ping timeout in seconds.', 2);
                    $this->addOption('concurrent', 'c', InputOption::VALUE_OPTIONAL, 'Number of concurrent pings.', 20);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Subnet Scan', function (SymfonyStyle $ss) use ($input) {
                        $subnet = $input->getArgument('subnet');
                        $timeout = (int) $input->getOption('timeout');
                        $concurrent = (int) $input->getOption('concurrent');

                        $manager = $this->networkManager();
                        // Update config temporarily
                        $config = $this->artisanContainer()->resolve("network.config");
                        if ($config instanceof NetworkConfig) {
                            $config->set('ping_timeout', $timeout);
                            $config->set('concurrent_pings', $concurrent);
                        }

                        $ss->note("Scanning subnet {$subnet}...");
                        $active = $manager->scanSubnet($subnet);

                        if (empty($active)) {
                            $ss->warning("No active hosts found.");
                        } else {
                            $ss->section('Active Hosts');
                            $ss->table(['Host', 'Status'], array_map(
                                static fn ($host) => [$host, 'Active'],
                                $active
                            ));
                            $ss->success('Found ' . count($active) . ' active host(s).');
                        }

                        return Command::SUCCESS;
                    });
                }

            },

            new class extends Command {
                use CommandHelper, NetworkTrait;

                public function __construct()
                {
                    parent::__construct('network:ports');
                    $this->setDescription('Scan ports on a host.');
                }

                protected function configure(): void
                {
                    $this->addArgument('host', InputArgument::REQUIRED, 'Hostname or IP address.');
                    $this->addArgument('start', InputArgument::OPTIONAL, 'Start port.', 1);
                    $this->addArgument('end', InputArgument::OPTIONAL, 'End port.', 1024);
                    $this->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Connection timeout in seconds.', 2);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Network Port Scan', function (SymfonyStyle $ss) use ($input) {
                        $host = $input->getArgument('host');
                        $start = (int) $input->getArgument('start');
                        $end = (int) $input->getArgument('end');
                        $timeout = (int) $input->getOption('timeout');

                        if ($start > $end) {
                            $ss->error("Start port ({$start}) must be less than or equal to end port ({$end}).");
                            return Command::FAILURE;
                        }

                        $manager = $this->networkManager();
                        $ss->note("Scanning ports {$start}-{$end} on {$host}...");
                        $open = $manager->scanPorts($host, $start, $end, $timeout);

                        if (empty($open)) {
                            $ss->warning("No open ports found.");
                        } else {
                            $rows = array_map(function ($p) {
                                return [$p['port'], $p['service']];
                            }, $open);
                            $ss->section('Open Ports');
                            $ss->table(['Port', 'Service'], $rows);
                            $ss->success("Found " . count($open) . " open port(s).");
                        }

                        return Command::SUCCESS;
                    });
                }

            },

            new class extends Command {
                use CommandHelper, NetworkTrait;

                public function __construct()
                {
                    parent::__construct('network:interfaces');
                    $this->setDescription('List local network interfaces.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Network Interfaces', function (SymfonyStyle $ss) {
                        $manager = $this->networkManager();
                        $interfaces = $manager->getLocalInterfaces();

                        if (empty($interfaces)) {
                            $ss->warning("No network interfaces found.");
                            return Command::SUCCESS;
                        }

                        $rows = [];
                        foreach ($interfaces as $iface) {
                            $rows[] = [
                                $iface->getName(),
                                $iface->getIp(),
                                $iface->getMac(),
                                $iface->getNetworkAddress(),
                                $iface->isUp() ? 'UP' : 'DOWN',
                                $iface->isLoopback() ? 'Yes' : 'No',
                            ];
                        }
                        $ss->section('Network Interfaces');
                        $ss->table(['Name', 'IP', 'MAC', 'Network', 'Status', 'Loopback'], $rows);
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, NetworkTrait;

                public function __construct()
                {
                    parent::__construct('network:monitor');
                    $this->setDescription('Run a single check on all monitored hosts.');
                }

                protected function configure(): void
                {
                    $this->addOption('add', 'a', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add hosts to monitor (format: host or alias:host).');
                    $this->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Ping timeout in seconds.', 2);
                    $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Continuously watch (runs every 60s).');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Network Monitor', function (SymfonyStyle $ss) use ($input) {
                        $manager = $this->networkManager();
                        $timeout = (int) $input->getOption('timeout');

                        // Add hosts if provided
                        $addList = $input->getOption('add');
                        foreach ($addList as $entry) {
                            if (strpos($entry, ':') !== false) {
                                [$alias, $host] = explode(':', $entry, 2);
                                $manager->monitorHost($host, $alias);
                            } else {
                                $manager->monitorHost($entry);
                            }
                        }

                        $summary = $manager->getMonitor()->getSummary();
                        if (empty($summary)) {
                            $ss->warning("No hosts are being monitored. Use --add to add some.");
                            return Command::FAILURE;
                        }

                        if ($input->getOption('watch')) {
                            $ss->note("Monitoring continuously; press Ctrl+C to stop.");
                            $manager->getMonitor()->watch(60, 0, function ($results) use ($ss) {
                                $ss->section('Monitor Check at ' . date('Y-m-d H:i:s'));
                                $ss->table(
                                    ['Alias', 'Host', 'Status', 'Latency'],
                                    array_map(static function ($alias, $data) {
                                        return [
                                            $alias,
                                            $data['host'],
                                            $data['reachable'] ? '<info>UP</info>' : '<error>DOWN</error>',
                                            ($data['latency'] ?? 'N/A') . ' ms',
                                        ];
                                    }, array_keys($results), array_values($results))
                                );
                            });
                            return Command::SUCCESS;
                        } else {
                            $results = $manager->checkMonitor();
                            $ss->section('Monitor Check Results');
                            $ss->table(
                                ['Alias', 'Host', 'Status', 'Latency'],
                                array_map(static function ($alias, $data) {
                                    return [
                                        $alias,
                                        $data['host'],
                                        $data['reachable'] ? '<info>UP</info>' : '<error>DOWN</error>',
                                        ($data['latency'] ?? 'N/A') . ' ms',
                                    ];
                                }, array_keys($results), array_values($results))
                            );
                            return Command::SUCCESS;
                        }
                    });
                }

            },

            new class extends Command {
                use CommandHelper, NetworkTrait;

                public function __construct()
                {
                    parent::__construct('network:dns');
                    $this->setDescription('Perform a DNS lookup.');
                }

                protected function configure(): void
                {
                    $this->addArgument('domain', InputArgument::REQUIRED, 'Domain name to look up.');
                    $this->addArgument('type', InputArgument::OPTIONAL, 'DNS record type (A, MX, etc.)', 'A');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'DNS Lookup', function (SymfonyStyle $ss) use ($input) {
                        $domain = $input->getArgument('domain');
                        $typeStr = strtoupper($input->getArgument('type'));
                        $typeMap = [
                            'A' => DNS_A,
                            'MX' => DNS_MX,
                            'NS' => DNS_NS,
                            'SOA' => DNS_SOA,
                            'TXT' => DNS_TXT,
                            'CNAME' => DNS_CNAME,
                            'AAAA' => DNS_AAAA,
                        ];
                        $type = $typeMap[$typeStr] ?? DNS_A;

                        $manager = $this->networkManager();
                        try {
                            $records = $manager->dnsLookup($domain, $type);
                        } catch (MachinjiriException $e) {
                            $ss->error("DNS lookup failed: " . $e->getMessage());
                            return Command::FAILURE;
                        }

                        if (empty($records)) {
                            $ss->warning("No records found for {$domain} of type {$typeStr}.");
                        } else {
                            $ss->section("DNS Records for {$domain}");
                            $rows = [];
                            foreach ($records as $rec) {
                                if (isset($rec['ip'])) {
                                    $rows[] = [$rec['host'] ?? $domain, $rec['type'] ?? $typeStr, $rec['ip']];
                                } elseif (isset($rec['target'])) {
                                    $rows[] = [$rec['host'] ?? $domain, $rec['type'] ?? $typeStr, $rec['target']];
                                } elseif (isset($rec['txt'])) {
                                    $rows[] = [$rec['host'] ?? $domain, $rec['type'] ?? $typeStr, $rec['txt']];
                                } else {
                                    $rows[] = [$rec['host'] ?? $domain, $rec['type'] ?? $typeStr, json_encode($rec)];
                                }
                            }
                            $ss->table(['Domain', 'Type', 'Value'], $rows);
                            $ss->success("Found " . count($records) . " record(s).");
                        }
                        return Command::SUCCESS;
                    });
                }

            },
        ];
    }
}