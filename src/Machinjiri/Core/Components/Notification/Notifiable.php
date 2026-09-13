<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification;

use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;

trait Notifiable
{
    public function notify(Notification $notification): NotificationResult
    {
        return NotificationManager::instance()->send($this, $notification);
    }

    public function notifyNow(Notification $notification): NotificationResult
    {
        return NotificationManager::instance()->sendNow($this, $notification);
    }

    public function notifyAsync(Notification $notification, ?string $queue = null): array
    {
        return NotificationManager::instance()->queue($this, $notification, $queue);
    }

    public function routeNotificationFor(string $channel): mixed
    {
        return match ($channel) {
            'mail'    => $this->email ?? $this->email_address ?? null,
            'sms'     => $this->phone ?? $this->phone_number ?? $this->mobile ?? null,
            'webhook' => $this->webhook_url ?? null,
            default   => null,
        };
    }

    public function getNotifiableId(): string
    {
        if (isset($this->uuid) && $this->uuid) {
            return (string) $this->uuid;
        }

        if (isset($this->id) && $this->id) {
            return (string) $this->id;
        }

        return spl_object_hash($this);
    }
}