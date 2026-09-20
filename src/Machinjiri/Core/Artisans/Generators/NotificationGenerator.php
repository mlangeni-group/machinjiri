<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Generators;

use Mlangeni\Machinjiri\Core\Container;

class NotificationGenerator
{
    private Container $app;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Generate a new Notification class.
     *
     * @param string   $name      Class name (e.g. "InvoicePaid")
     * @param string[] $channels  Channels to scaffold (mail, sms, database, webhook)
     * @param bool     $queueable Whether to add onQueue() to the constructor
     */
    public function create(string $name, array $channels = ['mail'], bool $queueable = false): bool
    {
        $name = $this->normalizeClassName($name);

        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name)) {
            return false;
        }

        $dir = $this->notificationsPath();

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $file = $dir . DIRECTORY_SEPARATOR . $name . '.php';

        if (file_exists($file)) {
            return false;
        }

        $stub = $this->buildStub($name, $channels, $queueable);

        return @file_put_contents($file, $stub) !== false;
    }

    /* ------------------------------------------------------------------ */

    private function notificationsPath(): string
    {
        if (function_exists('app_path')) {
            return app_path('Notifications');
        }

        return $this->app->app . 'Notifications';
    }

    private function normalizeClassName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['/', '\\'], ' ', $name);
        $name = str_replace(['-', '_'], ' ', $name);
        $name = str_replace(' ', '', ucwords($name));

        return $name;
    }

    /**
     * @param string[] $channels
     */
    private function buildStub(string $name, array $channels, bool $queueable): string
    {
        $channels = array_values(array_unique(array_map('strtolower', $channels)));
        $viaArray = "'" . implode("', '", $channels) . "'";
        $ctorBody = $queueable ? "        \$this->onQueue('notifications');\n" : '';

        $methods = [];

        foreach ($channels as $channel) {
            $methods[] = match ($channel) {
                'mail'     => $this->mailMethod(),
                'sms'      => $this->smsMethod(),
                'database' => $this->databaseMethod(),
                'webhook'  => $this->webhookMethod(),
                default    => $this->customMethod($channel),
            };
        }

        $methodsBlock = implode("\n", array_filter($methods));

        return <<<PHP
<?php

namespace App\Notifications;

use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;

class {$name} extends Notification
{
    public function __construct()
    {
{$ctorBody}    }

    public function via(NotifiableInterface \$notifiable): array
    {
        return [{$viaArray}];
    }

{$methodsBlock}
}
PHP;
    }

    private function mailMethod(): string
    {
        return <<<'PHP'
    public function toMail(NotifiableInterface $notifiable): array
    {
        return [
            'subject' => 'Notification subject',
            'html'    => '<p>Your HTML body here.</p>',
            'text'    => 'Your plain-text body here.',
        ];
    }
PHP;
    }

    private function smsMethod(): string
    {
        return <<<'PHP'
    public function toSms(NotifiableInterface $notifiable): array
    {
        return [
            'body' => 'Your SMS body here.',
        ];
    }
PHP;
    }

    private function databaseMethod(): string
    {
        return <<<'PHP'
    public function toDatabase(NotifiableInterface $notifiable): array
    {
        return [
            'type'  => 'generic',
            'title' => 'Notification title',
            'body'  => 'Notification body.',
        ];
    }
PHP;
    }

    private function webhookMethod(): string
    {
        return <<<'PHP'
    public function toWebhook(NotifiableInterface $notifiable): array
    {
        return [
            'url'   => $notifiable->routeNotificationFor('webhook') ?? '',
            'event' => 'generic',
            'data'  => ['message' => 'Notification body.'],
        ];
    }
PHP;
    }

    private function customMethod(string $channel): string
    {
        $method = 'to' . ucfirst($channel);

        return <<<PHP
    public function {$method}(NotifiableInterface \$notifiable): mixed
    {
        return null;
    }
PHP;
    }
}