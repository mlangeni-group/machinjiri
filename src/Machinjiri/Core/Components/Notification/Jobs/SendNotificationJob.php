<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationManager;
use Mlangeni\Machinjiri\Core\Container;

class SendNotificationJob extends BaseJob
{
    public function __construct(Container $app, array $payload = [], array $options = [])
    {
        // Set default options
        $defaultOptions = [
            'maxAttempts' => 3,
            'queue' => 'notifications',
            'timeout' => 60,
            'delay' => 0,
        ];
        
        parent::__construct($app, $payload, array_merge($defaultOptions, $options));
    }

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

        $this->addMetadata('processed_at', date('Y-m-d H:i:s'));
    }

    public function options(): array
    {
        return $this->options;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function failed(MachinjiriException $exception): void
    {
        LoggerFactory::system("queue-worker", "queue", false)
        ->warning(sprintf(
          'TestJob failed after %d attempts: %s',
          $this->getAttempts(),
          $exception->getMessage()
        ));
    }
}