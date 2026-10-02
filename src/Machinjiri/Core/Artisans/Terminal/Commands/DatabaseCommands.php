<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ResourceGenerator;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Database\Seeder\SeederManager;
use Mlangeni\Machinjiri\Core\Database\Factory\FactoryManager;
use Mlangeni\Machinjiri\Core\Database\Migrations\{MigrationCreator, MigrationHandler};
use Mlangeni\Machinjiri\Facade\UI\Bootstrap\Misc\Keywords;
use MongoDB\Client as MongoClient;

trait MigrationInstances
{
    use CommandHelper;
    
    public function handler(): MigrationHandler
    {
        return new MigrationHandler($this->artisanContainer());
    }

    public function creator(): MigrationCreator
    {
        return new MigrationCreator($this->artisanContainer());
    }

    public function seeder(): SeederManager
    {
        return new SeederManager($this->artisanContainer());
    }

    public function factory(): FactoryManager
    {
        return new FactoryManager($this->artisanContainer());
    }
}

class DatabaseCommands extends Command
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('db:test-connection');
                    $this->setDescription('Tests the database connection');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Connection', function (SymfonyStyle $ss) use ($input) {
                        $app = $this->artisanContainer();
                        if ($app->bound('db.kernel.connection')) {
                            $dbConnection = $app->resolve('db.kernel.connection');
                            if (($dbConnection instanceof \PDO) || ($dbConnection instanceof MongoClient)) {
                                $ss->success('Database connection is successful.');
                                return Command::SUCCESS;
                            } else {
                                $ss->error('Database connection failed. Please check your database configuration.');
                                return Command::FAILURE;
                            }
                        } else {
                            $ss->error('Database connection service is not bound in the container.');
                            return Command::FAILURE;
                        }
                    });
                }
            },
            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:migration:create');
                    $this->setDescription('Create a Migration Template file');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the migration template. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table ');
                    $this->addOption('blueprint', null, InputOption::VALUE_NONE, 'Create a Migration with Blueprint functionality');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Create a Migration]', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $bluePrint = (bool) $input->getOption('blueprint');

                        if (in_array($name, Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper migration name");
                            return Command::FAILURE;
                        }

                        $creator = $this->creator();
                        if ($creator->getMigrationFiles() > 0) {
                            foreach ($creator->getMigrationFiles() as $file) {
                                if ($creator->getFileName($file) == ucfirst($name)) {
                                    $ss->error("A migration with name '" . $name . "' already exists");
                                    return Command::FAILURE;
                                }
                            }
                        }

                        $ss->success("Created: " . basename($creator->create($name, $bluePrint)));
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:migration:list');
                    $this->setDescription('Get a list of migration files');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [List Created Migrations]', function (SymfonyStyle $ss) {
                        $creator = $this->creator();
                        $ss->section("List of Migrations");
                        $files = [];
                        if (count($creator->getMigrationFiles()) > 0) {
                            foreach ($creator->getMigrationFiles() as $migration) {
                                if (is_dir($migration)) continue;
                                $files[] = basename($migration);
                            }
                            $ss->listing($files);
                        } else {
                            $ss->warning("No Migrations available.");
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:migration:remove');
                    $this->setDescription('Remove/delete a migration file');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the migration. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Delete Migration]', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $creator = $this->creator();
                        if ($creator->removeMigration($name)) {
                            $ss->success("Deleted successfully: " . $name);
                        } else {
                            $ss->error("Unable to delete migration: " . $name);
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('db:migration:get-ran');
                    $this->setDescription('Retrieve Migrations that have been ran');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [List of Ran Migrations]', function (SymfonyStyle $ss) {
                        $mh = $this->handler();
                        $runMigrations = $mh->getRanMigrations(true);

                        if (count($runMigrations) > 0) {
                            $counter = 0;
                            $headers = ['No.', 'Migration', 'Created At'];
                            $rows = [];
                            foreach ($runMigrations as $runMigration) {
                                $counter++;
                                $rows[] = [$counter, $runMigration['migration'], $runMigration['created_at']];
                            }
                            $ss->table($headers, $rows);
                        } else {
                            $ss->info("No ran migrations found.");
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:migration:migrate');
                    $this->setDescription('Run migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Run Available Migrations]', function (SymfonyStyle $ss) {
                        $ss->progressStart(100);
                        $this->handler()->migrate();
                        $ss->progressAdvance();
                        $ss->progressFinish();
                        $ss->success("Operation completed successfully");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:migration:rollback');
                    $this->setDescription('Rollback migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migrations [Rollback Migrations]', function (SymfonyStyle $ss) {
                        $ss->progressStart(100);
                        $ss->text('');
                        $this->handler()->rollback();
                        $ss->progressAdvance();
                        $ss->progressFinish();
                        $ss->success("Operation Complete!");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:seeder:create');
                    $this->setDescription("Creates a Database Seeder File. (inside database/seeders)");
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Database Seeder Name');
                    $this->addOption('migration', null, InputOption::VALUE_NONE, 'Create a Migration file for this Seeder');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Seeder', function (SymfonyStyle $ss) use ($input) {
                        $seederName = $input->getArgument('name');

                        if (in_array(strtolower($seederName), Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper migration name");
                            return Command::FAILURE;
                        }

                        $makeMigration = (bool) $input->getOption('migration');
                        $listing = [];

                        if ($makeMigration) {
                            $creator = $this->creator();
                            if ($creator->getMigrationFiles() > 0) {
                                foreach ($creator->getMigrationFiles() as $file) {
                                    if ($creator->getFileName($file) == ucfirst($seederName)) {
                                        $ss->error("A migration with name '" . $seederName . "' already exists");
                                        return Command::FAILURE;
                                    }
                                }
                            }
                            $listing[] = "Migration => " . basename($creator->create($seederName));
                        }

                        $seederManager = $this->seeder();
                        $seederManager->registerAutoload();

                        $seederPath = $seederManager->make($seederName);

                        if ($seederManager->created) {
                            $listing[] = "Seeder => " . $seederName;
                            $listing[] = "Path => " . basename($seederPath);

                            $ss->success("Seeder Created Successfully");
                            $ss->section('Creation Information');
                            $ss->listing($listing);
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create Seeder at the moment");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:seeder:run');
                    $this->setDescription("Run a Database Seeder File. (inside database/seeders)");
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Database Seeder Name');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Seeder', function (SymfonyStyle $ss) use ($input) {
                        $seederName = $input->getArgument('name');

                        if (in_array(strtolower($seederName), Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper migration name");
                            return Command::FAILURE;
                        }

                        $seederManager = $this->seeder();
                        $seederManager->registerAutoload();

                        if ($seederManager->run($seederName)) {
                            $ss->success("Seeder Ran Successfully");
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to run Seeder at the moment");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:seeder:run-all');
                    $this->setDescription("Run all Database Seeders. (inside database/seeders)");
                }

                protected function configure(): void
                {
                    $this->addOption('with-transaction', null, InputOption::VALUE_NONE, 'Run all seeders within a single transaction');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Seeder', function (SymfonyStyle $ss) use ($input) {
                        $withTransaction = $input->getOption('with-transaction');

                        $seederManager = $this->seeder();
                        $seederManager->registerAutoload();

                        if (!$withTransaction) {
                            $results = $seederManager->runAll();
                            $ss->success("All Seeders Ran Successfully");
                            foreach ($results as $key => $result) {
                                $ss->text($key . " - " . $result);
                            }
                            return Command::SUCCESS;
                        } else {
                            $results = $seederManager->runAllInTransaction();
                            $ss->success("All Seeders Ran Successfully within a single transaction");
                            foreach ($results as $key => $result) {
                                $ss->text($key . " - " . $result);
                            }
                            return Command::SUCCESS;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:seeder:list');
                    $this->setDescription("Show list of Database Seeders. (inside database/seeders)");
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Seeder', function (SymfonyStyle $ss) {
                        $seederManager = $this->seeder();
                        $seederManager->registerAutoload();
                        $seeders = $seederManager->list();

                        if (count($seeders) === 0) {
                            $ss->warning("No seeders found.");
                            return Command::SUCCESS;
                        }
                        
                        foreach ($seeders as $seeder) {
                            $ss->text("Seeder: " . $seeder['name']);
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:seeder:refresh');
                    $this->setDescription("Refresh Database Seeders. (inside database/seeders)");
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Seeder', function (SymfonyStyle $ss) use ($input) {
                        $seederManager = $this->seeder();
                        $refreshed = (array) $seederManager->refresh();
                        if ($refreshed) {
                            $ss->success("All Seeders Refreshed Successfully");
                            foreach ($refreshed as $key => $result) {
                                $ss->text($key . " - Status: " . ($result ? "Success" : "Failed"));
                            }
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to refresh Seeders at the moment");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:factory:create');
                    $this->setDescription("Creates a Database Factory File. (inside database/factories)");
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Database Factory Name');
                    $this->addOption('migration', null, InputOption::VALUE_NONE, 'Create a Migration file for this Factory');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Factory', function (SymfonyStyle $ss) use ($input) {
                        $factoryName = $input->getArgument('name');

                        if (in_array(strtolower($factoryName), Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper Factory name");
                            return Command::FAILURE;
                        }

                        $makeMigration = (bool) $input->getOption('migration');
                        $listing = [];

                        if ($makeMigration) {
                            $creator = $this->creator();
                            if ($creator->getMigrationFiles() > 0) {
                                foreach ($creator->getMigrationFiles() as $file) {
                                    if ($creator->getFileName($file) == ucfirst($factoryName)) {
                                        $ss->error("A migration with name '" . $factoryName . "' already exists");
                                        return Command::FAILURE;
                                    }
                                }
                            }
                            $listing[] = "Migration => " . basename($creator->create($factoryName));
                        }

                        $factoryManager = $this->factory();
                        $factoryManager->registerAutoload();
                        $factoryPath = $factoryManager->make($factoryName);

                        if ($factoryManager->created) {
                            $listing[] = "Factory : " . $factoryName;
                            $listing[] = "Path : " . basename($factoryPath);

                            $ss->success("Factory Created Successfully");
                            $ss->section('Creation Information');
                            $ss->listing($listing);
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create Factory at the moment");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationInstances;

                public function __construct()
                {
                    parent::__construct('db:factory:run');
                    $this->setDescription("Generate fake values and insert into database");
                }

                protected function configure(): void
                {
                    $this->addArgument('model', InputArgument::REQUIRED, 'Factory model name');
                    $this->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of records to generate', 10);
                    $this->addOption('fake-only', null, InputOption::VALUE_NONE, 'Generate but do not insert in database');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Factory', function (SymfonyStyle $ss) use ($input, $output) {
                        $model = $input->getArgument('model');
                        $recordCount = $input->getOption('count');
                        $fakeOnly = (bool) $input->getOption('fake-only');

                        $factoryManager = $this->factory();

                        $records = ($fakeOnly) ? $factoryManager->fake($model, $recordCount) : $factoryManager->run($model, $recordCount);

                        if (count($records) === 0) {
                            $ss->text("Factory faked no records");
                            return Command::SUCCESS;
                        }

                        $records = $records['records'] ?? $records;

                        $count = count($records);
                        $ss->success("$count} records were generated.");
                        return Command::SUCCESS;
                    });
                }
            },
        ];
    }
}