<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Contracts;

interface NotifiableInterface
{
    /**
     * Resolve the routing target for a given channel
     * (e.g. an email address, phone number, webhook URL, or user id).
     */
    public function routeNotificationFor(string $channel): mixed;

    /**
     * Stable identifier used for tracking and de‑duplication.
     */
    public function getNotifiableId(): string;
}