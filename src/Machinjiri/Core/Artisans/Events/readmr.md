# Machinjiri Event System

A lightweight, PSR-14–inspired event dispatcher for the Machinjiri framework. It cleanly separates **system event logging** (`Factory`) from **application-level event dispatching** (`Event`), while still allowing system events to be both logged and dispatched from a single call site.

---

## Table of Contents

1. [Design Overview](#design-overview)
2. [Features](#features)
3. [Directory Layout](#directory-layout)
4. [Quick Start](#quick-start)
5. [Bootstrapping](#bootstrapping)
6. [The `Event` Façade](#the-event-façade)
   - [Core Dispatcher API](#core-dispatcher-api)
   - [User Events](#user-events)
   - [System Events](#system-events)
7. [The `Factory` System Logger](#the-factory-system-logger)
   - [Module Shortcuts](#module-shortcuts)
   - [Raw Logging API](#raw-logging-api)
8. [Registering Listeners](#registering-listeners)
9. [Dispatching Events](#dispatching-events)
10. [Wildcard Listeners](#wildcard-listeners)
11. [Once Listeners](#once-listeners)
12. [Subscribers](#subscribers)
13. [Deferred / Queued Listeners](#deferred--queued-listeners)
14. [Removing Listeners](#removing-listeners)
15. [Introspection & Debugging](#introspection--debugging)
16. [Recursion Protection](#recursion-protection)
17. [Error Handling](#error-handling)
18. [Where Logs Go](#where-logs-go)
19. [API Reference](#api-reference)
20. [Testing](#testing)
21. [FAQ](#faq)

---

## Design Overview

Two classes, two responsibilities:

```
                    ┌────────────────────────────────────────┐
                    │                Event                   │
                    │   user + system event façade           │
                    │                                        │
                    │   User events ─────► emit() ──┐        │
                    │   System events ──► emit()    │        │
                    │        + Factory::log() ──────┤        │
                    └───────────────────────────────┼────────┘
                                                    │
                              ┌─────────────────────┴───────────────┐
                              ▼                                     ▼
                     ┌──────────────────┐                ┌──────────────────┐
                     │  EventListener   │                │     Factory      │
                     │   (dispatcher)   │                │  (system logger) │
                     └──────────────────┘                └────────┬─────────┘
                                                                  ▼
                                                        ┌──────────────────┐
                                                        │  LoggerFactory   │
                                                        │   ::system(...)  │
                                                        └──────────────────┘
```

| Class | Role | Dispatches? | Writes logs? |
|---|---|---|---|
| **`Event`** | Combined façade for **user + system** events | ✅ | ✅ for system events (via `Factory`) |
| **`Factory`** | Framework-internal **system event logger** | ❌ | ✅ always |
| **`EventListener`** | The actual dispatcher | ✅ | ✅ registration/dispatch traces |
| **`DispatchedEvent`** | Typed event DTO | — | — |
| **`SubscriberInterface`** | Contract for subscriber classes | — | — |
| **`QueueableListener`** | Marker for deferred listeners | — | — |

**Rule of thumb**

- From **application code** (controllers, services, jobs): call `Event::*`.
- From **framework internals** where you only want to write a log line: call `Factory::*`.
- `Event`'s system helpers call `Factory` under the hood, so the log file always reflects what was dispatched.

---

## Features

- **Priority-ordered dispatch** — higher priority runs first.
- **Wildcard listeners** — subscribe to `user.*` and receive `user.login`, `user.logout`, etc.
- **Once-listeners** — auto-removed after first invocation.
- **Subscribers** — bundle many listeners into a single class.
- **Deferred / queued listeners** — offload work to a queue via a marker interface.
- **Recursion protection** — circular dispatch detection + max depth.
- **Fail-isolated dispatch** — a throwing listener doesn't kill the chain (unless `halt: true`).
- **Frozen snapshots** — listeners registered mid-dispatch don't fire in the same pass.
- **PSR-14-style `dispatch(object $event)`**.
- **Structured logging** — every system event lands in a module-namespaced log file.
- **Static façade** — `Event::userLogin($user)` is all you need at call sites.
- **Module-scoped IDs** — `Factory::database(...)` returns an ID offset by the database module base.

---

## Directory Layout

```
Core/Artisans/Events/
├── Event.php                 # Combined user + system façade
├── EventListener.php         # The dispatcher
├── DispatchedEvent.php       # Typed event DTO
├── Factory.php               # System event logger + ID generator
├── SubscriberInterface.php   # Contract for subscribers
└── QueueableListener.php     # Marker for deferred listeners
```

---

## Quick Start

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\Event;

// 1. Register a listener for a user event
Event::on('user.login', function ($payload) {
    error_log("User {$payload['user']->id} logged in.");
}, priority: 10);

// 2. Fire a user event
Event::userLogin($user, ['ip' => '10.0.0.1']);

// 3. Fire a system event (dispatches AND logs)
Event::jobFailed($job, $exception);

// 4. Log a system event without dispatching (framework internals)
use Mlangeni\Machinjiri\Core\Artisans\Events\Factory;
Factory::database('connection.opened', ['dsn' => 'mysql:host=…']);
```

---

## Bootstrapping

The `Event` façade lazily boots itself on first use. If you want a specific `Logger`, call `boot()` explicitly during startup:

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\Event;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

// In your bootstrap file
$logger = LoggerFactory::system('events', 'core', true);
Event::boot($logger);
```

To reset (useful for tests):

```php
Event::reset();
```

---

## The `Event` Façade

### Core Dispatcher API

These methods delegate straight to `EventListener`:

| Method | Description |
|---|---|
| `Event::on($event, $listener, $priority = 0)` | Register a listener |
| `Event::once($event, $listener, $priority = 0)` | Register a once-listener |
| `Event::subscribe($subscriber)` | Register a subscriber object |
| `Event::trigger($event, $payload, $halt, $eventId)` | Dispatch with full control |
| `Event::emit($event, $payload)` | Dispatch with auto-generated ID |
| `Event::dispatch(object $event)` | PSR-14 style |
| `Event::until($event, $payload)` | Dispatch, halt on first non-null |
| `Event::off($event, $listener)` | Remove one listener |
| `Event::flush($event)` | Remove all listeners for an event |
| `Event::flushAll()` | Remove everything |
| `Event::has($event)` | Does this event have listeners? |
| `Event::events()` | List registered event names |
| `Event::listeners($event)` | List listeners for an event |
| `Event::display()` | Pretty-print everything |
| `Event::setQueueDispatcher(callable)` | Install a queue delegate |

### User Events

User events **only dispatch** — they do not write to system log files. Payloads are always shaped as `['user' => $user, 'context' => [...]]` (plus extra keys where relevant).

```php
Event::userLogin($user, $context = []);
Event::userLogout($user, $context = []);
Event::userRegistered($user, $context = []);
Event::userUpdated($user, $context = []);
Event::userDeleted($user, $context = []);
Event::userPasswordChanged($user, $context = []);
Event::userPasswordResetRequested($user, $context = []);
Event::userPasswordReset($user, $context = []);
Event::userEmailVerified($user, $context = []);
Event::userProfileUpdated($user, $context = []);
Event::userRoleAssigned($user, $role, $context = []);
Event::userRoleRevoked($user, $role, $context = []);

// Authentication
Event::userAuthenticated($user, $context = []);
Event::userAuthenticationFailed($identifier, $context = []);
Event::userLockedOut($user, $context = []);
Event::userTwoFactorEnabled($user, $context = []);
Event::userTwoFactorDisabled($user, $context = []);
```

Normalized event names: `user.login`, `user.role.assigned`, `auth.2fa.enabled`, …

### System Events

System events **dispatch and log**. Internally they call `Factory::<module>(...)` *and* `emit(...)`.

#### Core / application

```php
Event::appBooted($context = []);
Event::appShutdown($context = []);
Event::exceptionThrown(\Throwable $e, $context = []);
```

#### HTTP / routing

```php
Event::requestHandled($request, $response, $context = []);
Event::requestRejected($request, $context = []);
Event::routeMatched(string $route, $context = []);
```

#### Queue / jobs

```php
Event::jobDispatched($job, $context = []);
Event::jobCompleted($job, $context = []);
Event::jobFailed($job, \Throwable $e, $context = []);
```

#### Cache

```php
Event::cacheHit(string $key, $context = []);
Event::cacheMissed(string $key, $context = []);
Event::cacheFlushed($context = []);
```

#### Database

```php
Event::databaseQueryExecuted(string $sql, array $bindings = [], float $durationMs = 0, array $context = []);
Event::migrationCompleted(string $migration, array $context = []);
```

#### Security

```php
Event::securityBreachDetected(string $reason, $context = []);
Event::accessDenied($user, string $resource, $context = []);
```

#### Filesystem

```php
Event::fileUploaded(string $path, $context = []);
Event::fileDeleted(string $path, $context = []);
```

#### Notifications / webhooks

```php
Event::notificationSent(string $channel, $recipient, $context = []);
Event::webhookReceived(string $source, array $payload = [], $context = []);
Event::webhookDelivered(string $target, $context = []);
```

#### Integrations

```php
Event::integrationCallSucceeded(string $integration, string $operation, $context = []);
Event::integrationCallFailed(string $integration, string $operation, \Throwable $e, $context = []);
```

#### View / UI

```php
Event::viewRendered(string $view, float $durationMs = 0, $context = []);
Event::componentRendered(string $component, $context = []);
```

#### Task / scheduler

```php
Event::taskStarted(string $task, $context = []);
Event::taskCompleted(string $task, float $durationMs = 0, $context = []);
Event::taskFailed(string $task, \Throwable $e, $context = []);
```

---

## The `Factory` System Logger

`Factory` is the framework's **system-event logger only**. It writes structured entries to `LoggerFactory::system(...)` log files and returns a module-scoped event ID. It does **not** dispatch listeners.

### Module Shortcuts

Every module has a base-ID constant and a shortcut method:

| Method | Module log file | Base ID |
|---|---|---|
| `Factory::core()` | `core` | 100 |
| `Factory::http()` | `http` | 200 |
| `Factory::ldap()` | `ldap` | 300 |
| `Factory::network()` | `network` | 400 |
| `Factory::notifications()` | `notifications` | 500 |
| `Factory::task()` | `task` | 600 |
| `Factory::uuid()` | `uuid` | 700 |
| `Factory::container()` | `container` | 800 |
| `Factory::webhook()` | `webhooks` | 900 |
| `Factory::database()` | `database` | 1000 |
| `Factory::auth()` | `authentication` | 1100 |
| `Factory::exception()` | `exceptions` | 1200 |
| `Factory::artisan()` | `artisan` | 1300 |
| `Factory::filesystem()` | `filesystem` | 1400 |
| `Factory::routing()` | `routing` | 1500 |
| `Factory::security()` | `security` | 1600 |
| `Factory::transport()` | `transport` | 1700 |
| `Factory::view()` | `view` | 1800 |
| `Factory::queue()` | `queue` | 1900 |
| `Factory::debugger()` | `debugger` | 2000 |
| `Factory::cache()` | `cache` | 2100 |
| `Factory::integration()` | `integrations` | 2200 |
| `Factory::ui()` | `ui` | 2300 |

Each shortcut accepts an optional PSR-3 level:

```php
Factory::database('connection.opened', ['dsn' => $dsn]);
Factory::security('firewall.blocked', ['ip' => $ip], 'warning');
Factory::exception('handler.caught', ['class' => $e::class], 'error');
Factory::cache('key.evicted', ['key' => $key], 'debug');
```

Default levels per module (sensible defaults):

| Module | Default level |
|---|---|
| `security`, `exception` | `warning` / `error` |
| `cache`, `debugger` | `debug` |
| everything else | `info` |

### Raw Logging API

When you need a module that isn't on the shortcut list, or want to log with full control:

```php
$id = Factory::log(
    module: 'custom',
    event:  'something.happened',
    moduleId: 9999,
    context: ['foo' => 'bar'],
    level:  'notice',
);
```

ID generation:

```php
Factory::generateEventId();                     // → 48291
Factory::generateEventId(Factory::DATABASE_ID); // → 1000 + random
```

---

## Registering Listeners

### Basic

```php
Event::on('user.login', fn($payload) => /* ... */);
```

### With priority

Higher runs first. Default is `0`.

```php
Event::on('user.login', $auditListener, priority: 100);   // runs first
Event::on('user.login', $notifyListener, priority: 10);   // runs second
Event::on('user.login', $metricsListener);                // runs last
```

### Via dependency injection

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

final class AuthService
{
    public function __construct(private EventListener $events) {}

    public function login(User $user): void
    {
        $this->events->on('user.login', fn($p) => /* ... */);
    }
}
```

---

## Dispatching Events

### `emit()` — fire-and-forget, auto-generates an ID

```php
Event::emit('user.login', ['user' => $user]);
```

### `trigger()` — fine-grained control

```php
// Collect all non-null responses
$responses = Event::trigger('user.login', ['user' => $user]);

// Halt on the first non-null response
$winner = Event::trigger('user.login', $payload, halt: true);

// Supply your own event ID
Event::trigger('user.login', $payload, halt: false, eventId: 12345);
```

### `until()` — shorthand for `trigger(..., halt: true)`

```php
$result = Event::until('user.login', ['user' => $user]);
```

### `dispatch()` — PSR-14 style

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\DispatchedEvent;

$event = new DispatchedEvent('user.login', 9876, ['user' => $user]);
Event::dispatch($event);
```

Any object with a public `name` property is also accepted:

```php
final class UserLoggedIn
{
    public string $name = 'user.login';
    public function __construct(public User $user) {}
}

Event::dispatch(new UserLoggedIn($user));
```

---

## Wildcard Listeners

Subscribe once and receive all matching events:

```php
Event::on('user.*', function ($payload, string $eventName) {
    error_log("Fired: {$eventName}");
});

Event::userLogin($user);       // → fires
Event::userLogout($user);      // → fires
Event::userRegistered($user);  // → fires
```

Wildcards use shell-style matching (`fnmatch`), so `user.*.created` matches `user.order.created`, etc.

---

## Once Listeners

Auto-removed after the first dispatch:

```php
Event::once('app.booted', function () {
    error_log("App booted (runs once).");
});

Event::appBooted();
Event::appBooted(); // listener is gone
```

---

## Subscribers

Group many listeners into one class:

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\SubscriberInterface;

final class AuditSubscriber implements SubscriberInterface
{
    public function __construct(private AuditLogger $audit) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'user.login'            => ['onLogin', 100],
            'user.logout'           => ['onLogout'],
            'user.password.changed' => [
                ['onPasswordChanged', 50],
                ['notifySecurityTeam', 10],
            ],
            'user.deleted' => [
                'archiveUser'  => 100,
                'notifyAdmins' => 5,
            ],
        ];
    }

    public function onLogin($payload): void           { $this->audit->write('login', $payload); }
    public function onLogout($payload): void          { $this->audit->write('logout', $payload); }
    public function onPasswordChanged($payload): void { /* ... */ }
    public function notifySecurityTeam($payload): void { /* ... */ }
    public function archiveUser($payload): void       { /* ... */ }
    public function notifyAdmins($payload): void      { /* ... */ }
}

// Register
Event::subscribe(new AuditSubscriber($audit));
```

### Supported handler shapes

```php
'event' => 'methodName'                       // priority 0
'event' => ['methodName', 25]                 // explicit priority
'event' => [['methodA', 10], ['methodB', 0]]  // multiple methods
'event' => ['methodA' => 10, 'methodB' => 0]  // associative
```

---

## Deferred / Queued Listeners

Mark listeners that shouldn't run inline (emails, webhooks, audit writes) by implementing `QueueableListener`:

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\QueueableListener;

final class SendWelcomeEmail implements QueueableListener
{
    public function __invoke($payload, string $event): void
    {
        Queue::push(new WelcomeEmailJob($payload['user']));
    }
}
```

Install a queue dispatcher at bootstrap:

```php
Event::setQueueDispatcher(function (QueueableListener $listener, string $event, $payload) {
    Job::dispatch($listener, $event, $payload);
});
```

Now:

```php
Event::on('user.registered', new SendWelcomeEmail());
Event::userRegistered($user); // listener is deferred, not inline
```

---

## Removing Listeners

```php
$listener = fn($p) => /* ... */;
Event::on('user.login', $listener);

// Remove one
Event::off('user.login', $listener);

// Remove all for one event
Event::flush('user.login');

// Remove everything
Event::flushAll();
```

`off()` only removes listeners registered with the **same callable instance**. Keep a reference if you need to remove a closure:

```php
$handler = function ($p) { /* ... */ };
Event::on('user.login', $handler);
Event::off('user.login', $handler); // ✅ works

Event::on('user.login', fn() => null);
Event::off('user.login', fn() => null); // ⚠️ does NOT remove
```

---

## Introspection & Debugging

```php
Event::events();                 // ['user.login', 'user.logout', …]
Event::listeners('user.login');  // [callable, callable, …]
Event::has('user.login');        // true / false
Event::display();                // pretty-print everything
```

`display()` output:

```
Registered Events:
=================
Event: user.login (2 listener(s))
  - Priority: 100, Listener: App\Audit::onLogin
  - Priority: 10, Listener: Closure

Event: app.booted (1 listener(s))
  - Priority: 0, Listener: App\Boot::warmCaches (once)
```

---

## Recursion Protection

The dispatcher refuses to re-enter the same event on the same call stack:

```php
Event::on('user.login', function ($p) {
    Event::userLogin($p['user']); // ✗ throws MachinjiriException
});
```

And enforces a maximum depth of 32 across different events. Both guards prevent stack overflows from accidental cycles.

---

## Error Handling

Listeners run **fail-isolated** by default. If one throws, the dispatcher:

1. Logs the failure via `Logger::error()`.
2. Continues to the next listener.
3. Returns the responses of listeners that succeeded.

Fail-fast behaviour with `halt: true`:

```php
try {
    Event::trigger('user.login', $payload, halt: true);
} catch (\Throwable $e) {
    // propagate as usual
}
```

With `halt: false` (default), the exception is swallowed and logged.

---

## Where Logs Go

`Factory` writes through `LoggerFactory::system(...)` using `is_event = true`. Files land at:

```
{logs_root}/{module}/events/{YYYY-MM-DD}/{module}.{module}.log
```

Where `{logs_root}` is, in order of priority:

1. `Container::getInstance()->storage . '/logs/'` — if a container instance is present.
2. `storage_path('logs/')` — if the helper function exists.
3. `Container::getSystemTempDir() . 'logs/'` — fallback.

Example layout after a busy day:

```
storage/logs/
├── database/events/2026-09-27/database.database.log
├── queue/events/2026-09-27/queue.queue.log
├── security/events/2026-09-27/security.security.log
└── core/events/2026-09-27/core.core.log
```

Every entry is JSON:

```json
{"timestamp":"2026-09-27T14:03:19.442000+02:00","level":"INFO","message":"migration.completed","context":{"event_id":1002841,"module":"database","module_id":1000,"migration":"2024_01_add_users"}}
```

---

## API Reference

### `Event`

```php
final class Event
{
    public static function boot(?Logger $logger = null): EventListener;
    public static function setListener(EventListener $listener): void;
    public static function listener(): EventListener;
    public static function reset(): void;

    // Core dispatcher API
    public static function on(string $event, callable $listener, int $priority = 0): void;
    public static function once(string $event, callable $listener, int $priority = 0): void;
    public static function subscribe(object $subscriber): void;
    public static function trigger(string $event, $payload = null, bool $halt = false, ?int $eventId = null);
    public static function emit(string $event, $payload = null);
    public static function dispatch(object $event);
    public static function until(string $event, $payload = null);
    public static function off(string $event, callable $listener): bool;
    public static function flush(string $event): void;
    public static function flushAll(): void;
    public static function has(string $event): bool;
    public static function events(): array;
    public static function listeners(string $event): array;
    public static function display(): void;
    public static function setQueueDispatcher(callable $dispatcher): void;

    // User events (dispatch only) — see table in README
    // System events (dispatch + log) — see table in README
}
```

### `Factory`

```php
class Factory
{
    public const CORE_ID              = 100;
    public const HTTP_ID              = 200;
    // … (23 module constants)

    public static function generateEventId(?int $moduleId = null): int;

    public static function log(
        string $module,
        string $event,
        int $moduleId,
        array $context = [],
        string $level = 'info'
    ): int;

    // Module shortcuts (23 of them)
    public static function core(string $event, array $context = [], string $level = 'info'): int;
    public static function database(string $event, array $context = [], string $level = 'info'): int;
    // … etc.
}
```

### `EventListener`

```php
class EventListener
{
    public function __construct(Logger $logger);
    public static function getInstance(Logger $logger): self;
    public static function resetInstance(): void;

    public function on(string $event, callable $listener, int $priority = 0): void;
    public function once(string $event, callable $listener, int $priority = 0): void;
    public function subscribe(object $subscriber): void;

    public function trigger(string $event, $payload = null, bool $halt = false, ?int $eventId = null);
    public function dispatch(object $event);
    public function until(string $event, $payload = null);

    public function removeListener(string $event, callable $listener): bool;
    public function removeAllListeners(string $event): void;
    public function flushAll(): void;

    public function getEvents(): array;
    public function getListeners(string $event): array;
    public function hasListeners(string $event): bool;
    public function displayEvents(): void;

    public function setQueueDispatcher(callable $dispatcher): void;
    public static function describeListener(callable $listener): string;
}
```

### `DispatchedEvent`

```php
final class DispatchedEvent
{
    public function __construct(string $name, ?int $id = null, $payload = null);
    public function name(): string;
    public function id(): ?int;
    public function payload();
    public function withId(int $id): self;
}
```

### `SubscriberInterface`

```php
interface SubscriberInterface
{
    public static function getSubscribedEvents(): array;
}
```

### `QueueableListener`

```php
interface QueueableListener {}
```

---

## Testing

```php
use Mlangeni\Machinjiri\Core\Artisans\Events\Event;

final class EventTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        Event::reset();
    }

    public function test_listener_receives_payload(): void
    {
        $received = null;
        Event::on('user.login', function ($p) use (&$received) { $received = $p; });

        Event::userLogin((object) ['id' => 1]);

        $this->assertNotNull($received);
        $this->assertSame(1, $received['user']->id);
    }

    public function test_priority_order(): void
    {
        $order = [];
        Event::on('t', function () use (&$order) { $order[] = 'low'; }, priority: 0);
        Event::on('t', function () use (&$order) { $order[] = 'high'; }, priority: 100);

        Event::emit('t');

        $this->assertSame(['high', 'low'], $order);
    }

    public function test_halt_returns_first_non_null(): void
    {
        Event::on('t', fn() => null);
        Event::on('t', fn() => 'winner', priority: 50);

        $this->assertSame('winner', Event::until('t'));
    }

    public function test_recursion_guard(): void
    {
        Event::on('a', fn() => Event::emit('a'));
        $this->expectException(\Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException::class);
        Event::emit('a');
    }

    public function test_factory_generates_namespaced_id(): void
    {
        $id = \Mlangeni\Machinjiri\Core\Artisans\Events\Factory::database('test');
        $this->assertGreaterThanOrEqual(
            \Mlangeni\Machinjiri\Core\Artisans\Events\Factory::DATABASE_ID,
            $id
        );
    }
}
```

### Tips

- Always `Event::reset()` in `setUp()` to isolate the dispatcher.
- Use `Event::setListener(...)` to inject a mock dispatcher.
- Silence output with a throwaway logger:
  ```php
  Event::boot(new Logger(sys_get_temp_dir() . '/test.log'));
  ```
- To assert on what `Factory` wrote, point `LoggerFactory` at a temp directory via `Container` or `storage_path()`.

---

## FAQ

**Q: What's the difference between `Event::userLogin(...)` and `Factory::auth('user.login', …)`?**
`Event::userLogin()` dispatches listeners. `Factory::auth('user.login', …)` only writes a log line. They are independent.

**Q: Do user events appear in system log files?**
No. Only `Event`'s system helpers write to log files, and they route through `Factory`. User events are dispatch-only by design (privacy, volume, relevance).

**Q: How do I log a user event?**
Register a listener that writes to your audit log:
```php
Event::on('user.login', fn($p) => AuditLog::write('login', $p));
```

**Q: Can I dispatch a system event without logging?**
Yes — call `Event::emit('app.booted', $payload)` directly instead of `Event::appBooted()`. Only the `Event::<systemHelper>()` wrappers log via `Factory`.

**Q: How do I change a module's log level?**
Pass a level explicitly: `Factory::security('foo', [], 'critical')`. Or configure `LOG_LEVEL` in your environment — the `Logger` respects it.

**Q: Are events synchronous?**
Yes. Use `QueueableListener` for async work.

**Q: What happens if two listeners share the same priority?**
Order is unspecified but stable within a run. Don't rely on it.

**Q: Can I dispatch an event from inside a listener?**
Yes, for a *different* event. Re-dispatching the *same* event on the same stack throws.

**Q: How do I remove a closure I forgot to keep a reference to?**
You can't. Use a named function, an invokable object, or `flush()` the event instead.

**Q: Is this PSR-14 compatible?**
Close enough — `dispatch(object $event)` follows the shape. Full PSR-14 requires `StoppableEventInterface`, which you can implement on `DispatchedEvent`.

**Q: Where do I put custom application events?**
Register them with `Event::on('app.order.placed', …)` and dispatch with `Event::emit('app.order.placed', $payload)`. Use your own naming convention (`app.*` or `<domain>.*`).

---

## License

Same license as the Machinjiri framework core.