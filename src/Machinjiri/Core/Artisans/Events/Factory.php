<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Components\UUID\OTP\OTPGenerator as EventIdGenerator;

/**
 * Factory – the framework's SYSTEM EVENT LOGGER.
 *
 * Every method here writes a structured entry to a module-namespaced log
 * file via LoggerFactory::system(). It does NOT dispatch listeners.
 *
 * User-facing events are handled by the Event façade.
 */
class Factory
{
    public const CORE_ID              = 100;
    public const HTTP_ID              = 200;
    public const LDAP_ID              = 300;
    public const NETWORK_ID           = 400;
    public const NOTIFICATIONS_ID     = 500;
    public const TASK_ID              = 600;
    public const UUID_ID              = 700;
    public const SERVICE_CONTAINER_ID = 800;
    public const WEBHOOKS_ID          = 900;
    public const DATABASE_ID          = 1000;
    public const AUTHENTICATION_ID    = 1100;
    public const EXCEPTIONS_ID        = 1200;
    public const ARTISAN_TERMINAL_ID  = 1300;
    public const FILE_SYSTEM_ID       = 1400;
    public const ROUTING_ID           = 1500;
    public const SECURITY_ID          = 1600;
    public const TRANSPORT_ID         = 1700;
    public const VIEW_ENGINE_ID       = 1800;
    public const QUEUE_ENGINE         = 1900;
    public const DEBUGGER_ID          = 2000;
    public const CACHING_ENGINE       = 2100;
    public const INTEGRATIONS_ID      = 2200;
    public const UI_ENGINE_ID         = 2300;

    /* ---------------------------------------------------------------------
     |  ID generation
     * ------------------------------------------------------------------- */

    /**
     * Generate a numeric system-event ID.
     * Pass a module ID to scope the ID to that module's range.
     */
    public static function generateEventId(?int $moduleId = null): int
    {
        $random = (int) EventIdGenerator::numeric(5);
        return $moduleId === null ? $random : ($moduleId + $random);
    }

    /* ---------------------------------------------------------------------
     |  Core logging
     * ------------------------------------------------------------------- */

    /**
     * Log a system event to the given module's event log.
     *
     * @param string $module   Module name (also used as log file name)
     * @param string $event    Event name
     * @param int    $moduleId The module's base ID constant
     * @param array  $context  Arbitrary extra context
     * @param string $level    PSR-3 level (info, warning, error, …)
     * @return int             The generated event ID
     */
    public static function log(
        string $module,
        string $event,
        int $moduleId,
        array $context = [],
        string $level = 'info'
    ): int {
        $eventId = self::generateEventId($moduleId);

        $logger = LoggerFactory::system($module, $module, true);

        $context = array_merge([
            'event_id'  => $eventId,
            'module'    => $module,
            'module_id' => $moduleId,
        ], $context);

        // Dispatch to the appropriate PSR-3 level
        switch (strtolower($level)) {
            case 'emergency': $logger->emergency($event, $context); break;
            case 'alert':     $logger->alert($event, $context);     break;
            case 'critical':  $logger->critical($event, $context);  break;
            case 'error':     $logger->error($event, $context);     break;
            case 'warning':   $logger->warning($event, $context);   break;
            case 'notice':    $logger->notice($event, $context);    break;
            case 'debug':     $logger->debug($event, $context);     break;
            case 'info':
            default:          $logger->info($event, $context);      break;
        }

        return $eventId;
    }

    /* ---------------------------------------------------------------------
     |  System module shortcuts
     * ------------------------------------------------------------------- */

    public static function core(string $event, array $context = [], string $level = 'info'): int
    { return self::log('core', $event, self::CORE_ID, $context, $level); }

    public static function http(string $event, array $context = [], string $level = 'info'): int
    { return self::log('http', $event, self::HTTP_ID, $context, $level); }

    public static function ldap(string $event, array $context = [], string $level = 'info'): int
    { return self::log('ldap', $event, self::LDAP_ID, $context, $level); }

    public static function network(string $event, array $context = [], string $level = 'info'): int
    { return self::log('network', $event, self::NETWORK_ID, $context, $level); }

    public static function notifications(string $event, array $context = [], string $level = 'info'): int
    { return self::log('notifications', $event, self::NOTIFICATIONS_ID, $context, $level); }

    public static function task(string $event, array $context = [], string $level = 'info'): int
    { return self::log('task', $event, self::TASK_ID, $context, $level); }

    public static function uuid(string $event, array $context = [], string $level = 'info'): int
    { return self::log('uuid', $event, self::UUID_ID, $context, $level); }

    public static function container(string $event, array $context = [], string $level = 'info'): int
    { return self::log('container', $event, self::SERVICE_CONTAINER_ID, $context, $level); }

    public static function webhook(string $event, array $context = [], string $level = 'info'): int
    { return self::log('webhooks', $event, self::WEBHOOKS_ID, $context, $level); }

    public static function database(string $event, array $context = [], string $level = 'info'): int
    { return self::log('database', $event, self::DATABASE_ID, $context, $level); }

    public static function auth(string $event, array $context = [], string $level = 'info'): int
    { return self::log('authentication', $event, self::AUTHENTICATION_ID, $context, $level); }

    public static function exception(string $event, array $context = [], string $level = 'error'): int
    { return self::log('exceptions', $event, self::EXCEPTIONS_ID, $context, $level); }

    public static function artisan(string $event, array $context = [], string $level = 'info'): int
    { return self::log('artisan', $event, self::ARTISAN_TERMINAL_ID, $context, $level); }

    public static function filesystem(string $event, array $context = [], string $level = 'info'): int
    { return self::log('filesystem', $event, self::FILE_SYSTEM_ID, $context, $level); }

    public static function routing(string $event, array $context = [], string $level = 'info'): int
    { return self::log('routing', $event, self::ROUTING_ID, $context, $level); }

    public static function security(string $event, array $context = [], string $level = 'warning'): int
    { return self::log('security', $event, self::SECURITY_ID, $context, $level); }

    public static function transport(string $event, array $context = [], string $level = 'info'): int
    { return self::log('transport', $event, self::TRANSPORT_ID, $context, $level); }

    public static function view(string $event, array $context = [], string $level = 'info'): int
    { return self::log('view', $event, self::VIEW_ENGINE_ID, $context, $level); }

    public static function queue(string $event, array $context = [], string $level = 'info'): int
    { return self::log('queue', $event, self::QUEUE_ENGINE, $context, $level); }

    public static function debugger(string $event, array $context = [], string $level = 'debug'): int
    { return self::log('debugger', $event, self::DEBUGGER_ID, $context, $level); }

    public static function cache(string $event, array $context = [], string $level = 'debug'): int
    { return self::log('cache', $event, self::CACHING_ENGINE, $context, $level); }

    public static function integration(string $event, array $context = [], string $level = 'info'): int
    { return self::log('integrations', $event, self::INTEGRATIONS_ID, $context, $level); }

    public static function ui(string $event, array $context = [], string $level = 'info'): int
    { return self::log('ui', $event, self::UI_ENGINE_ID, $context, $level); }
}