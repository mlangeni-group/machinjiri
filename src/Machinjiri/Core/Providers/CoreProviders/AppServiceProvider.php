<?php

/**
 * Application Service Provider
 *
 * This service provider is responsible for registering and bootstrapping
 * core application services. It binds interfaces to concrete implementations,
 * registers singleton instances, sets up configuration, and provides aliases
 * for easier access via the service container.
 *
 * @package Mlangeni\Machinjiri\Core\Providers\CoreProviders
 */

namespace Mlangeni\Machinjiri\Core\Providers\CoreProviders;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Artisans\Events\{EventListener, Event};
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Authentication\{Session, Cookie, AuthManager};
use Mlangeni\Machinjiri\Core\Authentication\ThirdParty\ThirdPartyAuth;
use Mlangeni\Machinjiri\Core\Components\LDAP\{Manager as LDAPManager, Connection as LDAPConnection, EntryManager};
use Mlangeni\Machinjiri\Core\Components\Network\Tools\{Manager, Scanner, Monitor, NetworkConfig};
use Mlangeni\Machinjiri\Core\Components\Webhooks\{WebhookSubscriptionManager, WebhookManager};
use Mlangeni\Machinjiri\Core\Debug\Debugger;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\{LocalAdapter, FtpAdapter};
use Mlangeni\Machinjiri\Core\FileSystem\FileSystemManager;
use Mlangeni\Machinjiri\Core\Http\{HttpRequest, HttpResponse, HttpClient};
use Mlangeni\Machinjiri\Core\Performance\Insight\Insight;
use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Routing\RoutingConfig;
use Mlangeni\Machinjiri\Core\Security\Encryption\Bangwe;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;
use Mlangeni\Machinjiri\Core\Security\Tokens\{CSRFToken, CookieOptions};
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;
use Mlangeni\Machinjiri\Core\Transport\SMS\SMSManager;

