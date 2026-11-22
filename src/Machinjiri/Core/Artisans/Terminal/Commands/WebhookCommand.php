<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ServiceProviderGenerator;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\WebhookException;

/**
 * Console command to generate webhook components.
 *
 * Usage:
 *   php artisan make:webhook Stripe
 *
 * Options:
 *   --controller  Generate webhook controller only
 *   --job         Generate queued job only
 *   --config      Update/add webhook provider configuration
 *   --handler     Generate an event handler class
 *   --provider    Generate a Service Provider 
 *   --all         Generate all components (default)
 */
class WebhookCommand
{
    /**
     * Get all Webhook commands.
     *
     * @return array<Command>
     */
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:webhook');
                    $this->setDescription('Generates webhook components: controller, job, config, and handler');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The webhook name (e.g., Stripe, GitHub)');
                    $this->addOption('controller', null, InputOption::VALUE_NONE, 'Generate webhook controller only');
                    $this->addOption('config', null, InputOption::VALUE_NONE, 'Add webhook provider configuration');
                    $this->addOption('handler', null, InputOption::VALUE_NONE, 'Generate an event handler class');
                    $this->addOption('provider', null, InputOption::VALUE_NONE, 'Generate a service provider for webhook registration');
                    $this->addOption('all', null, InputOption::VALUE_NONE, 'Generate all components (default)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Webhook Generator', function (SymfonyStyle $io) use ($input) {
                        $name = $input->getArgument('name');
                        $normalizedName = ucfirst(preg_replace('/[^a-zA-Z0-9]/', '', $name));

                        if (empty($normalizedName)) {
                            $io->error('Invalid webhook name. Use only letters and numbers.');
                            return Command::FAILURE;
                        }

                        $generateAll = $input->getOption('all') || (
                            !$input->getOption('controller') &&
                            !$input->getOption('config') &&
                            !$input->getOption('handler') &&
                            !$input->getOption('provider')
                        );

                        $created = [];

                        // 1. Generate Controller
                        if ($generateAll || $input->getOption('controller')) {
                            if ($this->generateController($normalizedName, $io)) {
                                $created[] = ['Controller', "app/Controllers/{$normalizedName}WebhookController.php"];
                            }
                        }

                        // 2. Update Configuration
                        if ($generateAll || $input->getOption('config')) {
                            if ($this->updateConfig($normalizedName, $io)) {
                                $created[] = ['Configuration', 'config/core/webhooks.php'];
                            }
                        }

                        // 3. Generate Handler
                        if ($generateAll || $input->getOption('handler')) {
                            if ($this->generateHandler($normalizedName, $io)) {
                                $created[] = ['Handler', "app/Webhooks/Handlers/{$normalizedName}WebhookHandler.php"];
                            }
                        }

                        // 4. Generate Service Provider
                        if ($generateAll || $input->getOption('provider')) {
                            if ($this->generateServiceProvider($normalizedName, $io)) {
                                $created[] = ['Service provider', "app/Providers/{$normalizedName}WebhookServiceProvider.php"];
                            }
                        }

                        if (empty($created)) {
                            $io->warning('No components were generated. Use --all or specify options.');
                            return Command::SUCCESS;
                        }

                        $io->success('Webhook generation complete');
                        $io->section('Generated components');
                        $io->table(
                            ['Component', 'Location', 'Status'],
                            array_map(static fn (array $component): array => [$component[0], $component[1], 'Created'], $created)
                        );

                        $io->section('Next steps');
                        $io->note('Register the handler in your service provider if it was not added automatically.');
                        $route = strtolower($normalizedName);
                        $io->definitionList(
                            ['Route' => "\$router->post('/webhook/{$route}', [{$normalizedName}WebhookController::class, 'handle'])"],
                            ['Environment variable' => "{$normalizedName}_WEBHOOK_SECRET"]
                        );

                        return Command::SUCCESS;
                    });
                }

                private function generateController(string $name, SymfonyStyle $io): bool
                {
                    $controllerName = $name . 'WebhookController';
                    $controllerPath = $this->getControllersPath() . $controllerName . '.php';

                    if (file_exists($controllerPath)) {
                        $io->warning("Controller {$controllerName} already exists. Skipping.");
                        return false;
                    }

                    $template = $this->getControllerTemplate($name, $controllerName);
                    if ($this->writeFile($controllerPath, $template)) {
                        return true;
                    }
                    $io->error("Failed to create controller: {$controllerName}");
                    return false;
                }

                private function updateConfig(string $name, SymfonyStyle $io): bool
                {
                    $configFile = $this->getConfigPath() . 'webhooks.php';
                    $providerKey = strtolower($name);

                    // Load existing config or start fresh
                    $config = [];
                    if (file_exists($configFile)) {
                        $config = require $configFile;
                    }

                    // If provider already exists, skip
                    if (isset($config['providers'][$providerKey])) {
                        $io->warning("Provider '{$providerKey}' already exists in config/webhooks.php. Skipping.");
                        return false;
                    }

                    // Build new provider configuration
                    $newProvider = [
                        'secret' => '${' . strtoupper($name) . '_WEBHOOK_SECRET}',
                        'async'  => false, // for async processing
                        'event_resolver' => ['type' => 'type'], // dot notation in JSON body
                        'handler' => "App\\Webhooks\\Handlers\\" . ucfirst($name) . "WebhookHandler",
                        'handler_failure_mode' => 'stop',   // 'stop' or 'continue'
                        'verify_signature' => true, // whether to verify signature or not
                        'verify' => [
                            'type'   => 'hmac',           // or 'hmac_timestamp' for Stripe-like
                            'header' => 'X-Signature',
                            'algo'   => 'sha256',
                            'prefix' => '',
                        ],
                    ];

                    // Add comment block for user to adjust
                    $config['providers'][$providerKey] = $newProvider;

                    // Write config file with pretty printed array
                    $content = "<?php\n\nreturn [\n" . $this->varExportPretty($config, 1) . "\n];\n";
                    if ($this->writeFile($configFile, $content)) {
                        $io->note("Review the 'event_resolver' and 'verify' settings for your provider configuration.");
                        return true;
                    }
                    $io->error("Failed to update configuration.");
                    return false;
                }

                private function generateHandler(string $name, SymfonyStyle $io): bool
                {
                    $handlerName = $name . 'WebhookHandler';
                    $handlerDir = $this->getWebhookHandlersPath();
                    $handlerPath = $handlerDir . $handlerName . '.php';

                    if (!is_dir($handlerDir)) {
                        mkdir($handlerDir, 0755, true);
                    }

                    if (file_exists($handlerPath)) {
                        $io->warning("Handler {$handlerName} already exists. Skipping.");
                        return false;
                    }

                    $template = $this->getHandlerTemplate($name, $handlerName);
                    if ($this->writeFile($handlerPath, $template)) {
                        return true;
                    }
                    $io->error("Failed to create handler: {$handlerName}");
                    return false;
                }

                private function generateServiceProvider(string $name, SymfonyStyle $io): bool
                {
                    $providerName = $name . 'WebhookServiceProvider';

                    $generator = new ServiceProviderGenerator($this->artisanContainer());

                    try {
                        $options = [
                            'deferred' => false,
                            'config'   => false,
                            'register' => true,
                            'bindings' => [],
                            'singletons' => [],
                            'aliases' => [],
                        ];
                        $files = $generator->generate($providerName, $options);
                        $io->note('Service provider registered in config/providers.php.');
                        return true;
                    } catch (\Exception $e) {
                        $io->error("Failed to create service provider: " . $e->getMessage());
                        return false;
                    }
                }

                // -------------------------------------------------------------------------
                // File system helpers
                // -------------------------------------------------------------------------

                private function getControllersPath(): string
                {
                    return $this->artisanContainer()->app . '/Controllers/';
                }

                private function getConfigPath(): string
                {
                    return $this->artisanContainer()->coreConfig;
                }

                private function getWebhookHandlersPath(): string
                {
                    return $this->artisanContainer()->app . '/Webhooks/Handlers/';
                }

                private function writeFile(string $path, string $content): bool
                {
                    $dir = dirname($path);
                    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                        return false;
                    }
                    return file_put_contents($path, $content) !== false;
                }

                private function varExportPretty(array $expression, int $indent = 0): string
                {
                    $prefix = str_repeat('    ', $indent);
                    $items = [];

                    foreach ($expression as $key => $value) {
                        $keyText = is_int($key) ? $key : "'" . addcslashes((string) $key, "\\'") . "'";
                        $items[] = $prefix . '    ' . $keyText . ' => ' . $this->formatValue($value, $indent + 1);
                    }

                    return implode(",\n", $items);
                }

                private function formatValue($value, int $indent): string
                {
                    if (is_array($value)) {
                        $body = $this->varExportPretty($value, $indent);
                        if ($body === '') {
                            return '[]';
                        }

                        $closingIndent = str_repeat('    ', $indent - 1);
                        return "[\n" . $body . "\n" . $closingIndent . ']';
                    }

                    if (is_string($value)) {
                        return var_export($value, true);
                    }

                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }

                    if ($value === null) {
                        return 'null';
                    }

                    return (string) $value;
                }

                
                private function getControllerTemplate(string $name, string $className): string
                {
                    $providerKey = strtolower($name);
                    return <<<PHP
<?php

namespace App\Controllers;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractController;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookPayload;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookManager;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookSubscriptionManager;

/**
 * Webhook controller for {$name}
 */
class {$className} extends AbstractController
{
    private WebhookManager \$webhookManager;
    private WebhookSubscriptionManager \$subscriptionManager;

    public function __construct() {
        \$this->webhookManager = resolve(WebhookManager::class);
        \$this->subscriptionManager = resolve(WebhookSubscriptionManager::class);
    }

    /**
     * Handle incoming {$name} webhook requests.
     */
    public function handle(HttpRequest \$request, HttpResponse \$response): string|HttpResponse
    {
        \$providerConfig = \$this->subscriptionManager->getEventResolver('{$providerKey}');
        \$payload = WebhookPayload::fromHttpRequest(
            \$request,
            '{$providerKey}',
            'X-Request-Id',
            \$providerConfig
        );
        \$webhookResponse = \$this->webhookManager->process(\$payload);
        return \$webhookResponse->toJson();
    }
}
PHP;
                }

                private function getHandlerTemplate(string $name, string $className): string
                {
                    $lowerName = strtolower($name);
                    return <<<PHP
<?php

namespace App\Webhooks\Handlers;

use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookHandlerInterface;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookPayload;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookResponse;

/**
 * Handler for {$name} webhook events.
 */
class {$className} implements WebhookHandlerInterface
{
    /**
     * Process the incoming webhook payload.
     */
    public function handle(WebhookPayload \$payload): WebhookResponse
    {
        \$event = \$payload->getEventType();
        \$data = \$payload->getParsedData();

        // Example event-based routing:
        // match(\$event) {
        //     'payment.succeeded' => \$this->handlePaymentSucceeded(\$data),
        //     'payment.failed'    => \$this->handlePaymentFailed(\$data),
        //     default             => \$this->handleUnknownEvent(\$event),
        // };

        // Your business logic here...

        return WebhookResponse::ok(['received' => true, 'event' => \$event]);
    }

    /**
     * Define which event types this handler supports.
     * Return '*' for all events, or an array of specific event names.
     * If you return an array, only those events will be routed to this handler.
     */
    public function supportsEvent(): string|array
    {
        return '*'; // Change to e.g. ['payment.succeeded', 'payment.failed']
    }

    private function handlePaymentSucceeded(array \$data): void
    {
        // TODO: Implement
    }

    private function handlePaymentFailed(array \$data): void
    {
        // TODO: Implement
    }

    private function handleUnknownEvent(string \$event): void
    {
        // Log or ignore unknown events
    }
}
PHP;
                }
            },
        ];
    }
}