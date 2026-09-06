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

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Http\{HttpRequest, HttpResponse, HttpClient};
use Mlangeni\Machinjiri\Core\Authentication\{Session, Cookie, AuthManager};
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Debug\Debugger;
use Mlangeni\Machinjiri\Core\FileSystem\FileSystemManager;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\{LocalAdapter, FtpAdapter};
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;
use Mlangeni\Machinjiri\Core\Transport\SMS\SMSManager;
use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;
use Mlangeni\Machinjiri\Core\Routing\RoutingConfig;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;
use Mlangeni\Machinjiri\Core\Security\Encryption\Bangwe;
use Mlangeni\Machinjiri\Core\Authentication\ThirdParty\ThirdPartyAuth;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Components\Network\Tools\{Manager, Scanner, Monitor, NetworkConfig};
use Mlangeni\Machinjiri\Core\Components\Webhooks\{WebhookSubscriptionManager, WebhookManager};
use Mlangeni\Machinjiri\Core\Components\LDAP\{
    Manager as LDAPManager,
    Connection as LDAPConnection,
    EntryManager
};


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
        // -------------------- HTTP Request/Response --------------------
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

        // -------------------- Authentication Services --------------------
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

        // -------------------- Debugging --------------------
        // Debugger is registered as a singleton for centralized debugging.
        $this->app->singleton(Debugger::class, function ($app) {
            return new Debugger($app);
        });

        // -------------------- Filesystem Adapters --------------------
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

        // -------------------- Caching --------------------
        // CacheManager handles caching strategies using the cache configuration.
        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager($app->configurations['cache']);
        });

        // -------------------- Mail Transport --------------------
        // MailManager is responsible for sending emails; it creates its own logger
        // and event listener internally, and uses a queue dispatcher if available.
        $this->app->singleton(MailManager::class, function ($app) {
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

        // -------------------- Event System --------------------
        // EventListener is bound as a regular binding (not singleton) to allow
        // fresh instances with a dedicated logger.
        $this->bind(EventListener::class, function($app) {
            return new EventListener(new Logger(env('APP_NAME') ?? 'machinjiri', Logger::DEBUG, true, '', 'app'));
        });

        // -------------------- Logging --------------------
        // Logger binding uses the application name from environment, with DEBUG level.
        $this->bind(Logger::class, function($app) {
            return new Logger(env('APP_NAME') ?? 'machinjiri', Logger::DEBUG, false, '', 'app');
        });

        // -------------------- CSRF Protection --------------------
        // CSRFToken singleton requires Session, Cookie, and a token name from env.
        $this->app->singleton(CSRFToken::class, function ($app) {
            return new CSRFToken(
                $app->resolve(Session::class),
                $app->resolve(Cookie::class),
                env("CSRF_TOKEN_NAME", "csrf_token")
            );
        });

        // -------------------- Routing Configuration --------------------
        // RoutingConfig loads the routing.php configuration file if it exists.
        $this->singleton(RoutingConfig::class, function ($app) {
            $config = $this->app->coreConfig . 'routing.php';
            return is_file($config) ? require $config : null;
        });

        // -------------------- Security Services --------------------
        // Hasher provides hashing utilities (bcrypt, etc.) as a singleton.
        $this->singleton(Hasher::class, function ($app) {
            return new Hasher();
        });

        // Bangwe is the encryption service, requiring the application instance.
        $this->singleton(Bangwe::class, function ($app) {
            return new Bangwe($app);
        });

        // -------------------- Third-Party Authentication --------------------
        // ThirdPartyAuth uses the OAuth configuration from the application config.
        $this->singleton(ThirdPartyAuth::class, function ($app) {
            return new ThirdPartyAuth($app->configurations['oauth']);
        });

        // -------------------- LDAP Manager --------------------
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

        // -------------------- Network Components --------------------
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

        // -------------------- Webhook Components --------------------
        // WebhookSubscriptionManager, and WebhookManager are registered as singletons.
        $this->singleton(WebhookSubscriptionManager::class, function ($app) {
            return new WebhookSubscriptionManager($app->configurations['webhooks'] ?? []);
        });

        $this->singleton(WebhookManager::class, function ($app) {
            return new WebhookManager($app, $app->resolve(WebhookSubscriptionManager::class), $app->resolve(CacheManager::class));
        });


        // -------------------- Aliases for Convenience --------------------
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
            'auth.manager'                  => AuthManager::class,
            'security.hasher'               => Hasher::class,
            'security.bangwe'               => Bangwe::class,
            'webhook.manager'               => WebhookManager::class,
            'webhook.subscription.manager'  => WebhookSubscriptionManager::class,
            'ldap.manager'                  => LDAPManager::class,
            'ldap.connection'               => LDAPConnection::class,
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
        }

        $wsm = $this->app->resolve(WebhookSubscriptionManager::class);
        $wsm->registerWebhookHandlers();
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