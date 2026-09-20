<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification;

use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Exceptions\NotificationException;
use Mlangeni\Machinjiri\Core\Container;

class ChannelManager
{
    /** @var array<string, ChannelInterface|callable(Container):ChannelInterface> */
    private array $channels = [];
    private array $resolved = [];

    public function __construct(private Container $app) {}

    public function register(string $name, $channel): self
    {
        $this->channels[$name] = $channel;
        unset($this->resolved[$name]);
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function channel(string $name): ?ChannelInterface
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset($this->channels[$name])) {
            throw new NotificationException(
                "Notification channel '{$name}' is not registered.",
                500,
                null,
                ['channel' => $name, 'available' => array_keys($this->channels)],
                'notification_channel'
            );
        }

        $channel = $this->channels[$name];

        if (is_callable($channel) && !$channel instanceof ChannelInterface) {
            return $this->resolved[$name] = $channel($this->app);
        }

        if (!$channel instanceof ChannelInterface) {
            $channel = $this->app->resolve($channel);

            if (!$channel instanceof ChannelInterface) {
                throw new NotificationException(
                    "Factory for notification channel '{$name}' did not return a ChannelInterface.",
                    500,
                    null,
                    ['channel' => $name],
                    'notification_channel'
                );
            }

            return $this->resolved[$name] = $channel;
        }
        
    }

    /** @return string[] */
    public function registered(): array
    {
        return array_keys($this->channels);
    }

    private function bundledChannels(): array 
    {
        return [
            'database' => \Mlangeni\Machinjiri\Core\Components\Notification\Channels\DatabaseChannel::class,
            'mail' => \Mlangeni\Machinjiri\Core\Components\Notification\Channels\MailChannel::class,
            'sms' => \Mlangeni\Machinjiri\Core\Components\Notification\Channels\SmsChannel::class,
            'webhook' => \Mlangeni\Machinjiri\Core\Components\Notification\Channels\WebhookChannel::class,
        ];
    }

    public function registerChannels(): void 
    {
        $userChannels = $this->app->configurations['notification']['channels'] ?? [];
        $channels = array_merge($this->bundledChannels(), $userChannels);
        foreach ($channels as $name => $callback) {
            if (is_callable($callback)) {
                $this->register($name, $callback);
            } else {
                $channel = $this->app->resolve($callback);
                if ($channel instanceof ChannelInterface) {
                    $this->register($name, $callback);
                }
            }
        }
    }
}