class AppServiceProvider extends ServiceProvider
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
        // Register the HTTP request/response handling services.
        $this->registerHttpServices();

        // Register authentication and session-related services.
        $this->registerAuthenticationServices();

        // Register development and debugging helpers.
        $this->registerDebuggingServices();

        // Register filesystem and storage abstractions.
        $this->registerFileSystemServices();

        // Register queue workers and job processing services.
        $this->registerQueueServices();

        // Register logging and monitoring services.
        $this->registerLoggerServices();

        // Register in-memory and persistent caching services.
        $this->registerCachingServices();

        // Register mail and transport services for outgoing messages.
        $this->registerTransportServices();

        // Register security primitives such as hashing, encryption, and tokens.
        $this->registerSecurityServices();

        // Register third-party authentication providers and integrations.
        $this->registerThirdPartyAuthServices();

        // Register LDAP directory connection and entry management services.
        $this->registerLDAPServices();

        // Register networking tools and infrastructure services.
        $this->registerNetworkServices();

        // Register webhook subscription and delivery managers.
        $this->registerWebhookServices();

        // Register performance-insight and telemetry services.
        $this->registerPerformanceServices();

        // Register aliases for easier access to services.
        $this->registerAliases();
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
        try {
            if (is_dir($configDir)) {
                $this->mergeConfigFrom($configDir . 'app.php', 'app');
                $this->mergeConfigFrom($configDir . 'filesystem.php', 'filesystem');
                $this->mergeConfigFrom($configDir . 'database.php', 'database');
                $this->mergeConfigFrom($configDir . 'cache.php', 'cache');
                $this->mergeConfigFrom($configDir . 'mail.php', 'mail');
                $this->mergeConfigFrom($configDir . 'queue.php', 'queue');
                $this->mergeConfigFrom($configDir . 'auth.php', 'auth');
                $this->mergeConfigFrom($configDir . 'oauth.php', 'oauth');
                $this->mergeConfigFrom($configDir . 'ldap.php', 'ldap');
                $this->mergeConfigFrom($configDir . 'logger.php', 'logger');
                $this->mergeConfigFrom($configDir . 'redis.php', 'redis');
                $this->mergeConfigFrom($configDir . 'sms.php', 'sms');
                $this->mergeConfigFrom($configDir . 'webhooks.php', 'webhooks');
                $this->mergeConfigFrom($configDir . 'hashing.php', 'hashing');
                $this->mergeConfigFrom($configDir . 'encryption.php', 'encryption');
            }
        } catch (\Throwable $e) {
            throw new MachinjiriException($e->getMessage(), $e->getCode(), $e);
        }

        $wsm = $this->app->resolve(WebhookSubscriptionManager::class);
        $wsm->registerWebhookHandlers();

        Event::boot($this->app->resolve('app.main.logger'));

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

    private function registerFileSystemServices()
    {
        // LocalAdapter uses the root path from the filesystem configuration.
        $this->app->singleton(LocalAdapter::class, function ($app) {
            return new LocalAdapter($app->configurations['filesystem']['disks']['local']['root']);
        });

        // FtpAdapter uses the FTP configuration from the filesystem settings.
        $this->app->singleton(FtpAdapter::class, function ($app) {
            return new FtpAdapter($app->configurations['filesystem']['disks']['ftp']);
        });

        // FileSystemManager manages multiple disks; passed the full filesystem config.
        $this->app->singleton(FileSystemManager::class, function ($app) {
            return new FileSystemManager($app->configurations['filesystem']);
        });
    }

    private function registerQueueServices()
    {
        // QueueManager is responsible for managing job queues; uses the queue config.
        $this->app->singleton('queue.manager', function ($app) {
            return new \Mlangeni\Machinjiri\Core\Artisans\Queue\QueueManager($app->configurations['queue']);
        });

        // QueueDispatcher handles dispatching jobs to the appropriate queue.
        $this->app->singleton('queue.dispatcher', function ($app) {
            return new \Mlangeni\Machinjiri\Core\Artisans\Queue\QueueDispatcher($app->resolve('queue.manager'));
        });
    }

    private function registerLoggerServices()
    {
        // Logger is registered as a singleton with the application name and debug level.
        $this->app->singleton(Logger::class, function ($app) {
            return new Logger(env('APP_NAME') ?? 'machinjiri', Logger::DEBUG, false, '', 'app');
        });

        $this->singleton('app.main.logger', function ($app) {
            return new Logger(env('APP_NAME') ?? 'machinjiri', Logger::DEBUG, true, '', 'app');
        });

        // EventListener is registered to listen for events and log them.
        $this->app->singleton(EventListener::class, function ($app) {
            return new EventListener($app->resolve('app.main.logger'));
        });
    }

    private function registerSecurityServices()
    {
        // Hasher provides hashing utilities (bcrypt, etc.) as a singleton.
        $this->singleton(Hasher::class, function ($app) {
            return new Hasher($app, $app->resolve(Logger::class));
        });

        // Bangwe is the encryption service, requiring the application instance.
        $this->singleton(Bangwe::class, function ($app) {
            return new Bangwe($app);
        });

        // -------------------- CSRF Protection --------------------
        // CSRFToken singleton requires Session, Cookie, and a token name from env.
        $this->app->singleton(CSRFToken::class, function ($app) {
            return new CSRFToken(
                $app->resolve(Session::class),
                $app->resolve(Cookie::class),
                env("CSRF_TOKEN_NAME", "csrf_token"),
                env("CSRF_COOKIE_NAME", "csrf_token"),
                env("CSRF_TTL", 3600),
                new CookieOptions(),
                env("CSRF_MAX_FAILURES", 5)
            );
        });
    }

    private function registerThirdPartyAuthServices()
    {
        // ThirdPartyAuth uses the OAuth configuration from the application config.
        $this->singleton(ThirdPartyAuth::class, function ($app) {
            return new ThirdPartyAuth($app->configurations['oauth']);
        });
    }

    private function registerLDAPServices()
    {
        // Custom LDAP manager registered with a string alias, using LDAP config.
        $this->singleton(LDAPManager::class, function ($app) {
            return new LDAPManager($app->configurations['ldap']);
        });

        $this->singleton(LDAPConnection::class, function ($app) {
            return $app->resolve(LDAPManager::class)->connection();
        });

        $this->singleton(EntryManager::class, function ($app) {
            return new EntryManager($app->resolve(LDAPConnection::class));
        });
    }

    private function registerNetworkServices()
    {
        // Network Manager, Scanner, and Monitor are registered as singletons.
        $this->singleton("network.manager", function ($app) {
            return new Manager(
                $app->resolve("network.scanner"),
                $app->resolve("network.monitor"),
                $app->resolve("cache.manager"),
                $app->resolve("network.config"),
                $app->resolve('queue.dispatcher'),
                $app->resolve(EventListener::class)
            );
        });

        $this->singleton("network.scanner", function ($app) {
            return new Scanner(
                $app->resolve("cache.manager"),
                $app->resolve("network.config"),
                $app->resolve(HttpClient::class)
            );
        });

        $this->singleton("network.monitor", function ($app) {
            return new Monitor(
                $app->resolve("network.scanner"),
                $app->resolve("cache.manager"),
                $app->resolve("network.config")
            );
        });
        
        $this->singleton("network.config", function ($app) {
            $config = $this->app->coreConfig . 'network.php';
            return is_file($config) ? require $config : new NetworkConfig();
        });
    }

    private function registerWebhookServices()
    {
        // WebhookSubscriptionManager, and WebhookManager are registered as singletons.
        $this->singleton(WebhookSubscriptionManager::class, function ($app) {
            return new WebhookSubscriptionManager($app->configurations['webhooks'] ?? []);
        });

        $this->singleton(WebhookManager::class, function ($app) {
            return new WebhookManager($app, $app->resolve(WebhookSubscriptionManager::class), $app->resolve(CacheManager::class));
        });
    }

    private function registerPerformanceServices()
    {
        // Insight and Monitor are registered as singletons.
        $this->singleton(Insight::class, function ($app) {
            return new Insight();
        });
    }

    private function registerAliases()
    {
        // Provide shorter names for common services to simplify dependency resolution.
        $this->aliasMany([
            'request'                       => HttpRequest::class,
            'response'                      => HttpResponse::class,
            'auth.session'                  => Session::class,
            'auth.cookie'                   => Cookie::class,
            'auth.thirdparty'               => ThirdPartyAuth::class,
            'auth.manager'                  => AuthManager::class,
            'debugger'                      => Debugger::class,
            'events'                        => EventListener::class,
            'fs.adapter.local'              => LocalAdapter::class,
            'fs.adapter.ftp'                => FtpAdapter::class,
            'fs.manager'                    => FileSystemManager::class,
            'cache.manager'                 => CacheManager::class,
            'mail.manager'                  => MailManager::class,
            'sms.manager'                   => SMSManager::class,
            'logger'                        => Logger::class,
            'routing.config'                => RoutingConfig::class,
            'security.hasher'               => Hasher::class,
            'security.bangwe'               => Bangwe::class,
            'webhook.manager'               => WebhookManager::class,
            'webhook.subscription.manager'  => WebhookSubscriptionManager::class,
            'ldap.manager'                  => LDAPManager::class,
            'ldap.connection'               => LDAPConnection::class,
            'perf.insight.class'            => Insight::class,
        ]);
    }

    private function registerTransportServices()
    {
        $this->singleton(MailManager::class, function ($app) {
            $log = "mailer-transport";
            return new MailManager(
                $app,
                null,
                new Logger('mailer-transport', Logger::DEBUG, false, '', 'system'),
                new EventListener(
                    new Logger('mailer-transport', Logger::DEBUG, true, '', 'system')
                ),
                null,
                $app->resolve('queue.dispatcher')
            );
        });

        // -------------------- SMS Transport --------------------
        $this->app->singleton(SMSManager::class, function ($app) {
            return SMSManager::fromConfig($app->configurations['sms'] ?? [], $app);
        });
        
    }

    private function registerAuthenticationServices()
    {
        // Session and Cookie handlers are singletons for state management.
        $this->singleton(Session::class, function ($app) {
            return new Session($app);
        });
        
        $this->singleton(Cookie::class, function ($app) {
            return new Cookie($app);
        });

        // AuthManager handles authentication logic; requires configuration.
        $this->singleton(AuthManager::class, function ($app) {
            $config = $app->configurations['auth'] ?? false;
            // Ensure authentication configuration exists; otherwise throw an exception.
            if (!$config) throw new MachinjiriException("App Service Error: auth config not found");
            return new AuthManager($app, $config);
        });
    }

    private function registerDebuggingServices()
    {
        // Debugger is registered as a singleton for centralized debugging.
        $this->app->singleton(Debugger::class, function ($app) {
            return new Debugger($app);
        });
    }

    private function registerCachingServices()
    {
        // CacheManager handles caching strategies using the cache configuration.
        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager($app->configurations['cache']);
        });
    }

    private function registerHttpServices()
    {
        // Register the HTTP request as a singleton, created from global PHP superglobals.
        $this->singleton(HttpRequest::class, function($app) {
            return HttpRequest::createFromGlobals();
        });

        // Register the HTTP response as a singleton for output manipulation.
        $this->singleton(HttpResponse::class, function($app) {
            return new HttpResponse();
        });

        // Register the HTTP client for making external requests.
        $this->singleton(HttpClient::class, function($app) {
            return new HttpClient();
        });
    }

}
