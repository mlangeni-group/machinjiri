<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Channels;

use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotificationStoreInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;
use Mlangeni\Machinjiri\Core\Components\Notification\Stores\LoggerNotificationStore;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;

class DatabaseChannel implements ChannelInterface
{
    public function __construct(private QueryBuilder $queryBuilder) {}

    public static function withFallback(): self
    {
        return new self(new LoggerNotificationStore());
    }

    public function name(): string
    {
        return 'database';
    }

    public function send(Notification $notification, NotifiableInterface $notifiable): NotificationResponse
    {
        $payload = $notification->toDatabase($notifiable);

        if ($payload === null) {
            return NotificationResponse::failure(
                $this->name(),
                'Notification does not provide a database payload.'
            );
        }

        $payload = array_merge($payload, [
            'notification_id' => $notification->id(),
            'notification'    => get_class($notification),
            'created_at'      => date(DATE_ATOM),
        ]);

        try {
            $result = $this->store(
                $notifiable->getNotifiableId(),
                $this->name(),
                $payload,
                $notification->id()
            );

            $record = (isset($result['lastInsertId']) && $result['lastInsertId']) ? $result['lastInsertId'] : 0;

            return NotificationResponse::success($this->name(), $record);
        } catch (\Throwable $e) {
            return NotificationResponse::failure(
                $this->name(),
                $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    private function store(string $id, string $name, array $payload, mixed $notificationId): array 
    {
        return $this->queryBuilder
            ->insert([
                "notifierId" => $id,
                "name" => $name,
                "payload" => json_encode($payload),
                "notificationId" => $notificationId
            ])
            ->execute();
    }
}