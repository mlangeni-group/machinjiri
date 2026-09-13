<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Components\Notification\Channels\{DatabaseChannel, MailChannel, SmsChannel, WebhookChannel};
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Exceptions\NotificationException;
use Mlangeni\Machinjiri\Core\Components\Notification\Jobs\SendNotificationJob;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;
use Mlangeni\Machinjiri\Core\Transport\SMS\SMSManager;

class NotificationManager
{
    private Container $app;
    private ChannelManager $channels;
    private Logger $logger;
    private ?EventListener $eventListener;
    private ?JobDispatcherInterface $dispatcher;

    private static ?NotificationManager $instance = null;

    public function __construct(
        Container $app,
        ?ChannelManager $channels = null,
        ?Logger $logger = null,
        ?EventListener $eventListener = null,
        ?JobDispatcherInterface $dispatcher = null
    ) {
        $this->app = $app;

        $this->logger = $logger ?? ($app->bound(Logger::class)
            ? $app->make(Logger::class)
            : LoggerFactory::system('notification', 'component', false));

        $this->eventListener = $eventListener ?? ($app->bound(EventListener::class)
            ? $app->make(EventListener::class)
            : null);

        $this->dispatcher = $dispatcher ?? ($app->bound(JobDispatcherInterface::class)
            ? $app->make(JobDispatcherInterface::class)
            : null);

        $this->channels = $channels ?? $this->buildDefaultChannelManager();

        self::$instance = $this;
    }

