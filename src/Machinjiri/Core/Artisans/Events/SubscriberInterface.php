<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

/**
 * Contract for event subscribers.
 *
 * getSubscribedEvents() must return one of:
 *   'event.name' => 'methodName'
 *   'event.name' => ['methodName', $priority]
 *   'event.name' => [['methodA', 10], ['methodB', 0]]
 *   'event.name' => ['methodA' => 10, 'methodB' => 0]
 */
interface SubscriberInterface
{
    public static function getSubscribedEvents(): array;
}