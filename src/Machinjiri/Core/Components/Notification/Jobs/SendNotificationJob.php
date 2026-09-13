<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationManager;
use Mlangeni\Machinjiri\Core\Container;

class SendNotificationJob
{
    public function __construct(
        private Container $app,
        private array $payload,
        private array $options = []
    ) {}

    public function handle(): void
    {
        /** @var NotificationManager $manager */
        $manager = $this->app->make(NotificationManager::class);
        $logger  = $this->app->bound(Logger::class)
            ? $this->app->make(Logger::class)
            : null;

        /** @var Notification $notification */
        $notification = $this->payload['notification'];
        /** @var NotifiableInterface $notifiable */
        $notifiable   = $this->payload['notifiable'];

        $result = $manager->sendNow($notifiable, $notification);

        if ($logger) {
            $logger->info('SendNotificationJob processed', [
                'notification' => get_class($notification),
                'notifiable'   => $notifiable->getNotifiableId(),
                'result'       => $result->jsonSerialize(),
            ]);
        }
    }

    public function options(): array
    {
        return $this->options;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}