<?php

namespace Mlangeni\Machinjiri\Core\Database\Schema;

use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\Builders\ColumnBuilder;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;

class Blueprint
{
    protected string $table;
    protected QueryBuilder $query;
    protected array $columns = [];
    protected array $indexes = [];
    protected array $foreignKeys = [];
    protected array $primaryKey = [];
    protected array $uniqueKeys = [];
    protected array $dropColumns = [];
    protected array $renameColumns = [];
    protected array $modifyColumns = [];
    protected array $dropIndexes = [];
    protected array $dropForeignKeys = [];
    protected array $tableOptions = [];
    protected string $action = 'create'; // 'create', 'alter', 'drop'
    protected bool $ifNotExists = false;
    protected bool $temporary = false;
    protected bool $withTimestamps = false;
    protected bool $withSoftDeletes = false;
    protected string $comment = '';

    public function __construct(string $table, QueryBuilder $query)
    {
        $this->table = $table;
        $this->query = $query;
    }

    /**
     * Set table creation options
     */
    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    /**
     * Use CREATE TABLE IF NOT EXISTS
     */
    public function ifNotExists(): self
    {
        $this->ifNotExists = true;
        return $this;
    }

    /**
     * Create a temporary table
     */
    public function temporary(): self
    {
        $this->temporary = true;
        return $this;
    }

    /**
     * Add table comment
     */
    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * Add table engine option (MySQL)
     */
    public function engine(string $engine): self
    {
        $this->tableOptions['engine'] = $engine;
        return $this;
    }

    /**
     * Add table charset option
     */
    public function charset(string $charset): self
    {
        $this->tableOptions['charset'] = $charset;
        return $this;
    }

    /**
     * Add table collation option
     */
    public function collation(string $collation): self
    {
        $this->tableOptions['collation'] = $collation;
        return $this;
    }

    /**
     * Add auto-increment starting value (MySQL)
     */
    public function autoIncrement(int $start): self
    {
        $this->tableOptions['auto_increment'] = $start;
        return $this;
    }

    /**
     * Add ID column with auto-increment primary key
     */
    public function id(string $name = 'id'): ColumnBuilder
    {
        $column = $this->query->id()->autoIncrement()->primaryKey();
        $this->columns[$name] = $column;
        $this->primaryKey = [$name];
        return $column;
    }

    /**
     * Add UUID primary key
     */
    public function uuid(string $name = 'uuid'): ColumnBuilder
    {
        $column = $this->query->string($name, 36)->primaryKey();
        $this->columns[$name] = $column;
        $this->primaryKey = [$name];
        return $column;
    }

