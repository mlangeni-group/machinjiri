<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Guards;

use Mlangeni\Machinjiri\Core\Authentication\Guard;
use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Authentication\UserProviders\UserProvider;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Exceptions\AuthenticationException;
use Mlangeni\Machinjiri\Core\Authentication\Models\LdapModel;

class SessionGuard implements Guard
{
    protected ?Authenticatable $user = null;
    protected Session $session;
    protected Cookie $cookie;
    protected UserProvider $provider;
    protected Hasher $hasher;
    protected EventListener $events;
    protected Logger $logger;
    protected CacheManager $cache;
    protected int $rememberExpiration;
    protected bool $loggedOut = false;

    public function __construct(
        Session $session,
        Cookie $cookie,
        UserProvider $provider,
        Hasher $hasher,
        EventListener $events,
        Logger $logger,
        CacheManager $cache,
        int $rememberExpiration = 2592000 // 30 days
    ) {
        $this->session = $session;
        $this->cookie = $cookie;
        $this->provider = $provider;
        $this->hasher = $hasher;
        $this->events = $events;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->rememberExpiration = $rememberExpiration;
    }

    public function check(): bool
    {
        return !is_null($this->user());
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        // if ($this->loggedOut) {
        //     return null;
        // }

        if (!is_null($this->user)) {
            return $this->user;
        }

        // Check session
        $id = $this->session->get('user_id');
        if ($id) {
            $this->user = $this->provider->retrieveById($id);
            if ($this->user) {
                return $this->user;
            }
            // Invalid session, clear
            $this->session->set('user_id', null);
        }

        // Check remember token
        $token = $this->cookie->get('remember_token');
        if ($token) {
            $this->user = $this->provider->retrieveByRememberToken($token);
            if ($this->user) {
                // Regenerate session to prevent fixation
                $this->session->regenerateId();
                $this->session->set('user_id', $this->user->getAuthIdentifier());
                return $this->user;
            }
            // Invalid token, delete cookie
            $this->cookie->delete('remember_token');
        }

        return null;
    }

    public function id(): mixed
    {
        $user = $this->user();
        return $user ? $user->getAuthIdentifier() : $this->session->get('user_id');
    }

    public function validate(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            $this->logger->info('User not found during validation', ['credentials' => array_keys($credentials)]);
            return false;
        }

        if ($user instanceof LdapModel) {
            $this->logger->info('LDAP user found during validation', ['username' => $user->getAuthIdentifier()]);
            $this->user = $user;
            return true;
        }

        if ($user && $this->hasher->verify($credentials['password'] ?? '', $user->getAuthPassword())) {
            $this->user = $user;
            return true;
        }
        
        return true;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
        $this->loggedOut = false;
        $this->session->regenerateId();
        $this->session->set('user_login', $user->getAuthIdentifier());
    }

    public function attempt(array $credentials, bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);
        if ($this->validate($credentials)) {
            $this->login($user, $remember);
            return true;
        }
        $this->logger->warning('Authentication attempt failed', ['credentials' => array_keys($credentials)]);
        $this->events->trigger('auth.failed', $credentials);
        return false;
    }

    public function login(Authenticatable $user, bool $remember = false): void
    {
        $this->setUser($user);

        if ($remember) {
            $this->createRememberToken($user);
        }

        $this->events->trigger('auth.login', $user);
        $this->logger->info('User logged in: {id}', ['id' => $user->getAuthIdentifier()]);
    }

    public function logout(): void
    {
        $user = $this->user();

        $this->session->destroy();
        $this->user = null;
        $this->loggedOut = true;

        $this->events->trigger('auth.logout', $user);
        $this->logger->info('User logged out id: {id}', ['id' => $user ? $user->getAuthIdentifier() : null]);
    }

    protected function createRememberToken(Authenticatable $user): void
    {
        $plain = bin2hex(random_bytes(32));
        $hashed = $this->hasher->make($plain);
        $this->provider->updateRememberToken($user, $hashed);

        // Store plain token in cookie (with expiration)
        $cookieValue = $user->getAuthIdentifier() . '|' . $plain;
        $this->cookie->set('remember_token', $cookieValue, $this->rememberExpiration, '/', '', true, true);
    }
}