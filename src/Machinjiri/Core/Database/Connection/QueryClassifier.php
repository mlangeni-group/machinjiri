<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

final class QueryClassifier
{
    /**
     * Determine whether a SQL statement is safe to run on a read replica.
     */
    public static function isReadQuery(string $sql): bool
    {
        $sql = preg_replace(
            '/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|\#[^\r\n]*(?:\r?\n|\z)|\/\*.*?\*\/)*/s',
            '',
            $sql
        ) ?? '';

        if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
            return false;
        }

        return !preg_match(
            '/\b(FOR\s+(UPDATE|NO\s+KEY\s+UPDATE|SHARE|KEY\s+SHARE)|LOCK\s+IN\s+SHARE\s+MODE|INTO\s+(OUTFILE|DUMPFILE))\b|:=|\b(NEXTVAL|SETVAL|PG_ADVISORY_LOCK|GET_LOCK|RELEASE_LOCK|LAST_INSERT_ID)\s*\(/i',
            $sql
        );
    }
}