    /**
     * Add string column
     */
    public function string(string $name, int $length = 255): ColumnBuilder
    {
        $column = $this->query->string($name, $length);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add text column
     */
    public function text(string $name): ColumnBuilder
    {
        $column = $this->query->text($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add long text column (MySQL) or text (others)
     */
    public function longText(string $name): ColumnBuilder
    {
        // For MySQL, we'd want LONGTEXT, but our grammar doesn't support it yet
        // For now, use TEXT
        $column = $this->query->text($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add integer column
     */
    public function integer(string $name, int $length = 11): ColumnBuilder
    {
        $column = $this->query->integer($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add tiny integer column
     */
    public function tinyInteger(string $name): ColumnBuilder
    {
        $column = $this->query->integer($name); // Use integer for now
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add big integer column
     */
    public function bigInteger(string $name): ColumnBuilder
    {
        // Use integer for now, could be BIGINT in MySQL grammar
        $column = $this->query->integer($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add unsigned integer column
     */
    public function unsignedInteger(string $name): ColumnBuilder
    {
        $column = $this->query->integer($name)->unsigned();
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add unsigned big integer column
     */
    public function unsignedBigInteger(string $name): ColumnBuilder
    {
        $column = $this->query->integer($name)->unsigned();
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add foreign key column
     */
    public function foreignId(string $name): ColumnBuilder
    {
        $column = $this->query->foreignId($name)->unsigned();
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add foreign key column with constrained reference
     */
    public function foreignIdFor(string $name, string $relatedTable): ColumnBuilder
    {
        $column = $this->query->foreignId($name)->unsigned();
        $this->columns[$name] = $column;
        
        // Store foreign key for later constraint creation
        $this->foreignKeys[] = [
            'column' => $name,
            'references' => 'id',
            'on' => $relatedTable
        ];
        
        return $column;
    }

    /**
     * Add boolean column
     */
    public function boolean(string $name): ColumnBuilder
    {
        $column = $this->query->boolean($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add float column
     */
    public function float(string $name, int $precision = 8, int $scale = 2): ColumnBuilder
    {
        $column = $this->query->float($name, $precision, $scale);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add decimal column
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnBuilder
    {
        $column = $this->query->decimal($name, $precision, $scale);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add date column
     */
    public function date(string $name): ColumnBuilder
    {
        $column = $this->query->date($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add date-time column
     */
    public function dateTime(string $name): ColumnBuilder
    {
        $column = $this->query->dateTime($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add timestamp column
     */
    public function timestamp(string $name): ColumnBuilder
    {
        $column = $this->query->timestamp($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add timestamps columns (created_at, updated_at)
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        $this->withTimestamps = true;
    }

    /**
     * Add timestamp with timezone (PostgreSQL)
     */
    public function timestampTz(string $name): ColumnBuilder
    {
        // For now, use regular timestamp
        return $this->timestamp($name);
    }

    /**
     * Add soft deletes column
     */
    public function softDeletes(string $column = 'deleted_at'): ColumnBuilder
    {
        $col = $this->timestamp($column)->nullable();
        $this->withSoftDeletes = true;
        return $col;
    }

    /**
     * Add JSON column
     */
    public function json(string $name): ColumnBuilder
    {
        // Note: JSON type not implemented in our grammar yet
        // For MySQL 5.7+/PostgreSQL 9.4+, we'd want JSON
        // For older versions/SQLite, use TEXT
        $column = $this->query->text($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add JSONB column (PostgreSQL)
     */
    public function jsonb(string $name): ColumnBuilder
    {
        // PostgreSQL specific, use TEXT for now
        $column = $this->query->text($name);
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add enum column
     */
    public function enum(string $name, array $allowed): ColumnBuilder
    {
        // Create ENUM type string for MySQL, or CHECK constraint for others
        $enumValues = "'" . implode("','", $allowed) . "'";
        $definition = "ENUM({$enumValues})";
        
        // Since our ColumnBuilder doesn't support custom types directly,
        // we'll create a custom column definition
        $column = new \Mlangeni\Machinjiri\Core\Database\ColumnBuilder(
            $name,
            $definition,
            $this->query->getGrammar() // We need to add getGrammar() method to QueryBuilder
        );
        
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add set column (MySQL)
     */
    public function set(string $name, array $allowed): ColumnBuilder
    {
        $setValues = "'" . implode("','", $allowed) . "'";
        $definition = "SET({$setValues})";
        
        $column = new \Mlangeni\Machinjiri\Core\Database\ColumnBuilder(
            $name,
            $definition,
            $this->query->getGrammar()
        );
        
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add binary column
     */
    public function binary(string $name, int $length = 255): ColumnBuilder
    {
        // BINARY or VARBINARY type
        $definition = "VARBINARY({$length})";
        
        $column = new \Mlangeni\Machinjiri\Core\Database\ColumnBuilder(
            $name,
            $definition,
            $this->query->getGrammar()
        );
        
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add spatial column (MySQL/PostGIS)
     */
    public function point(string $name): ColumnBuilder
    {
        $definition = "POINT";
        
        $column = new \Mlangeni\Machinjiri\Core\Database\ColumnBuilder(
            $name,
            $definition,
            $this->query->getGrammar()
        );
        
        $this->columns[$name] = $column;
        return $column;
    }

    /**
     * Add composite primary key
     */
    public function primary(array|string $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        
        $this->primaryKey = $columns;
        return $this;
    }

    /**
     * Add unique constraint
     */
    public function unique(array|string $columns, ?string $name = null): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        
        $indexName = $name ?? $this->generateIndexName($columns, 'unique');
        
        $this->uniqueKeys[$indexName] = [
            'columns' => $columns,
            'type' => 'unique'
        ];
        
        return $this;
    }

    /**
     * Add index
     */
    public function index(array|string $columns, ?string $name = null, string $type = 'index'): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        
        $indexName = $name ?? $this->generateIndexName($columns, $type);
        
        $this->indexes[$indexName] = [
            'columns' => $columns,
            'type' => $type
        ];
        
        return $this;
    }

    /**
     * Add fulltext index (MySQL)
     */
    public function fullText(array|string $columns, ?string $name = null): self
    {
        return $this->index($columns, $name, 'fulltext');
    }

    /**
     * Add spatial index (MySQL)
     */
    public function spatialIndex(array|string $columns, ?string $name = null): self
    {
        return $this->index($columns, $name, 'spatial');
    }

    /**
     * Add foreign key constraint
     */
    public function foreign(
        string $column,
        ?string $name = null,
        string $references = 'id',
        ?string $on = null,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT'
    ): self {
        $foreignTable = $on ?: $this->guessTableNameFromColumn($column);
        
        $constraintName = $name ?? $this->generateForeignKeyName($column, $foreignTable);
        
        $this->foreignKeys[$constraintName] = [
            'column' => $column,
            'references' => $references,
            'on' => $foreignTable,
            'onDelete' => $onDelete,
            'onUpdate' => $onUpdate
        ];
        
        return $this;
    }

    /**
     * Add drop column for alter table
     */
    public function dropColumn(string|array $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        
        $this->dropColumns = array_merge($this->dropColumns, $columns);
        return $this;
    }

    /**
     * Add rename column for alter table
     */
    public function renameColumn(string $from, string $to): self
    {
        $this->renameColumns[$from] = $to;
        return $this;
    }

    /**
     * Add drop primary key for alter table
     */
    public function dropPrimary(?string $name = null): self
    {
        $this->primaryKey = [];
        return $this;
    }

    /**
     * Add drop unique constraint for alter table
     */
    public function dropUnique(string $index): self
    {
        $this->dropIndexes[] = $index;
        unset($this->uniqueKeys[$index]);
        return $this;
    }

    /**
     * Add drop index for alter table
     */
    public function dropIndex(string $index): self
    {
        $this->dropIndexes[] = $index;
        unset($this->indexes[$index]);
        return $this;
    }

    /**
     * Add drop foreign key for alter table
     */
    public function dropForeign(string $index): self
    {
        $this->dropForeignKeys[] = $index;
        unset($this->foreignKeys[$index]);
        return $this;
    }

    /**
     * Add drop timestamps columns
     */
    public function dropTimestamps(): self
    {
        $this->dropColumn(['created_at', 'updated_at']);
        return $this;
    }

    /**
     * Add drop soft deletes column
     */
    public function dropSoftDeletes(string $column = 'deleted_at'): self
    {
        $this->dropColumn($column);
        return $this;
    }

    /**
     * Build and execute the schema operations
     */
    public function build(): void
    {
        switch ($this->action) {
            case 'create':
                $this->buildCreateTable();
                break;
            case 'alter':
                $this->buildAlterTable();
                break;
            case 'drop':
                $this->buildDropTable();
                break;
            case 'dropIfExists':
                $this->buildDropTableIfExists();
                break;
            default:
                throw DatabaseException::SchemaError("Unknown blueprint action: {$this->action}");
        }
    }

    /**
     * Build CREATE TABLE statement
     */
    protected function buildCreateTable(): void
    {
        // Prepare column definitions
        $columnDefinitions = [];
        
        foreach ($this->columns as $name => $column) {
            $columnDefinitions[$name] = (string) $column;
        }
        
        // Add primary key constraint if composite
        if (count($this->primaryKey) > 1) {
            $columns = implode(', ', $this->primaryKey);
            $columnDefinitions[] = "PRIMARY KEY ({$columns})";
        }
        
        // Add unique constraints
        foreach ($this->uniqueKeys as $name => $constraint) {
            $columns = implode(', ', $constraint['columns']);
            $columnDefinitions[] = "CONSTRAINT {$name} UNIQUE ({$columns})";
        }
        
        // Add indexes
        foreach ($this->indexes as $name => $index) {
            $columns = implode(', ', $index['columns']);
            $type = strtoupper($index['type']);
            
            // MySQL supports different index types
            if ($type === 'FULLTEXT') {
                $columnDefinitions[] = "FULLTEXT INDEX {$name} ({$columns})";
            } elseif ($type === 'SPATIAL') {
                $columnDefinitions[] = "SPATIAL INDEX {$name} ({$columns})";
            } else {
                $columnDefinitions[] = "INDEX {$name} ({$columns})";
            }
        }
        
        // Add foreign keys
        foreach ($this->foreignKeys as $name => $fk) {
            $columnDefinitions[] = sprintf(
                "CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s",
                $name,
                $fk['column'],
                $fk['on'],
                $fk['references'],
                $fk['onDelete'],
                $fk['onUpdate']
            );
        }
        
        // Execute create table
        $this->query->createTable($this->table, $columnDefinitions, $this->tableOptions)->execute();
    }

    /**
     * Build ALTER TABLE statement
     */
    protected function buildAlterTable(): void
    {
        // Start alter table
        $this->query->alterTable($this->table);
        
        // Add new columns
        foreach ($this->columns as $name => $column) {
            $this->query->addColumn($name, (string) $column);
        }
        
        // Drop columns
        foreach ($this->dropColumns as $column) {
            $this->query->dropColumn($column);
        }
        
        // Modify columns (not yet implemented in QueryBuilder)
        foreach ($this->modifyColumns as $name => $definition) {
            // This would require modifyColumn method in QueryBuilder
            // $this->query->modifyColumn($name, $definition);
        }
        
        // Execute alter table
        $this->query->execute();
        
        // Note: Index and foreign key modifications would need separate SQL statements
        // as most databases don't support them in the same ALTER TABLE
    }

    /**
     * Build DROP TABLE statement
     */
    protected function buildDropTable(): void
    {
        $this->query->dropTable($this->table)->execute();
    }

    /**
     * Build DROP TABLE IF EXISTS statement
     */
    protected function buildDropTableIfExists(): void
    {
        // Not directly supported in current QueryBuilder
        // For now, use regular drop and catch exception
        try {
            $this->query->dropTable($this->table)->execute();
        } catch (\Exception $e) {
            // Table didn't exist, that's OK
        }
    }

    /**
     * Generate index name automatically
     */
    protected function generateIndexName(array $columns, string $type): string
    {
        $prefix = match($type) {
            'unique' => 'unique',
            'fulltext' => 'fulltext',
            'spatial' => 'spatial',
            default => 'index'
        };
        
        return $prefix . '_' . $this->table . '_' . implode('_', $columns);
    }

    /**
     * Generate foreign key name automatically
     */
    protected function generateForeignKeyName(string $column, string $foreignTable): string
    {
        return 'fk_' . $this->table . '_' . $column . '_' . $foreignTable;
    }

    /**
     * Guess table name from foreign key column name
     */
    protected function guessTableNameFromColumn(string $column): string
    {
        // Remove _id suffix if present
        if (str_ends_with($column, '_id')) {
            return substr($column, 0, -3);
        }
        
        // Singularize if needed (basic)
        if (str_ends_with($column, 's_id')) {
            return substr($column, 0, -4);
        }
        
        throw DatabaseException::SchemaError(
            "Could not guess table name from column '{$column}'. " .
            "Please specify the table name explicitly."
        );
    }

    /**
     * Get the underlying QueryBuilder instance
     */
    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Get table name
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Check if blueprint has timestamps
     */
    public function hasTimestamps(): bool
    {
        return $this->withTimestamps;
    }

    /**
     * Check if blueprint has soft deletes
     */
    public function hasSoftDeletes(): bool
    {
        return $this->withSoftDeletes;
    }

    /**
     * Magic method to forward method calls to ColumnBuilder
     * Useful for adding custom column types
     */
    public function __call(string $method, array $arguments)
    {
        // Check if QueryBuilder has this method
        if (method_exists($this->query, $method)) {
            $columnName = $arguments[0] ?? null;
            if (!$columnName) {
                throw DatabaseException::SchemaError("Column name required for method: {$method}");
            }
            
            $column = call_user_func_array([$this->query, $method], $arguments);
            $this->columns[$columnName] = $column;
            return $column;
        }
        
        throw DatabaseException::SchemaError("Method {$method} not found in Blueprint or QueryBuilder");
    }
    
    /**
     * Add audit columns (created_by, updated_by, deleted_by)
     */
    public function auditColumns(): void
    {
        $this->foreignId('created_by')->nullable();
        $this->foreignId('updated_by')->nullable();
        $this->foreignId('deleted_by')->nullable();
    }
    
    /**
     * Add slug column with index
     */
    public function slug(string $name = 'slug'): ColumnBuilder
    {
        $column = $this->string($name, 255)->nullable();
        $this->index($name, "idx_{$this->table}_{$name}");
        return $column;
    }
    
    /**
     * Add polymorphic relationship columns
     */
    public function morphs(string $name, bool $nullable = false): void
    {
        $this->string("{$name}_type", 100);
        $this->foreignId("{$name}_id");
        
        if ($nullable) {
            $this->columns["{$name}_type"]->nullable();
            $this->columns["{$name}_id"]->nullable();
        }
        
        $this->index(["{$name}_type", "{$name}_id"], "idx_{$name}_morph");
    }
    
    /**
     * Add remember token for authentication
     */
    public function rememberToken(): ColumnBuilder
    {
        return $this->string('remember_token', 100)->nullable();
    }
    
    /**
     * Add IP address column
     */
    public function ipAddress(string $name = 'ip_address'): ColumnBuilder
    {
        return $this->string($name, 45)->nullable(); // IPv6 max length
    }
    
    /**
     * Add user agent column
     */
    public function userAgent(string $name = 'user_agent'): ColumnBuilder
    {
        return $this->text($name)->nullable();
    }
    
    /**
     * Add currency amount column pair (amount and currency)
     */
    public function currencyAmount(string $amountColumn = 'amount', string $currencyColumn = 'currency'): void
    {
        $this->decimal($amountColumn, 15, 4)->notNull();
        $this->string($currencyColumn, 3)->default('USD'); // ISO 4217
        $this->index([$amountColumn, $currencyColumn], "idx_{$amountColumn}_{$currencyColumn}");
    }
    
    /**
     * Add percentage column
     */
    public function percentage(string $name): ColumnBuilder
    {
        return $this->decimal($name, 5, 2)->default(0);
    }
    
}