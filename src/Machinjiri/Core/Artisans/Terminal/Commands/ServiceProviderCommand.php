<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ServiceProviderGenerator;

trait spHelper 
{
    use CommandHelper;

    public function serviceProvider(): ServiceProviderGenerator
    {
        return new ServiceProviderGenerator($this->artisanContainer());
    }

}

class ServiceProviderCommand
{
    public static function getCommands(): array
    {
        return [
            // provider:make
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:make');
                    $this->setDescription("Generate an Application's Service Provider");
                }

                protected function configure(): void
                {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('deferred', null, InputOption::VALUE_NONE, 'Set Service Provider as Deferred');
                    $this->addOption('no-config', null, InputOption::VALUE_NONE, 'Do not create configuration file');
                    $this->addOption('stub', null, InputOption::VALUE_OPTIONAL, 'Use custom stub template');
                    $this->addOption('bind', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add bindings (format: abstract=concrete)');
                    $this->addOption('singleton', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add singleton bindings (format: abstract=concrete)');
                    $this->addOption('alias', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add aliases (format: alias=abstract)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'App Service Providers', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->serviceProvider();
                        $service = $input->getArgument('service');

                        $options = [];
                        if ($input->getOption('deferred') !== null) {
                            $options['deferred'] = true;
                        }
                        if ($input->getOption('no-config')) {
                            $options['config'] = false;
                        }

                        // Bindings
                        $bindings = [];
                        foreach ($input->getOption('bind') as $binding) {
                            list($abstract, $concrete) = explode('=', $binding, 2);
                            $bindings[$abstract] = $concrete;
                        }
                        if (!empty($bindings)) {
                            $options['bindings'] = $bindings;
                        }

                        // Singletons
                        $singletons = [];
                        foreach ($input->getOption('singleton') as $singleton) {
                            list($abstract, $concrete) = explode('=', $singleton, 2);
                            $singletons[$abstract] = $concrete;
                        }
                        if (!empty($singletons)) {
                            $options['singletons'] = $singletons;
                        }

                        // Aliases
                        $aliases = [];
                        foreach ($input->getOption('alias') as $alias) {
                            list($aliasName, $abstract) = explode('=', $alias, 2);
                            $aliases[$aliasName] = $abstract;
                        }
                        if (!empty($aliases)) {
                            $options['aliases'] = $aliases;
                        }

                        $stub = $input->getOption('stub');
                        if ($stub) {
                            $replacements = [];
                            if (!empty($bindings)) {
                                $replacements['{{bindings}}'] = var_export($bindings, true);
                            }
                            if (!empty($singletons)) {
                                $replacements['{{singletons}}'] = var_export($singletons, true);
                            }
                            if (!empty($aliases)) {
                                $replacements['{{aliases}}'] = var_export($aliases, true);
                            }

                            $result = $generator->generateFromStub($service, $stub, $replacements);
                            $ss->success("Service Provider generated from stub '{$stub}' successfully");
                            return Command::SUCCESS;
                        } else {
                            $result = $generator->generate($service, $options);
                            if (count($result) > 0) {
                                $ss->success('Service provider generated successfully.');
                                $ss->listing($result);
                                return Command::SUCCESS;
                            } else {
                                $ss->error('Could not generate service provider.');
                                return Command::FAILURE;
                            }
                        }
                    });
                }
            },

            // provider:remove
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:remove');
                    $this->setDescription("Remove a Service Provider");
                }

