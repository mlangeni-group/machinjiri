<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Container;

class EntryManager
{
    protected Connection $connection;
    protected Logger $logger;
    protected EventListener $events;

    public function __construct(
        Connection $connection,
        ?Logger $logger = null,
        ?EventListener $events = null
    ) {
        $this->connection = $connection;
        $this->logger = $logger ?? resolve(Logger::class);
        $this->events = $events ?? resolve(EventListener::class);
    }

    /** 
     * Create a new LDAP entry.
     *
     * @param string $dn      Distinguished Name (e.g., "uid=johndoe,ou=people,dc=example,dc=com")
     * @param array  $attrs   Associative array of attributes (e.g., ['cn' => 'John Doe', 'sn' => 'Doe', ...])
     * @return bool
     * @throws LdapException
     */
    public function create(string $dn, array $attrs): bool
    {
        $this->events->trigger('ldap.entry.creating', ['dn' => $dn, 'attrs' => $attrs]);
        $this->logger->info('Creating LDAP entry', ['dn' => $dn]);

        $link = $this->connection->getLink();

        try {
            ldap_add($link, $dn, $attrs);
        } catch (\Throwable $e) {
            $this->logger->error('LDAP add failed', ['dn' => $dn, 'error' => $e->getMessage()]);
            throw new LdapException("Failed to create entry: " . $e->getMessage(), 500, $e, ['dn' => $dn]);
        }

        $this->events->trigger('ldap.entry.created', ['dn' => $dn]);
        $this->logger->info('LDAP entry created', ['dn' => $dn]);
        return true;
    }

    /**
     * Read an LDAP entry by DN.
     *
     * @param string $dn
     * @param array  $attributes  List of attributes to fetch (empty = all)
     * @return Entry|null
     */
    public function read(string $dn, array $attributes = []): ?Entry
    {
        try {
            $link = $this->connection->getLink();
            $result = ldap_read($link, $dn, '(objectClass=*)', $attributes);
            if (!$result) {
                $this->logger->warning('LDAP read failed', ['dn' => $dn, 'error' => ldap_error($link)]);
                return null;
            }
            $entries = ldap_get_entries($link, $result);
            if ($entries['count'] === 0) {
                return null;
            }
            return new Entry($entries[0], $this->connection->getBaseDn());
        } catch (\Throwable $e) {
            $this->logger->error('LDAP read failed', ['dn' => $dn, 'error' => $e->getMessage()]);
            throw new LdapException("Failed to read entry: " . $e->getMessage(), 500, $e, ['dn' => $dn]);
            return null;
        }
    }

    /**
     * Replace the supplied attributes on an LDAP entry.
     *
     * @param string $dn
     * @param array  $newAttrs Attributes to replace; attributes not included are unchanged.
     * @param bool   $replace Legacy parameter retained for backwards compatibility.
     * @deprecated The $replace parameter has no effect.
     * @return bool
     * @throws LdapException
     */
    public function update(string $dn, array $newAttrs, bool $replace = true): bool
    {
        return $this->modifyAttributes(
            $dn,
            $newAttrs,
            static fn ($link, string $entryDn, array $attrs): bool => ldap_modify($link, $entryDn, $attrs),
            'modify'
        );
    }

    /**
     * Add values to attributes on an LDAP entry.
     *
     * @throws LdapException
     */
    public function addAttributes(string $dn, array $attrs): bool
    {
        return $this->modifyAttributes(
            $dn,
            $attrs,
            static fn ($link, string $entryDn, array $values): bool => ldap_mod_add($link, $entryDn, $values),
            'add'
        );
    }

    /**
     * Delete attribute values from an LDAP entry. Use an empty value list to
     * delete an entire attribute.
     *
     * @throws LdapException
     */
    public function deleteAttributes(string $dn, array $attrs): bool
    {
        return $this->modifyAttributes(
            $dn,
            $attrs,
            static fn ($link, string $entryDn, array $values): bool => ldap_mod_del($link, $entryDn, $values),
            'delete'
        );
    }

