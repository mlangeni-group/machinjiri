<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithEvents
{
    private array $eventFakeDispatched = [];

    protected function setUpEventFake(): void
    {
        $this->eventFakeDispatched = [];
        $this->bind('event.dispatcher', function () {
            return new class {
                public function dispatch($event, $payload = [])
                {
                    $GLOBALS['__event_fake_dispatched'][] = ['event' => $event, 'payload' => $payload];
                }
            };
        });
    }

    protected function assertEventDispatched(string $eventClass, ?callable $callback = null): void
    {
        $events = $GLOBALS['__event_fake_dispatched'] ?? [];
        $found = false;
        foreach ($events as $ev) {
            if ($ev['event'] === $eventClass) {
                if (!$callback || $callback($ev['payload'])) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, "Event {$eventClass} was not dispatched.");
    }

    protected function tearDownEventFake(): void
    {
        unset($GLOBALS['__event_fake_dispatched']);
    }
}