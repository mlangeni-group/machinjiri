<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Artisans\Generators\TemplateGenerator;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class ViewCommands
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
              use CommandHelper;
              public function __construct()
              {
                  parent::__construct('view:make');
                  $this->setDescription('Create a new view template');
              }
          
              protected function configure(): void
              {
                  $this->addArgument('name', InputArgument::REQUIRED, 'View name (dot notation, e.g., admin.dashboard)');
                  $this->addOption('layout', 'l', InputOption::VALUE_OPTIONAL, 'Layout to extend (e.g., layouts.app)', 'layouts.app');
                  $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing view');
              }
          
              protected function execute(InputInterface $input, OutputInterface $output): int
              {
                  return $this->executeWithStyle($input, $output, 'View Generator', function (SymfonyStyle $ss) use ($input) {
                      $name = $input->getArgument('name');
                      $layout = $input->getOption('layout');
                      $force = $input->getOption('force');
          
                      try {
                          $generator = new TemplateGenerator(resource_path("views"));
                          $path = $generator->makeView($name, $layout, [], $force);
                          $ss->success("View '{$name}' created successfully");
                          $ss->text("Path: " . $path);
                          return Command::SUCCESS;
                      } catch (\Throwable $e) {
                          $ss->error($e->getMessage());
                          return Command::FAILURE;
                      }
                      
                  });
              }
            },
            new class extends Command {
              use CommandHelper;
              public function __construct()
              {
                  parent::__construct('view:make-layout');
                  $this->setDescription('Create a new layout template');
              }
          
              protected function configure(): void
              {
                  $this->addArgument('name', InputArgument::REQUIRED, 'Layout name (dot notation, e.g., layouts.admin)');
                  $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing layout');
              }
          
              protected function execute(InputInterface $input, OutputInterface $output): int
              {
                  return $this->executeWithStyle($input, $output, 'Layout Generator', function (SymfonyStyle $ss) use ($input) {
                      $name = $input->getArgument('name');
                      $force = $input->getOption('force');
          
                      try {
                          $generator = new TemplateGenerator(getcwd() . "/resources/views/layouts/");
                          $path = $generator->makeLayout($name, [], $force);
                          $ss->success("Layout '{$name}' created successfully");
                          $ss->text("Path: " . $path);
                          return Command::SUCCESS;
                      } catch (MachinjiriException $e) {
                          $ss->error($e->getMessage());
                          return Command::FAILURE;
                      }
                  });
              }
            },
            new class extends Command {
              use CommandHelper;
              public function __construct()
              {
                  parent::__construct('view:make-partial');
                  $this->setDescription('Create a new partial (fragment) template');
              }
          
              protected function configure(): void
              {
                  $this->addArgument('name', InputArgument::REQUIRED, 'Partial name (dot notation, e.g., partials.header)');
                  $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing partial');
              }
          
              protected function execute(InputInterface $input, OutputInterface $output): int
              {
                  return $this->executeWithStyle($input, $output, 'Partial Generator', function (SymfonyStyle $ss) use ($input) {
                      $name = $input->getArgument('name');
                      $force = $input->getOption('force');
          
                      try {
                          $generator = new TemplateGenerator(getcwd() . "/resources/views/partials/");
                          $path = $generator->makePartial($name, [], $force);
                          $ss->success("Partial '{$name}' created successfully");
                          $ss->text("Path: " . $path);
                          return Command::SUCCESS;
                      } catch (MachinjiriException $e) {
                          $ss->error($e->getMessage());
                          return Command::FAILURE;
                      }
                  });
              }
            },
        ];
    }
}