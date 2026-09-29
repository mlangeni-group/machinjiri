<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

/**
 * Event – the application's combined USER + SYSTEM event façade.
 *
 * - User events    : userLogin, userLogout, userRegistered, …
 * - System events  : appBooted, requestHandled, jobFailed, cacheHit, …
 *
 * Both kinds of helpers dispatch through the EventListener.
 * System helpers ALSO log via Factory (the system-event logger).
 */
final class Event
{
    /** @var EventListener|null */
    private static ?EventListener $listener = null;

    private function __construct() {}

    /* ---------------------------------------------------------------------
     |  Boot
     * ------------------------------------------------------------------- */

    public static function boot(?Logger $logger = null): EventListener
    {
        if (self::$listener === null) {
            $logger = $logger ?? LoggerFactory::system('events', 'core', true);
            self::$listener = EventListener::getInstance($logger);
        }
        return self::$listener;
    }

    public static function setListener(EventListener $listener): void
    {
        self::$listener = $listener;
    }

    public static function listener(): EventListener
    {
        return self::$listener ?? self::boot();
    }

    public static function reset(): void
    {
        self::$listener = null;
        EventListener::resetInstance();
    }

    /* ---------------------------------------------------------------------
     |  Core dispatcher API
     * ------------------------------------------------------------------- */

    public static function on(string $event, callable $listener, int $priority = 0): void
    { self::listener()->on($event, $listener, $priority); }

    public static function once(string $event, callable $listener, int $priority = 0): void
    { self::listener()->once($event, $listener, $priority); }

    public static function subscribe(object $subscriber): void
    { self::listener()->subscribe($subscriber); }

    public static function trigger(string $event, $payload = null, bool $halt = false, ?int $eventId = null)
    { return self::listener()->trigger($event, $payload, $halt, $eventId); }

    public static function emit(string $event, $payload = null)
    { return self::listener()->trigger($event, $payload, false, Factory::generateEventId()); }

    public static function dispatch(object $event)
    { return self::listener()->dispatch($event); }

    public static function until(string $event, $payload = null)
    { return self::listener()->until($event, $payload); }

    public static function off(string $event, callable $listener): bool
    { return self::listener()->removeListener($event, $listener); }

    public static function flush(string $event): void
    { self::listener()->removeAllListeners($event); }

    public static function flushAll(): void
    { self::listener()->flushAll(); }

    public static function has(string $event): bool
    { return self::listener()->hasListeners($event); }

    public static function events(): array
    { return self::listener()->getEvents(); }

    public static function listeners(string $event): array
    { return self::listener()->getListeners($event); }

    public static function display(): void
    { self::listener()->displayEvents(); }

    public static function setQueueDispatcher(callable $dispatcher): void
    { self::listener()->setQueueDispatcher($dispatcher); }

    /* =====================================================================
     |  USER EVENTS
     |  Dispatch only – user events are not written to system log files.
     | =================================================================== */

    public static function userLogin($user, array $context = [])
    { return self::emit('user.login', ['user' => $user, 'context' => $context]); }

    public static function userLogout($user, array $context = [])
    { return self::emit('user.logout', ['user' => $user, 'context' => $context]); }

    public static function userRegistered($user, array $context = [])
    { return self::emit('user.registered', ['user' => $user, 'context' => $context]); }

    public static function userUpdated($user, array $context = [])
    { return self::emit('user.updated', ['user' => $user, 'context' => $context]); }

    public static function userDeleted($user, array $context = [])
    { return self::emit('user.deleted', ['user' => $user, 'context' => $context]); }

    public static function userPasswordChanged($user, array $context = [])
    { return self::emit('user.password.changed', ['user' => $user, 'context' => $context]); }

    public static function userPasswordResetRequested($user, array $context = [])
    { return self::emit('user.password.reset.requested', ['user' => $user, 'context' => $context]); }

    public static function userPasswordReset($user, array $context = [])
    { return self::emit('user.password.reset', ['user' => $user, 'context' => $context]); }

    public static function userEmailVerified($user, array $context = [])
    { return self::emit('user.email.verified', ['user' => $user, 'context' => $context]); }

    public static function userProfileUpdated($user, array $context = [])
    { return self::emit('user.profile.updated', ['user' => $user, 'context' => $context]); }

