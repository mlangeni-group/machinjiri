<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Models;

use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractModel;
use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Components\LDAP\Manager as LdapManager;
use Mlangeni\Machinjiri\Core\Components\LDAP\{Entry, Connection, EntryManager};
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Override;

class OauthModel extends AbstractModel implements Authenticatable
{

    private Entry $ldapEntry;
    private string $identifier;

    protected array $attributes = ['mail', 'password', 'uid'];
    protected array $fillable = ['remember_token'];

    public function getAuthIdentifierName(): string
    {
        return $this->identifier;
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->ldapEntry->getAttribute($this->getAuthIdentifierName());
    }

    public function getAuthPassword(): string
    {
        return ''; // LDAP users don't have a local password
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

    public function hasRole(string $role): bool
    {
        return false;
    }

    public function hasPermission(string $permission): bool
    {
        return false;   
    }

    public function isLdapUser(): bool
    {
        return true;
    }

    public function setLdapEntry(Entry $entry, string $identifier): void 
    {
        $this->ldapEntry = $entry;
        $this->identifier = $identifier;
    }

}