<?php

namespace Mlangeni\Machinjiri\Core\Database\Connection;

use \PDO;
use \PDOException;
use \PDOStatement;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Artisans\Events\Factory;

/**
 * Runs prepared statements and emits the query event.
 */
final class PdoQueryRunner
{
    public function run(PDO $connection, string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);

            Factory::database('query.executed', [
                'query'  => $sql,
                'params' => $params,
            ]);
            return $stmt;
        } catch (PDOException $e) {
            throw DatabaseException::QueryError("Query execution failed: " . $e->getMessage());
        }
    }
}