<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\AuthenticationException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Authentication\Guard;
use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Authentication\UserProviders\{DatabaseUserProvider, OAuthUserProvider, UserProvider};
use Mlangeni\Machinjiri\Core\Authentication\Guards\{SessionGuard, TokenGuard, JwtGuard};
use Mlangeni\Machinjiri\Core\Security\Encryption\Bangwe;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;
use Mlangeni\Machinjiri\Core\Authentication\ThirdParty\ThirdPartyAuth;
use Mlangeni\Machinjiri\Core\Authentication\{Session, Cookie};
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Authentication\Models\{User, LdapModel};
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Authentication\UserProviders\LdapUserProvider;


class AuthManager
{
    protected Container $container;
    protected array $config;
    protected array $guards = [];
    protected ?string $defaultGuard;
    protected ?Guard $currentGuard = null;
    protected EventListener $events;
    protected Logger $logger;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = $config;
        $this->defaultGuard = $config['default'] ?? 'session';
        $this->events = $container->resolve(EventListener::class);
        $this->logger = $container->resolve(Logger::class);
    }

    /**
     * Get a guard instance by name.
     */
    public function guard(?string $name = null): Guard
    {
        $name = $name ?? $this->defaultGuard;

        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }

        $guardConfig = $this->config['guards'][$name] ?? null;
        if (!$guardConfig) {
            throw new AuthenticationException("Authentication Guard [{$name}] not configured.");
        }

        $guard = $this->resolveGuard($name, $guardConfig);
        $this->guards[$name] = $guard;
        return $guard;
    }

    protected function resolveGuard(string $name, array $config): Guard
    {
        $driver = $config['driver'] ?? 'session';

        switch ($driver) {
            case 'session':
                return $this->createSessionGuard($config);
            case 'token': 
                return $this->createTokenGuard($config);
            case 'jwt':
                return $this->createJwtGuard($config);
            default:
                throw new AuthenticationException("Unsupported guard driver [{$driver}]");
        }
    }

    protected function createSessionGuard(array $config): SessionGuard
    {
        $session = $this->container->resolve(Session::class);
        $cookie = $this->container->resolve(Cookie::class);
        $hasher = $config['hasher'] ?? $this->container->resolve(Hasher::class);
        $userProvider = $this->createUserProvider($config);
        $cache = $this->container->resolve(CacheManager::class);
        $rememberExpiration = $config['remember_expiration'] ?? 7 * 24 * 60 * 60;

        return new SessionGuard(
            $session,
            $cookie,
            $userProvider,
            $hasher,
            $this->events,
            $this->logger,
            $cache,
            $rememberExpiration
        );
    }

    protected function createTokenGuard(array $config): TokenGuard
    {
        $userProvider = $this->createUserProvider($config);
        return new TokenGuard($userProvider);
    }

    protected function createJwtGuard(array $config): JwtGuard
    {
        $userProvider = $this->createUserProvider($config);
        $bangwe = $this->container->resolve(Bangwe::class);
        return new JwtGuard($userProvider, $bangwe);
    }

    protected function createUserProvider(array $guardConfig): UserProvider
    {
        $providerConfig = $guardConfig['provider'] ?? ['driver' => 'database'];
        $driver = $providerConfig['driver'] ?? 'database';

        switch (strtolower($driver)) {
            case 'database':
                $model = $providerConfig['model'] ?? User::class;
                return new DatabaseUserProvider(
                    new QueryBuilder('users'),
                    $model,
                    $this->events,
                    $this->logger,
                    $this->container->resolve(CacheManager::class)
                );
            case 'ldap':
                // Uses supplied LDAP components
                $ldapManager = $this->container->resolve('ldap.manager');
                $model = $providerConfig['model'] ?? LdapModel::class;
                return new LdapUserProvider(
                    $ldapManager,
                    $model,
                    $providerConfig['sync_attributes'] ?? [],
                    $providerConfig
                );
            case 'oauth':
                $thirdPartyAuth = $this->container->resolve(ThirdPartyAuth::class);
                $model = $guardConfig['provider']['model'] ?? User::class;
                $logger = $this->container->resolve(Logger::class);
                $events = $this->container->resolve(EventListener::class);
                return new OAuthUserProvider($thirdPartyAuth, $model, $logger, $events);
            default:
               throw new AuthenticationException("Unsupported user provider driver [{$driver}]"); 
        }
    }


    public function check(): bool
    {
        return $this->guard()->check();
    }

    public function guest(): bool
    {
        return $this->guard()->guest();
    }

    public function user(): ?Authenticatable
    {
        return $this->guard()->user();
    }

    public function id(): mixed
    {
        return $this->guard()->id();
    }

    public function attempt(array $credentials, bool $remember = false): bool
    {
        $result = $this->guard()->attempt($credentials, $remember);
        if ($result) {
            $this->events->trigger('auth.login', $this->user());
        } else {
            $this->events->trigger('auth.failed', $credentials);
        }
        return $result;
    }

    public function login(Authenticatable $user, bool $remember = false): void
    {
        $this->guard()->login($user, $remember);
        $this->events->trigger('auth.login', $user);
    }

    public function logout(): void
    {
        $user = $this->user();
        $this->guard()->logout();
        if ($user) {
            $this->events->trigger('auth.logout', $user);
        }
    }

    public function validate(array $credentials): bool
    {
        return $this->guard()->validate($credentials);
    }

    public function setDefaultGuard(string $name): void
    {
        $this->defaultGuard = $name;
    }

    public function getGuards(): array
    {
        return array_keys($this->config['guards'] ?? []);
    }
}