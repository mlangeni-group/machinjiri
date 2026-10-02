<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class DatabaseException extends MachinjiriException 
{
    public static function ConnectionError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Connection: " . $message, $previous);
    }

    public static function QueryBuilderError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Builder: " . $message, $previous);
    }

    public static function SchemaError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Schema: " . $message, $previous);
    }

    public static function GrammarError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Grammar: " . $message, $previous);
    }

    public static function CachingError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Caching: " . $message, $previous);
    }

    public static function MigrationError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Migration: " . $message, $previous);
    }

    public static function SeedingError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Seeder: " . $message, $previous);
    }

    public static function FactoryError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Factory: " . $message, $previous);
    }

    public static function QueryError(string $message, ?\Throwable $previous = null): MachinjiriException 
    {
        return self::database("Database Query: " . $message, $previous);
    }

}