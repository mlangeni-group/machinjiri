<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

use Mlangeni\Machinjiri\Core\Artisans\Generators\NotificationGenerator;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationManager;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\ChannelManager;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResult;

class NotificationCommand
{
    public static function getCommands(): array
    {
        return [

            /* ============================================================
             |  make:notification
             | ============================================================ */
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:notification');
                    $this->setDescription('Creates a Notification class template inside the app/Notifications/ directory');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The class name of the notification.');
                    $this->addOption(
                        'channels',
                        'c',
                        InputOption::VALUE_REQUIRED,
                        'Comma-separated channels to scaffold (mail,sms,database,webhook)',
                        'mail'
                    );
                    $this->addOption('queue', 'Q', InputOption::VALUE_NONE, 'Mark the notification as queueable by default');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'App Notification', function (SymfonyStyle $ss) use ($input) {
                        $name     = $input->getArgument('name');
                        $channels = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('channels')))));
                        $queueable = (bool) $input->getOption('queue');

                        $generator = new NotificationGenerator($this->artisanContainer());

                        if ($generator->create($name, $channels, $queueable)) {
                            $ss->success("Notification Class '{$name}' created successfully");
                            $ss->newLine();
                            $ss->writeln("  <fg=gray>Channels:</> <fg=cyan>" . implode(', ', $channels) . "</>");
                            $ss->writeln("  <fg=gray>Queueable:</> <fg=cyan>" . ($queueable ? 'yes' : 'no') . "</>");
                            return Command::SUCCESS;
                        }

                        $ss->error("Unable to create Notification Class '{$name}' due to: class already exists or unreadable directory");
                        return Command::FAILURE;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('notify:channels');
                    $this->setDescription('List every notification channel registered with the NotificationManager');
                }

                protected function configure(): void
                {
                    $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the channel list as JSON');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Registered Notification Channels', function (SymfonyStyle $ss) use ($input) {
                        /** @var NotificationManager $manager */
                        $manager  = $this->artisanContainer()->make(NotificationManager::class);
                        $channels = $manager->channels()->registered();

                        if (empty($channels)) {
                            $ss->warning('No notification channels are registered.');
                            return Command::SUCCESS;
                        }

                        $rows = [];
                        foreach ($channels as $name) {
                            try {
                                $instance = $manager->channels()->channel($name);
                                $rows[] = [$name, is_object($instance) ? get_class($instance) : "callback function", '<fg=green>ok</>'];
                            } catch (\Throwable $e) {
                                $rows[] = [$name, '—', '<fg=red>' . $e->getMessage() . '</>'];
                            }
                        }

                        if ($input->getOption('json')) {
                            $ss->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                            return Command::SUCCESS;
                        }

                        $ss->table(['Channel', 'Implementation', 'Status'], $rows);
                        return Command::SUCCESS;
                    });
                }
            },

            /* ============================================================
             |  notify:list
             | ============================================================ */
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('notify:list');
                    $this->setDescription('List Notification classes discovered under app/Notifications');
                }

                protected function configure(): void
                {
                    $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Custom directory to scan');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Available Notification Classes', function (SymfonyStyle $ss) use ($input) {
                        $dir = $input->getOption('path') ?? $this->resolveNotificationsDirectory();

                        if (!is_dir($dir)) {
                            $ss->warning("Notifications directory not found: {$dir}");
                            return Command::SUCCESS;
                        }

                        $files = glob(rtrim($dir, '/\\') . '/*.php') ?: [];

                        if (empty($files)) {
                            $ss->warning('No notification classes were found.');
                            return Command::SUCCESS;
                        }

                        $rows = [];
                        foreach ($files as $file) {
                            $class = 'App\\Notifications\\' . basename($file, '.php');
                            $rows[] = [$class, file_exists($file) ? '<fg=green>yes</>' : '<fg=red>no</>'];
                        }

                        $ss->table(['Notification Class', 'Exists'], $rows);
                        return Command::SUCCESS;
                    });
                }

                private function resolveNotificationsDirectory(): string
                {
                    $base = method_exists($this->artisanContainer(), 'getBasePath')
                        ? $this->artisanContainer()->getBasePath()
                        : (property_exists($this->artisanContainer(), 'basePath')
                            ? $this->artisanContainer()->basePath
                            : dirname(__DIR__, 5));

                    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Notifications';
                }
            },

            /* ============================================================
             |  notify:show
             | ============================================================ */
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('notify:show');
                    $this->setDescription('Inspect a Notification class: channels, payload methods, queue settings');
                }

                protected function configure(): void
                {
                    $this->addArgument('notification', InputArgument::REQUIRED, 'Notification class name (FQCN or App\\Notifications\\Name)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Notification Inspection', function (SymfonyStyle $ss) use ($input) {
                        $fqcn = $this->resolveNotificationClass($input->getArgument('notification'));

                        if (!class_exists($fqcn)) {
                            $ss->error("Notification class '{$fqcn}' not found.");
                            return Command::FAILURE;
                        }

                        if (!is_subclass_of($fqcn, Notification::class)) {
                            $ss->error("'{$fqcn}' does not extend " . Notification::class);
                            return Command::FAILURE;
                        }

                        $ref = new \ReflectionClass($fqcn);

                        $ss->section('General');
                        $ss->horizontalTable(['Property', 'Value'], [
                            ['Class',      $fqcn],
                            ['Extends',    get_parent_class($fqcn)],
                            ['Abstract',   $ref->isAbstract() ? 'yes' : 'no'],
                            ['Final',      $ref->isFinal() ? 'yes' : 'no'],
                        ]);

                        $ss->section('Channel payload methods');
                        $methodRows = [];
                        foreach (['toMail', 'toSms', 'toDatabase', 'toWebhook'] as $method) {
                            if (!$ref->hasMethod($method)) {
                                $methodRows[] = [$method, '—', '<fg=gray>inherited</>'];
                                continue;
                            }

                            $declaring = $ref->getMethod($method)->getDeclaringClass()->getName();
                            $overridden = $declaring === $fqcn;
                            $methodRows[] = [
                                $method,
                                $overridden ? '<fg=green>overridden</>' : '<fg=gray>inherited</>',
                                $declaring,
                            ];
                        }
                        $ss->table(['Method', 'State', 'Declared In'], $methodRows);

                        $ss->section('Constructor');
                        $ctor = $ref->getConstructor();
                        if (!$ctor || $ctor->getNumberOfParameters() === 0) {
                            $ss->writeln('<fg=gray>No constructor parameters.</>');
                        } else {
                            $ctorRows = [];
                            foreach ($ctor->getParameters() as $param) {
                                $ctorRows[] = [
                                    $param->getName(),
                                    $param->hasType() ? (string) $param->getType() : 'mixed',
                                    $param->isOptional() ? 'optional' : 'required',
                                ];
                            }
                            $ss->table(['Parameter', 'Type', 'Required'], $ctorRows);
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            /* ============================================================
             |  notify:send
             | ============================================================ */
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('notify:send');
                    $this->setDescription('Send a notification to an ad-hoc recipient (email/phone/webhook)');
                }

                protected function configure(): void
                {
                    $this->addArgument('notification', InputArgument::REQUIRED, 'Notification class (FQCN or App\\Notifications\\Name)');
                    $this->addOption('to', 't', InputOption::VALUE_REQUIRED, 'Primary recipient (email or phone)');
                    $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Recipient email');
                    $this->addOption('phone', null, InputOption::VALUE_REQUIRED, 'Recipient phone');
                    $this->addOption('webhook', null, InputOption::VALUE_REQUIRED, 'Recipient webhook URL');
                    $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Notifiable ID', 'cli-recipient');
                    $this->addOption('channel', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Force specific channel(s); overrides via()');
                    $this->addOption('data', 'd', InputOption::VALUE_REQUIRED, 'JSON object of constructor named args', '{}');
                    $this->addOption('queue', 'Q', InputOption::VALUE_NONE, 'Dispatch to the queue instead of sending now');
                    $this->addOption('queue-name', null, InputOption::VALUE_REQUIRED, 'Queue name (defaults to notification\'s own)');
                    $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit result as JSON');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Send Notification', function (SymfonyStyle $ss) use ($input) {
                        $container = $this->artisanContainer();

                        /* --- resolve notification --- */
                        $fqcn = $this->resolveNotificationClass($input->getArgument('notification'));
                        if (!class_exists($fqcn) || !is_subclass_of($fqcn, Notification::class)) {
                            $ss->error("Notification class '{$fqcn}' not found or does not extend " . Notification::class);
                            return Command::FAILURE;
                        }

                        /* --- parse constructor data --- */
                        $data = json_decode((string) $input->getOption('data'), true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $ss->error('Invalid JSON passed to --data: ' . json_last_error_msg());
                            return Command::FAILURE;
                        }
                        if (!is_array($data)) {
                            $ss->error('--data must be a JSON object of named constructor arguments.');
                            return Command::FAILURE;
                        }

                        /* --- build the ad-hoc notifiable --- */
                        $notifiable = $this->buildNotifiableFromInput($input);

                        /* --- instantiate --- */
                        try {
                            /** @var Notification $notification */
                            $notification = new $fqcn(...$data); // PHP 8.1 supports named args via spread
                        } catch (\Throwable $e) {
                            $ss->error("Failed to instantiate {$fqcn}: {$e->getMessage()}");
                            return Command::FAILURE;
                        }

                        /* --- optional channel override --- */
                        $forced = $input->getOption('channel');
                        if (!empty($forced)) {
                            $notification = $this->wrapWithForcedChannels($notification, array_values($forced));
                        }

                        /* --- dispatch --- */
                        /** @var NotificationManager $manager */
                        $manager = $container->make(NotificationManager::class);

                        try {
                            if ($input->getOption('queue')) {
                                $ids = $manager->queue($notifiable, $notification, $input->getOption('queue-name'));
                                if ($input->getOption('json')) {
                                    $ss->writeln(json_encode(['queued' => true, 'job_ids' => $ids], JSON_PRETTY_PRINT));
                                } else {
                                    $ss->success('Notification queued.');
                                    foreach ($ids as $id) {
                                        $ss->writeln("  <fg=gray>job id:</> <fg=cyan>{$id}</>");
                                    }
                                }
                                return Command::SUCCESS;
                            }

                            $result = $manager->send($notifiable, $notification);
                            $this->renderResult($ss, $result, (bool) $input->getOption('json'));

                            return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
                        } catch (\Throwable $e) {
                            $ss->error('Notification failed: ' . $e->getMessage());
                            return Command::FAILURE;
                        }
                    });
                }

                private function buildNotifiableFromInput(InputInterface $input): NotifiableInterface
                {
                    $primary = $input->getOption('to');
                    $email   = $input->getOption('email') ?? ($primary && filter_var($primary, FILTER_VALIDATE_EMAIL) ? $primary : null);
                    $phone   = $input->getOption('phone') ?? ($primary && !filter_var($primary, FILTER_VALIDATE_EMAIL) ? $primary : null);
                    $webhook = $input->getOption('webhook');
                    $id      = (string) $input->getOption('id');

                    return new class($id, $email, $phone, $webhook) implements NotifiableInterface {
                        public function __construct(
                            private string $id,
                            private ?string $email,
                            private ?string $phone,
                            private ?string $webhook,
                        ) {}

                        public function routeNotificationFor(string $channel): mixed
                        {
                            return match ($channel) {
                                'mail'    => $this->email,
                                'sms'     => $this->phone,
                                'webhook' => $this->webhook,
                                default   => $this->id,
                            };
                        }

                        public function getNotifiableId(): string
                        {
                            return $this->id;
                        }
                    };
                }

                private function wrapWithForcedChannels(Notification $inner, array $channels): Notification
                {
                    return new class($inner, $channels) extends Notification {
                        public function __construct(private Notification $inner, private array $forced)
                        {
                            parent::__construct();
                        }

                        public function via(NotifiableInterface $notifiable): array
                        {
                            return $this->forced;
                        }

                        public function toMail(NotifiableInterface $n): mixed      { return $this->inner->toMail($n); }
                        public function toSms(NotifiableInterface $n): mixed       { return $this->inner->toSms($n); }
                        public function toDatabase(NotifiableInterface $n): ?array  { return $this->inner->toDatabase($n); }
                        public function toWebhook(NotifiableInterface $n): ?array   { return $this->inner->toWebhook($n); }

                        public function shouldSend(NotifiableInterface $n, string $channel): bool
                        {
                            return $this->inner->shouldSend($n, $channel);
                        }
                    };
                }

                private function renderResult(SymfonyStyle $ss, NotificationResult $result, bool $asJson): void
                {
                    if ($asJson) {
                        $ss->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        return;
                    }

                    $rows = [];
                    foreach ($result->all() as $response) {
                        $rows[] = [
                            $response->channel,
                            $response->success ? '<fg=green>sent</>' : '<fg=red>failed</>',
                            $response->success
                                ? (is_scalar($response->result) ? (string) $response->result : gettype($response->result))
                                : (string) $response->error,
                        ];
                    }

                    $ss->table(['Channel', 'Status', 'Detail'], $rows);

                    $ss->writeln('');
                    $ss->writeln($result->isSuccessful()
                        ? '  <fg=green>✔ All channels delivered.</>'
                        : '  <fg=red>✘ One or more channels failed.</>');
                }
            },

            /* ============================================================
             |  notify:test
             | ============================================================ */
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('notify:test');
                    $this->setDescription('Send a lightweight test notification through a single channel');
                }

                protected function configure(): void
                {
                    $this->addArgument('channel', InputArgument::REQUIRED, 'Channel to test (mail, sms, database, webhook, …)');
                    $this->addOption('to', 't', InputOption::VALUE_REQUIRED, 'Recipient address / number / URL');
                    $this->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Test subject', 'Machinjiri notification test');
                    $this->addOption('body', null, InputOption::VALUE_REQUIRED, 'Test body', 'This is a test notification from the Machinjiri CLI.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Notification Channel Test', function (SymfonyStyle $ss) use ($input) {
                        $container = $this->artisanContainer();
                        /** @var NotificationManager $manager */
                        $manager = $container->make(NotificationManager::class);

                        $channel = (string) $input->getArgument('channel');

                        if (!$manager->channels()->has($channel)) {
                            $ss->error("Channel '{$channel}' is not registered. Try `notify:channels`.");
                            return Command::FAILURE;
                        }

                        $to      = $input->getOption('to');
                        $subject = $input->getOption('subject');
                        $body    = $input->getOption('body');

                        $notifiable = new class($to, $to, $to) implements NotifiableInterface {
                            public function __construct(
                                private ?string $email,
                                private ?string $phone,
                                private ?string $webhook,
                            ) {}

                            public function routeNotificationFor(string $channel): mixed
                            {
                                return match ($channel) {
                                    'mail'    => $this->email,
                                    'sms'     => $this->phone,
                                    'webhook' => $this->webhook,
                                    default   => 'cli-test',
                                };
                            }

                            public function getNotifiableId(): string
                            {
                                return 'cli-test';
                            }
                        };

                        $notification = new class($subject, $body, $channel) extends Notification {
                            public function __construct(
                                private string $subject,
                                private string $body,
                                private string $forcedChannel,
                            ) {
                                parent::__construct();
                            }

                            public function via(NotifiableInterface $notifiable): array
                            {
                                return [$this->forcedChannel];
                            }

                            public function toMail(NotifiableInterface $n): array
                            {
                                return ['subject' => $this->subject, 'html' => "<p>{$this->body}</p>", 'text' => $this->body];
                            }

                            public function toSms(NotifiableInterface $n): array
                            {
                                return ['body' => $this->body];
                            }

                            public function toDatabase(NotifiableInterface $n): array
                            {
                                return ['type' => 'cli.test', 'title' => $this->subject, 'body' => $this->body];
                            }

                            public function toWebhook(NotifiableInterface $n): array
                            {
                                return [
                                    'url'  => $n->routeNotificationFor('webhook') ?? '',
                                    'event' => 'cli.test',
                                    'data' => ['subject' => $this->subject, 'body' => $this->body],
                                ];
                            }
                        };

                        $ss->writeln("  <fg=gray>Channel:</>  <fg=cyan>{$channel}</>");
                        $ss->writeln("  <fg=gray>Target:</>   " . ($to ?: '<fg=yellow>(default from routeNotificationFor)</>'));
                        $ss->newLine();

                        $result = $manager->send($notifiable, $notification);

                        foreach ($result->all() as $response) {
                            if ($response->success) {
                                $ss->success("[{$response->channel}] delivered.");
                            } else {
                                $ss->error("[{$response->channel}] {$response->error}");
                            }
                        }

                        return $result->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
                    });
                }
            },

            /* ============================================================
             |  notify:events
             | ============================================================ */
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('notify:events');
                    $this->setDescription('List the events the Notification component emits');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Notification Events', function (SymfonyStyle $ss) {
                        $ss->table(['Event', 'Fired When', 'Payload Keys'], [
                            ['notification.sending', 'Before a channel attempts delivery', 'notification, notifiable, channel'],
                            ['notification.sent',    'After a channel reports success',   'notification, notifiable, channel, response'],
                            ['notification.failed',  'A channel reports failure or throws','notification, notifiable, channel, response|exception'],
                            ['notification.queued',  'A queued notification is dispatched','notification, notifiable, queue'],
                        ]);
                        return Command::SUCCESS;
                    });
                }
            },

        ];
    }

    /* ================================================================
     |  Shared helpers (duplicated per command because each is an
     |  anonymous class; keep the trait free of class-level state).
     | ================================================================ */

    private static function resolveNotificationClassStatic(string $name): string
    {
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        return 'App\\Notifications\\' . $name;
    }
}