    /**
     * Replace attribute values on an LDAP entry.
     *
     * @throws LdapException
     */
    public function replaceAttributes(string $dn, array $attrs): bool
    {
        return $this->modifyAttributes(
            $dn,
            $attrs,
            static fn ($link, string $entryDn, array $values): bool => ldap_mod_replace($link, $entryDn, $values),
            'replace'
        );
    }

    /**
     * Apply mixed add, delete, and replace operations as one LDAP batch.
     *
     * Each modification must contain an attribute name, a batch operation
     * constant, and an array of values. An empty values array deletes the
     * entire attribute when using LDAP_MODIFY_BATCH_REMOVE.
     *
     * @param array $modifications
     * @throws LdapException
     */
    public function updateAdvanced(string $dn, array $modifications): bool
    {
        $allowedTypes = [
            LDAP_MODIFY_BATCH_ADD,
            LDAP_MODIFY_BATCH_REMOVE,
            LDAP_MODIFY_BATCH_REPLACE,
        ];

        foreach ($modifications as $modification) {
            if (
                !is_array($modification)
                || !isset($modification['attrib'], $modification['modtype'], $modification['values'])
                || !is_string($modification['attrib'])
                || $modification['attrib'] === ''
                || !is_int($modification['modtype'])
                || !in_array($modification['modtype'], $allowedTypes, true)
                || !is_array($modification['values'])
            ) {
                throw new LdapException(
                    'Invalid LDAP batch modification. Each operation requires an attrib, valid modtype, and values array.',
                    400,
                    null,
                    ['dn' => $dn]
                );
            }
        }

        return $this->modifyAttributes(
            $dn,
            $modifications,
            static fn ($link, string $entryDn, array $changes): bool => ldap_modify_batch($link, $entryDn, $changes),
            'batch modify'
        );
    }

    private function modifyAttributes(string $dn, array $attrs, callable $modifier, string $operation): bool
    {
        if ($attrs === []) {
            throw new LdapException(
                'LDAP modification must include at least one attribute operation.',
                400,
                null,
                ['dn' => $dn]
            );
        }

        $this->events->trigger('ldap.entry.updating', ['dn' => $dn, 'attrs' => $attrs]);
        $this->logger->info('Updating LDAP entry', ['dn' => $dn, 'operation' => $operation]);

        $link = $this->connection->getLink();
        try {
            if (!$modifier($link, $dn, $attrs)) {
                $error = ldap_error($link);
                throw new \RuntimeException(
                    $error !== '' ? $error : 'LDAP operation returned false without an error message.'
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('LDAP modify failed', ['dn' => $dn, 'error' => $e->getMessage()]);
            throw new LdapException("Failed to update entry: " . $e->getMessage(), 500, $e, ['dn' => $dn]);
        }

        $this->events->trigger('ldap.entry.updated', ['dn' => $dn]);
        $this->logger->info('LDAP entry updated', ['dn' => $dn]);
        return true;
    }

    /**
     * Delete an LDAP entry.
     *
     * @param string $dn
     * @return bool
     * @throws LdapException
     */
    public function delete(string $dn): bool
    {
        $this->events->trigger('ldap.entry.deleting', ['dn' => $dn]);
        $this->logger->info('Deleting LDAP entry', ['dn' => $dn]);

        $link = $this->connection->getLink();
        if (!ldap_delete($link, $dn)) {
            $error = ldap_error($link);
            $this->logger->error('LDAP delete failed', ['dn' => $dn, 'error' => $error]);
            throw new LdapException("Failed to delete entry: $error", 500, null, ['dn' => $dn]);
        }

        $this->events->trigger('ldap.entry.deleted', ['dn' => $dn]);
        $this->logger->info('LDAP entry deleted', ['dn' => $dn]);
        return true;
    }
}