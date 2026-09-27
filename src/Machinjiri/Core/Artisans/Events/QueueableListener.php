<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

/**
 * Marker interface for listeners that should be deferred to a queue
 * rather than executed inline (emails, webhooks, audit writes, …).
 */
interface QueueableListener
{
}