    public static function userRoleAssigned($user, $role, array $context = [])
    { return self::emit('user.role.assigned', ['user' => $user, 'role' => $role, 'context' => $context]); }

    public static function userRoleRevoked($user, $role, array $context = [])
    { return self::emit('user.role.revoked', ['user' => $user, 'role' => $role, 'context' => $context]); }

    public static function userAuthenticated($user, array $context = [])
    { return self::emit('auth.authenticated', ['user' => $user, 'context' => $context]); }

    public static function userAuthenticationFailed($identifier, array $context = [])
    { return self::emit('auth.failed', ['identifier' => $identifier, 'context' => $context]); }

    public static function userLockedOut($user, array $context = [])
    { return self::emit('auth.locked', ['user' => $user, 'context' => $context]); }

    public static function userTwoFactorEnabled($user, array $context = [])
    { return self::emit('auth.2fa.enabled', ['user' => $user, 'context' => $context]); }

    public static function userTwoFactorDisabled($user, array $context = [])
    { return self::emit('auth.2fa.disabled', ['user' => $user, 'context' => $context]); }

    /* =====================================================================
     |  SYSTEM EVENTS
     |  Dispatch AND log to the module's system log file via Factory.
     | =================================================================== */

    /* --- Core / application --------------------------------------------- */

    public static function appBooted(array $context = [])
    {
        Factory::core('app.booted', $context);
        return self::emit('app.booted', $context);
    }

    public static function appShutdown(array $context = [])
    {
        Factory::core('app.shutdown', $context);
        return self::emit('app.shutdown', $context);
    }

