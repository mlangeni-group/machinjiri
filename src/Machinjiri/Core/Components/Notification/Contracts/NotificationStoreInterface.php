<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Contracts;

interface NotificationStoreInterface
{
    /**
     * Persist an in‑app notification payload.
     *
     * @return mixed Implementation‑specific record id or value.
     */
    public function store(
        string $notifiableId,
        string $channel,
        array $payload,
        ?string $notificationId = null
    ): mixed;
    
}