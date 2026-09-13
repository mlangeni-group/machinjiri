<?php

/**
 * Notification Service Provider
 *
 * This service provider is responsible for registering and bootstrapping
 * core application services. It binds interfaces to concrete implementations,
 * registers singleton instances, sets up configuration, and provides aliases
 * for easier access via the service container.
 *
 * @package Mlangeni\Machinjiri\Core\Providers\CoreProviders
 */

namespace Mlangeni\Machinjiri\Core\Providers\CoreProviders;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationManager;
use Mlangeni\Machinjiri\Core\Components\Notification\ChannelManager;
use Mlangeni\Machinjiri\Core\Components\Notification\Channels\{MailChannel, SmsChannel, DatabaseChannel, WebhookChannel};
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;
use Mlangeni\Machinjiri\Core\Transport\SMS\SMSManager;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register core application services.
     *
     * This method is called during the service container's registration phase.
     * All bindings and singletons are defined here. Services like HTTP handling,
     * authentication, logging, filesystem, caching, mailing, and security are
     * registered with the container.
     *
     * @return void
     */
    public function register(): void
    {
        // -------------------- Notification Manager --------------------
        // Register the Notification Manager as a singleton
        $this->singleton(NotificationManager::class, function($app) {
            return new NotificationManager(
                $app,
                $app->resolve(ChannelManager::class),
                $app->resolve(Logger::class),
                $app->resolve(EventListener::class),
                $app->resolve('queue.dispatcher')
            );
        });

        // -------------------- Channel Manager --------------------
        // Register the Notification Channel Manager as a singleton
        $this->singleton(ChannelManager::class, function($app) {
            return new ChannelManager($app);
        });

        $this->singleton(MailChannel::class, function($app) {
            return new MailChannel($app->resolve(MailManager::class));
        });

        $this->singleton(SmsChannel::class, function($app) {
            return new SmsChannel($app->resolve(SMSManager::class));
        });

        // -------------------- Aliases for Convenience --------------------
        // Provide shorter names for common services to simplify dependency resolution.
        $this->aliasMany([
            'notification.manager'          => NotificationManager::class,
            'notification.channel.manager'  => ChannelManager::class,
        ]);
    }

    /**
     * Bootstrap application services.
     *
     * This method is called after all service providers have been registered.
     * It loads the various configuration files from the config directory and merges
     * them into the application's configuration repository.
     *
     * @return void
     */
    public function boot(): void
    {
        // Get the configuration directory path from the application.
        $configDir = $this->app->coreConfig;

        // If the config directory exists, load each configuration file.
        if (is_dir($configDir)) {
            $this->mergeConfigFrom($configDir . 'notification.php', 'notification');
        }

        $this->app->resolve(ChannelManager::class)->registerChannels();
    }

    /**
     * Get the services provided by this provider.
     *
     * This method returns an array of service names (abstracts or aliases)
     * that this provider registers. It is used by the container to optimize
     * deferred service loading.
     *
     * @return array
     */
    public function provides(): array
    {
        // Combine all bindings, singletons, and aliases into a single list.
        return array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->aliases)
        );
    }
}