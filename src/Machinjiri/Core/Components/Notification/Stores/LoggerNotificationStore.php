<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Stores;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotificationStoreInterface;

class LoggerNotificationStore implements NotificationStoreInterface
{
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::system('notification', 'store', false);
    }

    public function store(
        string $notifiableId,
        string $channel,
        array $payload,
        ?string $notificationId = null
    ): mixed {
        $record = [
            'id'              => $notificationId ?? bin2hex(random_bytes(12)),
            'notifiable_id'   => $notifiableId,
            'channel'         => $channel,
            'payload'         => $payload,
            'created_at'      => date(DATE_ATOM),
        ];

        $this->logger->info('Notification stored', $record);

        return $record['id'];
    }
}