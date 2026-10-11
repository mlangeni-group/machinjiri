<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use \ArrayAccess;
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;

class Entry implements ArrayAccess
{
    protected array $data;
    protected string $dn;
    protected string $baseDn;
    protected ?CacheManager $cache = null;
    protected ?array $nestedGroups = null;

    public function __construct(array $data, string $baseDn, ?CacheManager $cache = null)
    {
        $this->data = $data;
        $this->dn = $data['dn'] ?? '';
        $this->baseDn = $baseDn;
        $this->cache = $cache ?? resolve(CacheManager::class);
    }

    public function getDn(): string
    {
        return $this->dn;
    }

    public function getAttribute(string $name)
    {
        return $this->data[$name][0] ?? ($this->data[$name] ?? null);
    }

    public function getAll(string $name): array
    {
        return $this->data[$name] ?? [];
    }

    public function getAttributes(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Check if entry is a member of the given group, with recursive resolution.
     */
    public function inGroup(string $groupDn): bool
    {
        $groups = $this->getAllGroups();
        return in_array(strtolower($groupDn), array_map('strtolower', $groups));
    }

    /**
     * Get all groups (direct and nested) that this entry belongs to.
     */
    public function getAllGroups(): array
    {
        if ($this->nestedGroups !== null) {
            return $this->nestedGroups;
        }

        $directGroups = $this->getAll('memberof');
        if (!is_array($directGroups)) {
            $directGroups = [];
        }

        $allGroups = $directGroups;
        $visited = [];

        // Resolve nested groups
        foreach ($directGroups as $groupDn) {
            $this->resolveNestedGroups($groupDn, $allGroups, $visited);
        }

        $this->nestedGroups = array_unique($allGroups);
        return $this->nestedGroups;
    }

    protected function resolveNestedGroups(string $groupDn, array &$allGroups, array &$visited): void
    {
        $lower = strtolower($groupDn);
        if (in_array($lower, $visited)) {
            return;
        }
        $visited[] = $lower;

        // Use cache to avoid repeated lookups
        $cacheKey = 'ldap:group_members:' . md5($groupDn);
        if ($this->cache) {
            $members = $this->cache->get($cacheKey);
            if ($members !== null) {
                foreach ($members as $member) {
                    if (!in_array(strtolower($member), array_map('strtolower', $allGroups))) {
                        $allGroups[] = $member;
                        $this->resolveNestedGroups($member, $allGroups, $visited);
                    }
                }
                return;
            }
        }

        // Query LDAP for group members
        $conn = Container::resolve(Connection::class);
        $query = $conn->query()
            ->baseDn($this->baseDn)
            ->select(['member'])
            ->where('dn', '=', $groupDn);
        
        $result = $conn->getLink();
        $search = ldap_read($result, $groupDn, '(objectClass=*)', ['member']);
        if ($search) {
            $entries = ldap_get_entries($result, $search);
            if (isset($entries[0]['member'])) {
                $members = $entries[0]['member'];
                if (!is_array($members)) {
                    $members = [$members];
                }
                if ($this->cache) {
                    $this->cache->set($cacheKey, $members, 600);
                }
                foreach ($members as $member) {
                    if (!in_array(strtolower($member), array_map('strtolower', $allGroups))) {
                        $allGroups[] = $member;
                        $this->resolveNestedGroups($member, $allGroups, $visited);
                    }
                }
            }
        }
    }

    // ArrayAccess implementation
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }
    public function offsetGet($offset): mixed
    {
        return $this->getAttribute($offset);
    }
    public function offsetSet($offset, $value): void
    {
        throw new LdapException('LDAP entries are read-only.');
    }
    public function offsetUnset($offset): void
    {
        throw new LdapException('Cannot unset LDAP attributes.');
    }
}