    /* -----------------------------------------------------------------
     |  Static accessor (used by the Notifiable trait)
     | ----------------------------------------------------------------- */

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new NotificationException(
                'NotificationManager has not been instantiated yet.',
                500,
                null,
                [],
                'notification_config'
            );
        }

        return self::$instance;
    }

    public static function setInstance(?self $manager): void
    {
        self::$instance = $manager;
    }

    /* -----------------------------------------------------------------
     |  Channel registration
     | ----------------------------------------------------------------- */

    public function channels(): ChannelManager
    {
        return $this->channels;
    }

    public function extend(string $name, callable|Contracts\ChannelInterface $channel): self
    {
        $this->channels->register($name, $channel);
        return $this;
    }

    /* -----------------------------------------------------------------
     |  Sync delivery
     | ----------------------------------------------------------------- */

    /**
     * Send the notification synchronously to one or more notifiables.
     *
     * @param NotifiableInterface|NotifiableInterface[] $notifiables
     */
    public function send(NotifiableInterface|array $notifiables, Notification $notification): NotificationResult
    {
        return $this->deliver($notifiables, $notification);
    }

    /**
     * Alias of send() — expressively means "do not queue".
     *
     * @param NotifiableInterface|NotifiableInterface[] $notifiables
     */
    public function sendNow(NotifiableInterface|array $notifiables, Notification $notification): NotificationResult
    {
        return $this->deliver($notifiables, $notification);
    }

    /* -----------------------------------------------------------------
     |  Async delivery
     | ----------------------------------------------------------------- */

    /**
     * Queue the notification for one or more notifiables.
     *
     * @return string[] Dispatched job ids (one per notifiable).
     */
    public function queue(NotifiableInterface|array $notifiables, Notification $notification, ?string $queue = null): array
    {
        if (!$this->dispatcher) {
            throw new NotificationException(
                'Job dispatcher not configured. Bind a JobDispatcherInterface implementation.',
                500,
                null,
                [],
                'notification_config'
            );
        }

        $queue ??= $notification->queueName();

        $ids = [];
        foreach ($this->normalizeNotifiables($notifiables) as $notifiable) {
            $job = new SendNotificationJob($this->app, [
                'notification' => $notification,
                'notifiable'   => $notifiable,
            ], [
                'queue' => $queue,
                'delay' => $notification->delaySeconds(),
            ]);

            $ids[] = $this->dispatchJob($job, $queue);

            $this->logger->info('Notification queued', [
                'notification' => get_class($notification),
                'notifiable'   => $notifiable->getNotifiableId(),
                'queue'        => $queue,
            ]);

            $this->eventListener?->trigger('notification.queued', [
                'notification' => $notification,
                'notifiable'   => $notifiable,
                'queue'        => $queue,
            ]);
        }

        return $ids;
    }

    /* -----------------------------------------------------------------
     |  Internals
     | ----------------------------------------------------------------- */

    /**
     * @param NotifiableInterface|NotifiableInterface[] $notifiables
     */
    private function deliver(NotifiableInterface|array $notifiables, Notification $notification): NotificationResult
    {
        $result = new NotificationResult();

        foreach ($this->normalizeNotifiables($notifiables) as $notifiable) {
            foreach ($this->resolveChannels($notification, $notifiable) as $channelName) {
                $result->add($this->sendViaChannel($notification, $notifiable, $channelName));
            }
        }

        return $result;
    }

    private function sendViaChannel(
        Notification $notification,
        NotifiableInterface $notifiable,
        string $channelName
    ): NotificationResponse {
        $this->eventListener?->trigger('notification.sending', [
            'notification' => $notification,
            'notifiable'   => $notifiable,
            'channel'      => $channelName,
        ]);

        try {
            $channel = $this->channels->channel($channelName);
            $response = $channel->send($notification, $notifiable);

            if ($response->success) {
                $this->logger->info('Notification sent', [
                    'notification' => get_class($notification),
                    'channel'      => $channelName,
                    'notifiable'   => $notifiable->getNotifiableId(),
                ]);

                $this->eventListener?->trigger('notification.sent', [
                    'notification' => $notification,
                    'notifiable'   => $notifiable,
                    'channel'      => $channelName,
                    'response'     => $response,
                ]);
            } else {
                $this->logger->warning('Notification channel reported a failure', [
                    'notification' => get_class($notification),
                    'channel'      => $channelName,
                    'notifiable'   => $notifiable->getNotifiableId(),
                    'error'        => $response->error,
                ]);

                $this->eventListener?->trigger('notification.failed', [
                    'notification' => $notification,
                    'notifiable'   => $notifiable,
                    'channel'      => $channelName,
                    'response'     => $response,
                ]);
            }

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('Notification channel threw an exception', [
                'notification' => get_class($notification),
                'channel'      => $channelName,
                'notifiable'   => $notifiable->getNotifiableId(),
                'error'        => $e->getMessage(),
            ]);

            $this->eventListener?->trigger('notification.failed', [
                'notification' => $notification,
                'notifiable'   => $notifiable,
                'channel'      => $channelName,
                'exception'    => $e,
            ]);

            return NotificationResponse::failure(
                $channelName,
                $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * @return string[]
     */
    private function resolveChannels(Notification $notification, NotifiableInterface $notifiable): array
    {
        $channels = $notification->via($notifiable);

        if (!is_array($channels)) {
            throw new NotificationException(
                sprintf('Notification %s::via() must return an array.', get_class($notification)),
                500,
                null,
                ['notification' => get_class($notification)],
                'notification_config'
            );
        }

        $channels = array_values(array_unique($channels));

        return array_values(array_filter($channels, function (string $channel) use ($notification, $notifiable) {
            if (!$this->channels->has($channel)) {
                $this->logger->warning('Skipping unregistered notification channel', [
                    'notification' => get_class($notification),
                    'channel'      => $channel,
                ]);
                return false;
            }

            return $notification->shouldSend($notifiable, $channel);
        }));
    }

    private function normalizeNotifiables(NotifiableInterface|array $notifiables): array
    {
        if ($notifiables instanceof NotifiableInterface) {
            return [$notifiables];
        }

        foreach ($notifiables as $notifiable) {
            if (!$notifiable instanceof NotifiableInterface) {
                throw new NotificationException(
                    'All notifiables must implement NotifiableInterface.',
                    500,
                    null,
                    ['received' => is_object($notifiable) ? get_class($notifiable) : gettype($notifiable)],
                    'notification_config'
                );
            }
        }

        return array_values($notifiables);
    }

    private function dispatchJob(object $job, ?string $queue): string
    {
        if ($queue && method_exists($this->dispatcher, 'dispatchToQueue')) {
            return (string) $this->dispatcher->dispatchToQueue($job, $queue);
        }

        return (string) $this->dispatcher->dispatch($job);
    }

    private function buildDefaultChannelManager(): ChannelManager
    {
        $manager = new ChannelManager($this->app);

        // Mail – resolved lazily; MailManager is registered as a singleton in the container.
        $manager->register('mail', function (Container $app) {
            /** @var MailManager $mailer */
            $mailer = $app->bound(MailManager::class)
                ? $app->make(MailManager::class)
                : $app->make(MailManager::class);

            return new MailChannel($mailer);
        });

        // SMS
        $manager->register('sms', function (Container $app) {
            /** @var SMSManager $sms */
            $sms = $app->bound(SMSManager::class)
                ? $app->make(SMSManager::class)
                : $app->make(SMSManager::class);

            return new SmsChannel($sms);
        });

        // Database – uses a bound NotificationStoreInterface if available.
        $manager->register('database', function (Container $app) {
            $store = $app->bound(Contracts\NotificationStoreInterface::class)
                ? $app->make(Contracts\NotificationStoreInterface::class)
                : null;

            return $store
                ? new DatabaseChannel($store)
                : DatabaseChannel::withFallback();
        });

        // Webhook
        $manager->register('webhook', function (Container $app) {
            return new WebhookChannel($this->logger);
        });

        return $manager;
    }
}