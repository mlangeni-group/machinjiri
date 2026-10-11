<?php

namespace Mlangeni\Machinjiri\Core\Authentication\UserProviders;

use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Exceptions\AuthenticationException;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;

class DatabaseUserProvider implements UserProvider
{
    protected QueryBuilder $query;
    protected string $model;
    protected EventListener $events;
    protected Logger $logger;
    protected CacheManager $cache;
    protected int $cacheTtl = 300;

    public function __construct(
        QueryBuilder $query,
        string $model,
        EventListener $events,
        Logger $logger,
        CacheManager $cache,
        int $cacheTtl = 300
    ) {
        $this->query = $query;
        $this->model = $model;
        $this->events = $events;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    public function retrieveById($id): ?Authenticatable
    {
        $cacheKey = 'user:' . $id;
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $this->hydrateModel($cached);
        }

        $data = $this->query->select()->where('id', '=', $id)->first();
        if ($data) {
            $model = $this->hydrateModel($data);
            $this->cache->set($cacheKey, $data, $this->cacheTtl);
            return $model;
        }
        return null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = clone $this->query;
        
        foreach ($credentials as $key => $value) {
            if ($key !== 'password') {
                $query->select()->where($key, '=', $value);
            }
        }
        $result = $query->first();
        if ($result === null) throw new AuthenticationException("Authentication Error: User does not exist");
        return $this->hydrateModel($result);
    }

    public function retrieveByRememberToken(string $token): ?Authenticatable
    {
        // Token format: "id|plain"
        $parts = explode('|', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$id, $plain] = $parts;

        $user = $this->retrieveById($id);
        if (!$user) {
            return null;
        }

        $hashed = $user->getRememberToken();
        if (empty($hashed)) {
            return null;
        }

        // Verify using the hasher
        if ($this->getHasher()->verify($plain, $hashed)) {
            return $user;
        }
        return null;
    }

    protected function getHasher(): Hasher
    {
        return new Hasher();
    }

    public function updateRememberToken(Authenticatable $user, string $token): void
    {
        $user->setRememberToken($token);
        $id = $user->getAuthIdentifier();

        if (empty($id)) throw new AuthenticationException("Unknown identifier for user");

        $this->query
                ->update(['remember_token' => $token])
                ->where('id', '=', $id)
                ->execute();
        // Invalidate cache
        $this->cache->delete('user:' . $id);
    }

    protected function hydrateModel(array $data): Authenticatable
    {
        $model = new $this->model($data);
        if (method_exists($model, 'exists')) {
            $model->exists = true;
        }
        return $model;
    }
}