                protected function configure(): void
                {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('config', null, InputOption::VALUE_NONE, 'Also remove its configuration file');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force removal without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Service Providers', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->serviceProvider();
                        $service = $input->getArgument('service');

                        if (!$input->getOption('force')) {
                            if (!$ss->confirm("Are you sure you want to remove service provider '{$service}'?", false)) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }

                        $removeConfig = $input->getOption('config');
                        $result = $generator->remove($service, $removeConfig, true);

                        if ($result) {
                            $ss->success('Service provider removed successfully.');
                            return Command::SUCCESS;
                        } else {
                            $ss->error('Could not remove service provider.');
                            return Command::FAILURE;
                        }
                    });
                }
            },

            // provider:list
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:list');
                    $this->setDescription("List all Service Providers");
                    $this->addOption('details', null, InputOption::VALUE_NONE, 'Show detailed information');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Service Providers', function (SymfonyStyle $ss) use ($input, $output) {
                        $generator = $this->serviceProvider();
                        $providers = $generator->listProviders();

                        if (empty($providers)) {
                            $ss->warning('No service providers found.');
                            return Command::SUCCESS;
                        }

                        if ($input->getOption('details')) {
                            $rows = [];
                            foreach ($providers as $provider) {
                                $rows[] = [
                                    $provider['name'],
                                    $provider['file'],
                                    $provider['path'],
                                    $provider['class'],
                                    $provider['exists'] ? 'Yes' : 'No',
                                ];
                            }
                            $ss->table(['Name', 'File', 'Path', 'Class', 'Exists'], $rows);
                        } else {
                            $providerNames = array_column($providers, 'name');
                            $ss->listing($providerNames);
                        }

                        $ss->success(sprintf('Found %d service provider(s).', count($providers)));
                        return Command::SUCCESS;
                    });
                }
            },

            // provider:init
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:init');
                    $this->setDescription("Initialize all basic Service Providers");
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force re-creation of existing providers');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Initialize Basic Service Providers', function (SymfonyStyle $ss) {
                        $generator = $this->serviceProvider();
                        $result = $generator->generateAllBasic();

                        if (count($result) > 0) {
                            $ss->success("Basic service providers initialized successfully");
                            $ss->listing($result);
                            return Command::SUCCESS;
                        } else {
                            $ss->warning("All basic service providers already exist");
                            return Command::SUCCESS;
                        }
                    });
                }
            },

            // provider:stub
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:stub');
                    $this->setDescription("Create a Service Provider from custom stub");
                }

                protected function configure(): void
                {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addArgument('stub', InputArgument::REQUIRED, 'The stub template name');
                    $this->addOption('replace', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Custom replacements (format: key=value)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Create Service Provider from Stub', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->serviceProvider();
                        $service = $input->getArgument('service');
                        $stubName = $input->getArgument('stub');

                        $replacements = [];
                        foreach ($input->getOption('replace') as $replacement) {
                            list($key, $value) = explode('=', $replacement, 2);
                            $replacements['{{' . $key . '}}'] = $value;
                        }

                        $result = $generator->generateFromStub($service, $stubName, $replacements);
                        $ss->success("Service provider created from stub '{$stubName}'.");
                        $ss->definitionList(['File' => $result]);
                        return Command::SUCCESS;
                    });
                }
            },

            // provider:register
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:register');
                    $this->setDescription("Register a Service Provider in providers.php config");
                }

                protected function configure(): void
                {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('deferred', null, InputOption::VALUE_NONE, 'Register as deferred provider');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Register Service Provider', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->serviceProvider();
                        $service = $input->getArgument('service');
                        $service = $generator->normalizeName($service);

                        $providersConfig = getcwd() . '/config/providers.php';
                        if (!file_exists($providersConfig)) {
                            $generator->createDefaultProvidersConfig();
                            $ss->note('Created providers.php configuration file.');
                        }

                        $config = require $providersConfig;
                        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$service}";
                        $updated = false;

                        if (!in_array($providerClass, $config['providers'] ?? [])) {
                            $config['providers'][] = $providerClass;
                            $updated = true;
                        }

                        if ($input->getOption('deferred')) {
                            if (!isset($config['deferred'])) {
                                $config['deferred'] = [];
                            }
                            if (!in_array($providerClass, $config['deferred'])) {
                                $config['deferred'][] = $providerClass;
                                $updated = true;
                            }
                        }

                        if ($updated) {
                            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                            file_put_contents($providersConfig, $content);
                            $ss->success('Service provider registered in providers.php.');
                            $isDeferred = isset($config['deferred']) && in_array($providerClass, $config['deferred']);
                            $ss->definitionList(
                                ['Provider' => $providerClass],
                                ['Registered' => 'Yes'],
                                ['Deferred' => $isDeferred ? 'Yes' : 'No']
                            );
                        } else {
                            $ss->warning('Service provider is already registered.');
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            // provider:unregister
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:unregister');
                    $this->setDescription("Unregister a Service Provider from providers.php config");
                }

                protected function configure(): void
                {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force unregistration without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Unregister Service Provider', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->serviceProvider();
                        $service = $input->getArgument('service');
                        $service = $generator->normalizeName($service);

                        if (!$input->getOption('force')) {
                            if (!$ss->confirm("Are you sure you want to unregister service provider '{$service}' from providers.php?", false)) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }

                        $providersConfig = getcwd() . '/config/providers.php';
                        if (!file_exists($providersConfig)) {
                            $ss->error("providers.php configuration file not found");
                            return Command::FAILURE;
                        }

                        $config = require $providersConfig;
                        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$service}";
                        $updated = false;

                        if (isset($config['providers']) && ($key = array_search($providerClass, $config['providers'])) !== false) {
                            unset($config['providers'][$key]);
                            $config['providers'] = array_values($config['providers']);
                            $updated = true;
                        }

                        if (isset($config['deferred']) && ($key = array_search($providerClass, $config['deferred'])) !== false) {
                            unset($config['deferred'][$key]);
                            $config['deferred'] = array_values($config['deferred']);
                            $updated = true;
                        }

                        if ($updated) {
                            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                            file_put_contents($providersConfig, $content);
                            $ss->success('Service provider unregistered from providers.php.');
                            $ss->definitionList(['Provider' => $providerClass]);
                        } else {
                            $ss->warning("Service Provider was not found in providers.php");
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            // provider:info
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:info');
                    $this->setDescription("Show information about a Service Provider");
                }

                protected function configure(): void
                {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Service Provider Information', function (SymfonyStyle $ss) use ($input, $output) {
                        $generator = $this->serviceProvider();
                        $service = $input->getArgument('service');
                        $service = $generator->normalizeName($service);

                        $providerFile = getcwd() . '/app/Providers/' . $service . '.php';
                        if (!file_exists($providerFile)) {
                            $ss->error("Service Provider not found: {$service}");
                            return Command::FAILURE;
                        }

                        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$service}";
                        $configName = strtolower(str_replace('ServiceProvider', '', $service));
                        $configFile = getcwd() . '/config/' . $configName . '.php';

                        $info = [
                            'Name' => $service,
                            'Class' => $providerClass,
                            'File' => $providerFile,
                            'Exists' => file_exists($providerFile) ? 'Yes' : 'No',
                            'Class Exists' => class_exists($providerClass) ? 'Yes' : 'No',
                        ];

                        if (file_exists($providerFile)) {
                            $content = file_get_contents($providerFile);

                            preg_match('/protected bool \$defer = (true|false);/', $content, $deferredMatch);
                            $deferred = $deferredMatch[1] ?? 'false';
                            $info['Deferred'] = $deferred;

                            preg_match('/protected array \$bindings = (\[.*?\]);/s', $content, $bindingsMatch);
                            if ($bindingsMatch) {
                                eval('$bindings = ' . $bindingsMatch[1] . ';');
                                $info['Bindings Count'] = count($bindings);
                            }

                            preg_match('/protected array \$singletons = (\[.*?\]);/s', $content, $singletonsMatch);
                            if ($singletonsMatch) {
                                eval('$singletons = ' . $singletonsMatch[1] . ';');
                                $info['Singletons Count'] = count($singletons);
                            }

                            preg_match('/protected array \$aliases = (\[.*?\]);/s', $content, $aliasesMatch);
                            if ($aliasesMatch) {
                                eval('$aliases = ' . $aliasesMatch[1] . ';');
                                $info['Aliases Count'] = count($aliases);
                            }
                        }

                        $ss->section('Provider Details');
                        $ss->definitionList(...array_map(
                            static fn (string $key, $value): array => [$key => $value],
                            array_keys($info),
                            array_values($info)
                        ));

                        $providersConfig = getcwd() . '/config/providers.php';
                        if (file_exists($providersConfig)) {
                            $config = require $providersConfig;
                            $isRegistered = in_array($providerClass, $config['providers'] ?? []);
                            $isDeferred = isset($config['deferred']) && in_array($providerClass, $config['deferred']);

                            $registration = ['Registered in providers.php' => $isRegistered ? 'Yes' : 'No'];
                            if ($isRegistered) {
                                $registration['Marked as deferred'] = $isDeferred ? 'Yes' : 'No';
                            }
                            $ss->section('Registration');
                            $ss->definitionList($registration);
                        }

                        if (file_exists($configFile)) {
                            $ss->section('Configuration');
                            $ss->definitionList(['File' => $configFile], ['Status' => 'Found']);
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            // provider:thirdparty-auth
            new class extends Command {
                use CommandHelper, spHelper;

                public function __construct()
                {
                    parent::__construct('provider:thirdparty-auth');
                    $this->setDescription("Generate ThirdPartyAuthServiceProvider with configuration");
                }

                protected function configure(): void
                {
                    $this->addOption('deferred', null, InputOption::VALUE_NONE, 'Make provider deferred (lazy-loaded)');
                    $this->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip configuration file creation');
                    $this->addOption('no-migration', null, InputOption::VALUE_NONE, 'Skip database migration creation');
                    $this->addOption('providers', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of providers to enable');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force creation even if files exist');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Third-Party Authentication Setup', function (SymfonyStyle $ss) use ($input) {
                        $generator = $this->serviceProvider();

                        $options = [
                            'deferred' => $input->getOption('deferred'),
                            'config' => !$input->getOption('no-config'),
                            'database' => !$input->getOption('no-migration'),
                            'register' => true,
                        ];

                        if ($input->getOption('providers')) {
                            $providers = array_map('trim', explode(',', $input->getOption('providers')));
                            $options['providers'] = $providers;
                        }

                        $providerFile = getcwd() . '/app/Providers/ThirdPartyAuthServiceProvider.php';
                        if (file_exists($providerFile) && !$input->getOption('force')) {
                            $ss->error("ThirdPartyAuthServiceProvider already exists. Use --force to overwrite.");
                            return Command::FAILURE;
                        }

                        $result = $generator->generateThirdPartyAuth($options);

                        if (count($result) > 0) {
                            $ss->success('Third-party authentication setup completed successfully.');
                            $ss->section('Created Files');
                            $ss->listing($result);

                            $ss->section("Next Steps");
                            $ss->text([
                                "1. Add OAuth credentials to your .env file:",
                                "   GOOGLE_CLIENT_ID=your-client-id",
                                "   GOOGLE_CLIENT_SECRET=your-client-secret",
                                "   GITHUB_CLIENT_ID=your-client-id",
                                "   GITHUB_CLIENT_SECRET=your-client-secret",
                                "",
                                "2. Run database migrations to create necessary tables",
                                "",
                                "3. Customize routes in your application to handle:",
                                "   /auth/login?provider=google",
                                "   /auth/callback",
                                "   /auth/logout",
                                "",
                                "4. Review the configuration file at: config/thirdparty_auth.php",
                            ]);

                            return Command::SUCCESS;
                        } else {
                            $ss->error("Could not generate Third-Party Authentication setup");
                            return Command::FAILURE;
                        }
                    });
                }
            },
        ];
    }
}