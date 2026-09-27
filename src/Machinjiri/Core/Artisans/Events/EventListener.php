<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class EventListener
{
    /** @var array<string, array<int, array<int, array{listener: callable, once: bool}>>> */
    protected array $listeners = [];

    /** @var array<string, array<int, array{listener: callable, once: bool}>> Memoized flattened, sorted lists */
    protected array $sortedCache = [];

    /** @var array<int, string> Recursion guard stack */
    protected array $dispatchStack = [];

    /** @var int Maximum recursion depth */
    protected int $maxDepth = 32;

    /** @var callable|null Optional queue dispatcher for QueueableListener instances */
    protected $queueDispatcher = null;

    protected Logger $logger;

    /** @var self|null */
    private static ?self $instance = null;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /** True singleton (previous impl returned a new instance each call). */
    public static function getInstance(Logger $logger): self
    {
        return self::$instance ??= new self($logger);
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function setQueueDispatcher(callable $dispatcher): void
    {
        $this->queueDispatcher = $dispatcher;
    }

    /* ---------------------------------------------------------------------
     |  Registration
     * ------------------------------------------------------------------- */

    public function on(string $event, callable $listener, int $priority = 0): void
    {
        $event = $this->normalizeEvent($event);
        $this->listeners[$event][$priority][] = ['listener' => $listener, 'once' => false];
        $this->invalidateCache();

        $this->logger->info("Registered listener for event: {$event}", [
            'priority' => $priority,
            'listener' => self::describeListener($listener),
        ]);
    }

    public function once(string $event, callable $listener, int $priority = 0): void
    {
        $event = $this->normalizeEvent($event);
        $this->listeners[$event][$priority][] = ['listener' => $listener, 'once' => true];
        $this->invalidateCache();

        $this->logger->info("Registered once-listener for event: {$event}", [
            'priority' => $priority,
            'listener' => self::describeListener($listener),
        ]);
    }

    public function subscribe(object $subscriber): void
    {
        if (!method_exists($subscriber, 'getSubscribedEvents')) {
            throw new MachinjiriException(
                "Subscriber [" . get_class($subscriber) . "] must implement getSubscribedEvents()."
            );
        }

        foreach ($subscriber->getSubscribedEvents() as $event => $handler) {
            foreach ($this->normalizeSubscriberHandler($handler) as [$method, $priority]) {
                $this->on($event, [$subscriber, $method], $priority);
            }
        }
    }

    protected function normalizeSubscriberHandler($handler): array
    {
        // 'methodName'
        if (is_string($handler)) {
            return [[$handler, 0]];
        }

        // ['methodName', priority]
        if (isset($handler[0]) && is_string($handler[0])) {
            return [[$handler[0], (int) ($handler[1] ?? 0)]];
        }

        // [['methodA', 10], ['methodB', 0]]
        if (isset($handler[0]) && is_array($handler[0])) {
            $out = [];
            foreach ($handler as $spec) {
                $out[] = [$spec[0], (int) ($spec[1] ?? 0)];
            }
            return $out;
        }

        // ['methodA' => 10, 'methodB' => 0]
        $out = [];
        foreach ($handler as $method => $priority) {
            if (is_string($method)) {
                $out[] = [$method, (int) $priority];
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     |  Dispatch
     * ------------------------------------------------------------------- */

    /**
     * @return array|mixed|null  Array of responses, or the first non-null response when $halt is true.
     */
    public function trigger(string $event, $payload = null, bool $halt = false, ?int $eventId = null)
    {
        $event   = $this->normalizeEvent($event);
        $eventId = $eventId ?? Factory::generateEventId();

        $this->guardRecursion($event);

        $this->logger->info("Event triggered: {$event}", [
            'id'            => $eventId,
            'payload'       => $payload,
            'has_listeners' => $this->hasListeners($event),
        ]);

        $this->dispatchStack[] = $event;

        try {
            $responses = [];
            // Frozen snapshot – listeners registered during dispatch are NOT seen now.
            $snapshot  = $this->resolveListeners($event);

            foreach ($snapshot as $entry) {
                $listener = $entry['listener'];

                // Deferred / queued listeners
                if ($listener instanceof QueueableListener && $this->queueDispatcher !== null) {
                    ($this->queueDispatcher)($listener, $event, $payload);
                    $this->logger->debug("Deferred listener for event: {$event}", [
                        'listener' => self::describeListener($listener),
                    ]);
                    continue;
                }

                try {
                    $response = call_user_func($listener, $payload, $event);
                } catch (\Throwable $e) {
                    $this->logger->error("Listener failed for event: {$event}", [
                        'listener' => self::describeListener($listener),
                        'error'    => $e->getMessage(),
                    ]);
                    if ($halt) {
                        throw $e;
                    }
                    continue; // fail-isolated by default
                }

                if ($entry['once']) {
                    $this->removeListener($event, $listener);
                }

                if ($halt && $response !== null) {
                    $this->logger->debug("Event halted: {$event}", [
                        'response' => $response,
                        'listener' => self::describeListener($listener),
                    ]);
                    return $response;
                }

                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $halt ? null : $responses;
        } finally {
            array_pop($this->dispatchStack);
        }
    }

    /** PSR-14–shaped dispatch – accepts any object. */
    public function dispatch(object $event)
    {
        if ($event instanceof DispatchedEvent) {
            return $this->trigger($event->name(), $event->payload(), false, $event->id());
        }
        $name = property_exists($event, 'name') ? $event->name : get_class($event);
        return $this->trigger($name, $event);
    }

    public function until(string $event, $payload = null)
    {
        return $this->trigger($event, $payload, true);
    }

    /* ---------------------------------------------------------------------
     |  Removal
     * ------------------------------------------------------------------- */

    public function removeListener(string $event, callable $removeListener): bool
    {
        $event = $this->normalizeEvent($event);
        if (!isset($this->listeners[$event])) {
            return false;
        }

        foreach ($this->listeners[$event] as $priority => $entries) {
            foreach ($entries as $key => $entry) {
                if ($entry['listener'] === $removeListener) {
                    unset($this->listeners[$event][$priority][$key]);
                    if (empty($this->listeners[$event][$priority])) {
                        unset($this->listeners[$event][$priority]);
                    }
                    if (empty($this->listeners[$event])) {
                        unset($this->listeners[$event]);
                    }
                    $this->invalidateCache();

                    $this->logger->info("Removed listener from event: {$event}", [
                        'priority' => $priority,
                    ]);
                    return true;
                }
            }
        }
        return false;
    }

    public function removeAllListeners(string $event): void
    {
        $event = $this->normalizeEvent($event);
        if (isset($this->listeners[$event])) {
            unset($this->listeners[$event]);
            $this->invalidateCache();
            $this->logger->info("Removed all listeners for event: {$event}");
        }
    }

    public function flushAll(): void
    {
        $this->listeners   = [];
        $this->sortedCache = [];
        $this->logger->info("Flushed all event listeners.");
    }

    /* ---------------------------------------------------------------------
     |  Introspection
     * ------------------------------------------------------------------- */

    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }

    public function getListeners(string $event): array
    {
        return array_map(
            static fn($e) => $e['listener'],
            $this->resolveListeners($this->normalizeEvent($event))
        );
    }

    public function hasListeners(string $event): bool
    {
        $event = $this->normalizeEvent($event);
        if (!empty($this->listeners[$event])) {
            return true;
        }
        foreach ($this->listeners as $pattern => $_) {
            if (strpos($pattern, '*') !== false && fnmatch($pattern, $event)) {
                return true;
            }
        }
        return false;
    }

    public function displayEvents(): void
    {
        $events = $this->getEvents();
        if (empty($events)) {
            echo "No events registered.\n";
            return;
        }

        echo "Registered Events:\n=================\n";
        foreach ($events as $event) {
            $count = count($this->getListeners($event));
            echo "Event: {$event} ({$count} listener(s))\n";
            foreach ($this->listeners[$event] as $priority => $entries) {
                foreach ($entries as $entry) {
                    $tag = $entry['once'] ? ' (once)' : '';
                    echo "  - Priority: {$priority}, Listener: "
                        . self::describeListener($entry['listener']) . $tag . "\n";
                }
            }
            echo "\n";
        }
    }

    /* ---------------------------------------------------------------------
     |  Internals
     * ------------------------------------------------------------------- */

    /**
     * Return the flattened, priority-sorted list of listeners matching $event.
     * Wildcard patterns (e.g. "user.*") are honoured.
     */
    protected function resolveListeners(string $event): array
    {
        if (isset($this->sortedCache[$event])) {
            return $this->sortedCache[$event];
        }

        $matched = [];
        foreach ($this->listeners as $pattern => $priorities) {
            $isMatch = ($pattern === $event)
                || (strpos($pattern, '*') !== false && fnmatch($pattern, $event));

            if (!$isMatch) {
                continue;
            }

            foreach ($priorities as $priority => $entries) {
                foreach ($entries as $entry) {
                    $matched[$priority][] = $entry;
                }
            }
        }

        krsort($matched);

        $flat = [];
        foreach ($matched as $entries) {
            foreach ($entries as $entry) {
                $flat[] = $entry;
            }
        }

        return $this->sortedCache[$event] = $flat;
    }

    protected function invalidateCache(): void
    {
        $this->sortedCache = [];
    }

    protected function guardRecursion(string $event): void
    {
        if (in_array($event, $this->dispatchStack, true)) {
            throw new MachinjiriException("Circular event dispatch detected for [{$event}].");
        }
        if (count($this->dispatchStack) >= $this->maxDepth) {
            throw new MachinjiriException(
                "Maximum event dispatch depth of {$this->maxDepth} exceeded (event: {$event})."
            );
        }
    }

    /** Preserves "*" so wildcard patterns survive normalisation. */
    private function normalizeEvent(string $event): string
    {
        return strtolower(str_replace(
            [' ', '-', '_', '/', '\\', '[', ']'],
            '.',
            $event
        ));
    }

    public static function describeListener(callable $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }
        if (is_array($listener)) {
            $target = $listener[0] ?? null;
            $method = $listener[1] ?? '__invoke';
            if (is_object($target)) {
                return get_class($target) . '::' . $method;
            }
            return (string) $target . '::' . $method;
        }
        if ($listener instanceof \Closure) {
            return 'Closure';
        }
        if (is_object($listener)) {
            return get_class($listener) . '::__invoke';
        }
        return 'callable';
    }
}