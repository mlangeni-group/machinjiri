<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Contracts;

use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;

interface ChannelInterface
{
    /**
     * Logical name (must match the key used in Notification::via()).
     */
    public function name(): string;

    /**
     * Deliver the notification for a single recipient.
     */
    public function send(Notification $notification, NotifiableInterface $notifiable): NotificationResponse;
}