    public static function exceptionThrown(\Throwable $e, array $context = [])
    {
        $ctx = array_merge($context, [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
        Factory::exception('app.exception', $ctx, 'error');
        return self::emit('app.exception', $ctx);
    }

    /* --- HTTP / routing -------------------------------------------------- */

    public static function requestHandled($request, $response, array $context = [])
    {
        $ctx = ['request' => $request, 'response' => $response, 'context' => $context];
        Factory::http('request.handled', $context);
        return self::emit('http.request.handled', $ctx);
    }

    public static function requestRejected($request, array $context = [])
    {
        $ctx = ['request' => $request, 'context' => $context];
        Factory::http('request.rejected', $context, 'warning');
        return self::emit('http.request.rejected', $ctx);
    }

    public static function routeMatched(string $route, array $context = [])
    {
        Factory::routing('route.matched', array_merge($context, ['route' => $route]));
        return self::emit('routing.route.matched', ['route' => $route, 'context' => $context]);
    }

    /* --- Queue / jobs ---------------------------------------------------- */

    public static function jobDispatched($job, array $context = [])
    {
        Factory::queue('job.dispatched', $context);
        return self::emit('queue.job.dispatched', ['job' => $job, 'context' => $context]);
    }

    public static function jobFailed($job, \Throwable $e, array $context = [])
    {
        $ctx = ['job' => $job, 'error' => $e->getMessage(), 'context' => $context];
        Factory::queue('job.failed', $ctx, 'error');
        return self::emit('queue.job.failed', $ctx);
    }

    public static function jobCompleted($job, array $context = [])
    {
        Factory::queue('job.completed', $context);
        return self::emit('queue.job.completed', ['job' => $job, 'context' => $context]);
    }

    /* --- Cache ----------------------------------------------------------- */

    public static function cacheHit(string $key, array $context = [])
    {
        Factory::cache('cache.hit', array_merge($context, ['key' => $key]));
        return self::emit('cache.hit', ['key' => $key, 'context' => $context]);
    }

    public static function cacheMissed(string $key, array $context = [])
    {
        Factory::cache('cache.missed', array_merge($context, ['key' => $key]));
        return self::emit('cache.missed', ['key' => $key, 'context' => $context]);
    }

    public static function cacheFlushed(array $context = [])
    {
        Factory::cache('cache.flushed', $context);
        return self::emit('cache.flushed', $context);
    }

    /* --- Database -------------------------------------------------------- */

    public static function databaseQueryExecuted(string $sql, array $bindings = [], float $durationMs = 0, array $context = [])
    {
        $ctx = compact('sql', 'bindings', 'durationMs') + ['context' => $context];
        Factory::database('query.executed', $ctx, $durationMs > 1000 ? 'warning' : 'debug');
        return self::emit('database.query.executed', $ctx);
    }

    public static function migrationCompleted(string $migration, array $context = [])
    {
        Factory::database('migration.completed', array_merge($context, ['migration' => $migration]));
        return self::emit('database.migration.completed', ['migration' => $migration, 'context' => $context]);
    }

    /* --- Security -------------------------------------------------------- */

    public static function securityBreachDetected(string $reason, array $context = [])
    {
        $ctx = ['reason' => $reason, 'context' => $context];
        Factory::security('breach.detected', $ctx, 'critical');
        return self::emit('security.breach.detected', $ctx);
    }

    public static function accessDenied($user, string $resource, array $context = [])
    {
        $ctx = ['user' => $user, 'resource' => $resource, 'context' => $context];
        Factory::security('access.denied', $ctx, 'warning');
        return self::emit('security.access.denied', $ctx);
    }

    /* --- Filesystem ------------------------------------------------------ */

    public static function fileUploaded(string $path, array $context = [])
    {
        Factory::filesystem('file.uploaded', array_merge($context, ['path' => $path]));
        return self::emit('filesystem.file.uploaded', ['path' => $path, 'context' => $context]);
    }

    public static function fileDeleted(string $path, array $context = [])
    {
        Factory::filesystem('file.deleted', array_merge($context, ['path' => $path]));
        return self::emit('filesystem.file.deleted', ['path' => $path, 'context' => $context]);
    }

    /* --- Notifications / webhooks ---------------------------------------- */

    public static function notificationSent(string $channel, $recipient, array $context = [])
    {
        Factory::notifications('notification.sent', array_merge($context, [
            'channel' => $channel,
        ]));
        return self::emit('notification.sent', [
            'channel'   => $channel,
            'recipient' => $recipient,
            'context'   => $context,
        ]);
    }

    public static function webhookReceived(string $source, array $payload = [], array $context = [])
    {
        Factory::webhook('webhook.received', array_merge($context, ['source' => $source]));
        return self::emit('webhook.received', [
            'source'  => $source,
            'payload' => $payload,
            'context' => $context,
        ]);
    }

    public static function webhookDelivered(string $target, array $context = [])
    {
        Factory::webhook('webhook.delivered', array_merge($context, ['target' => $target]));
        return self::emit('webhook.delivered', ['target' => $target, 'context' => $context]);
    }

    /* --- Integrations ---------------------------------------------------- */

    public static function integrationCallSucceeded(string $integration, string $operation, array $context = [])
    {
        Factory::integration('call.succeeded', array_merge($context, compact('integration', 'operation')));
        return self::emit('integration.call.succeeded', compact('integration', 'operation') + ['context' => $context]);
    }

    public static function integrationCallFailed(string $integration, string $operation, \Throwable $e, array $context = [])
    {
        $ctx = compact('integration', 'operation') + ['error' => $e->getMessage(), 'context' => $context];
        Factory::integration('call.failed', $ctx, 'error');
        return self::emit('integration.call.failed', $ctx);
    }

    /* --- View / UI ------------------------------------------------------- */

    public static function viewRendered(string $view, float $durationMs = 0, array $context = [])
    {
        Factory::view('view.rendered', array_merge($context, compact('view', 'durationMs')));
        return self::emit('view.rendered', compact('view', 'durationMs') + ['context' => $context]);
    }

    public static function componentRendered(string $component, array $context = [])
    {
        Factory::ui('component.rendered', array_merge($context, ['component' => $component]));
        return self::emit('ui.component.rendered', ['component' => $component, 'context' => $context]);
    }

    /* --- Task / scheduler ------------------------------------------------ */

    public static function taskStarted(string $task, array $context = [])
    {
        Factory::task('task.started', array_merge($context, ['task' => $task]));
        return self::emit('task.started', ['task' => $task, 'context' => $context]);
    }

    public static function taskCompleted(string $task, float $durationMs = 0, array $context = [])
    {
        Factory::task('task.completed', array_merge($context, compact('task', 'durationMs')));
        return self::emit('task.completed', compact('task', 'durationMs') + ['context' => $context]);
    }

    public static function taskFailed(string $task, \Throwable $e, array $context = [])
    {
        $ctx = ['task' => $task, 'error' => $e->getMessage(), 'context' => $context];
        Factory::task('task.failed', $ctx, 'error');
        return self::emit('task.failed', $ctx);
    }
}