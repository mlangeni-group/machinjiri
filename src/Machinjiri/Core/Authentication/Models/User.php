<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Models;

use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractModel;
use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;

class User extends AbstractModel implements Authenticatable
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected array $fillable = ['name', 'email', 'password', 'remember_token', 'role', 'permissions'];
    protected array $hidden = ['password', 'remember_token'];
    protected array $casts = [
        'permissions' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected static bool $cacheEnabled = false;

    // --- Authenticatable methods ---

    public function getAuthIdentifier(): mixed
    {
        $identifier = $this->getAttribute($this->getAuthIdentifierName());
        if ($identifier === null) {
            $query = (!$this->cacheEnabled) ? $this->newQuery() : $this->newCachedQuery() ;
            $result = $query->select([$this->getAuthIdentifierName()])
                        ->where('email', '=', $this->getAuthEmail())
                        ->first();
            $identifier = $result[$this->getAuthIdentifierName()];
        }
        return $identifier;
    }
    
    public function getAuthPassword(): string
    {
        return $this->getAttribute('password') ?? '';
    }

    public function getAuthEmail(): string
    {
        return $this->getAttribute('email') ?? '';
    }

    public function getRememberToken(): string
    {
        return $this->getAttribute('remember_token') ?? '';
    }

    public function setRememberToken(string $token): void
    {
        $this->setAttribute('remember_token', $token);
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function getAuthIdentifierName(): string
    {
        return $this->primaryKey;
    }

    // --- Role & Permission helpers ---

    public function hasRole(string $role): bool
    {
        return $this->getAttribute('role') === $role;
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getAttribute('permissions') ?? [];
        return in_array($permission, $permissions);
    }

    // Magic accessors to support legacy code
    public function __get($name): mixed
    {
        return $this->getAttribute($name);
    }
}