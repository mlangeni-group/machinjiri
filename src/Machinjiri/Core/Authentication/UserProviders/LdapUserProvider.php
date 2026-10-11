<?php

namespace Mlangeni\Machinjiri\Core\Authentication\UserProviders;

use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Components\LDAP\Manager as LdapManager;
use Mlangeni\Machinjiri\Core\Components\LDAP\{Entry, EntryManager};
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Authentication\Models\LdapModel;
 
class LdapUserProvider implements UserProvider
{
    protected LdapManager $ldap;
    protected string $modelClass;
    protected array $syncAttributes;
    protected string $usernameAttribute = 'uid';
    protected array $searchFields = ['uid', 'mail'];
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;
    protected int $cacheTtl = 300;

    public function __construct(
        LdapManager $ldap,
        string $modelClass,
        array $syncAttributes,
        array $config = []
    ) {
        $this->ldap = $ldap;
        $this->modelClass = $modelClass;
        $this->syncAttributes = $syncAttributes;
        $this->usernameAttribute = $config['username_attribute'] ?? 'uid';
        $this->searchFields = $config['search_fields'] ?? ['uid', 'mail'];
        $this->cacheTtl = $config['cache_ttl'] ?? 300;
        $this->logger = resolve(Logger::class);
        $this->events = resolve(EventListener::class);
        $this->cache = resolve(CacheManager::class);
    }

    public function retrieveById($id): ?Authenticatable
    {
        // Not directly supported; rely on local sync.
        return null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $username = $credentials['username'] ?? ($credentials['email'] ?? $credentials['mail'] ?? null);
        if (!$username) {
            return null;
        }

        $syncToDatabase = $credentials['sync'] ?? false;

        $this->events->trigger('ldap.user.retrieving', ['username' => $username]);

        // Check cache
        $cacheKey = 'ldap:user:' . md5($username);
        $cachedEntry = $this->cache->get($cacheKey);
        if ($cachedEntry) {
            $this->logger->debug('LDAP user cache hit', ['username' => $username]);
            return $this->syncLocalUser($cachedEntry, $syncToDatabase);
        }

        $entry = null;
        $identifier = null;
        foreach ($this->searchFields as $field) {
            $query = $this->ldap->query()
                ->where($field, '=', $username)
                ->select(array_values($this->syncAttributes));
            $entry = $query->first();

            if ($entry) {
                $identifier = $field;
                break;
            }
        }

        if ($entry === null || !$entry instanceof Entry) {
            $this->logger->info('LDAP user not found', ['username' => $username]);
            $this->events->trigger('ldap.user.notfound', ['username' => $username]);
            return null;
        }

        $this->cache->set($cacheKey, $entry, $this->cacheTtl);
        $localUser = $this->syncLocalUser($entry, $syncToDatabase);

        // Store the LDAP entry on the user (if we add a setter)
        if (method_exists($localUser, 'setLdapEntry')) {
            $localUser->setLdapEntry($entry, $identifier);
        }
        $this->events->trigger('ldap.user.retrieved', ['user' => $localUser]);
        return $localUser;
    }

    public function retrieveByRememberToken(string $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, string $token): void
    {
        // Update the local user model if needed
        if (method_exists($user, 'setRememberToken')) {
            $user->setRememberToken($token);
        }
    }

    protected function syncLocalUser(Entry $entry, bool $syncToDatabase = false): Authenticatable
    {
        $modelClass = $this->modelClass;
        $username = $entry->getAttribute($this->usernameAttribute);
        if (!$username) {
            throw new LdapException("Username attribute '{$this->usernameAttribute}' not found.");
        }

        $attributes = [];

        foreach ($this->syncAttributes as $localAttr => $ldapAttr) {
            $attributes[$localAttr] = $entry->getAttribute($ldapAttr);
        }

        $attributes['username'] = $username;

        if (!$syncToDatabase) {
            // Create a new instance without saving to the database
            return new LdapModel($attributes);
        }

        $local = $modelClass::where('username', $username)->first();

        if ($local) {
            $local->fill($attributes);
            $local->save();
        } else {
            $local = new $modelClass($attributes);
            $local->save();
        }
        return $local;
    }

    public function validateCredentials($user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        if (!$password) {
            return false;
        }
        // Check LDAP bind using the user's DN (must be stored on the user)
        $dn = method_exists($user, 'getLdapDn') ? $user->getLdapDn() : null;
        if (!$dn) {
            return false;
        }
        try {
            return $this->ldap->connection()->bind($dn, $password);
        } catch (LdapException $e) {
            $this->logger->warning('LDAP bind failed', ['dn' => $dn, 'error' => $e->getMessage()]);
            return false;
        }
    }
}