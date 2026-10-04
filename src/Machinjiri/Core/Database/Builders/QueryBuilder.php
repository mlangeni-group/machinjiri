<?php

namespace Mlangeni\Machinjiri\Core\Database\Builders;

use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Grammars\Grammar;
use Mlangeni\Machinjiri\Core\Database\Schema\Blueprint;

class QueryBuilder {
    protected string $table;
    protected array $columns = ['*'];
    protected array $where = [];
    protected array $joins = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $bindings = [];
    protected string $action = 'select';
    protected array $insertData = [];
    protected array $updateData = [];
    protected ?string $groupBy = null;
    protected string $createTable = '';
    protected array $createColumns = [];
    protected array $createOptions = [];
    protected string $alterTable = '';
    protected array $alterActions = [];

    protected Grammar $grammar;

    public function __construct(string $table = '')
    {
        $this->table = $table;
        $this->grammar = DatabaseConnection::getGrammar();
    }

    public static function builder(string $table): self 
    {
        return new self($table);
    }

    // SELECT operations
    public function select(array $columns = ['*']): self
    {
        $this->action = 'select';
        $this->columns = $columns;
        return $this;
    }

    public function distinct(): self
    {
        $this->columns[0] = 'DISTINCT ' . ($this->columns[0] === '*' ? '*' : implode(', ', $this->columns));
        return $this;
    }

    // JOIN operations
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = [
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'type' => $type
        ];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    // WHERE conditions
    public function where($column, $operator = null, $value = null, $boolean = 'AND'): self
    {
        if ($column instanceof \Closure) {
            $this->where[] = [
                'type'    => 'nested',
                'query'   => $column,
                'boolean' => $boolean
            ];
            return $this;
        }
    
        $this->where[] = [
            'type'     => 'basic',
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => $boolean
        ];
        $this->addBinding($value);
        return $this;
    }

    public function orWhere(string $column, string $operator, $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->where[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean
        ];
        $this->addBinding($values);
        return $this;
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->where[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean
        ];
        return $this;
    }

    // ORDER BY
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC'
        ];
        return $this;
    }

    // LIMIT and OFFSET
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    // GROUP BY
    public function groupBy(string $column): self
    {
        $this->groupBy = $column;
        return $this;
    }

    // INSERT operations
    public function insert(array $data): self
    {
        $this->action = 'insert';
        $this->insertData = $data;
        $this->addBinding(array_values($data));
        return $this;
    }

    // UPDATE operations
    public function update(array $data): self
    {
        $this->action = 'update';
        $this->updateData = $data;
        $this->addBinding(array_values($data));
        return $this;
    }

    // DELETE operations
    public function delete(): self
    {
        $this->action = 'delete';
        return $this;
    }

    // Binding management
    protected function addBinding($value): void
    {
        if (is_array($value)) {
            $this->bindings = array_merge($this->bindings, $value);
        } else {
            $this->bindings[] = $value;
        }
    }

    // Query execution
    public function get(): array
    {
        $sql = $this->compileSelect();
        return $this->execute($sql);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    public function execute(string $sql = ''): array
    {
        if (empty($sql)) {
            $sql = $this->compileQuery();
        }

        try {
            $stmt = DatabaseConnection::executeQuery($sql, $this->bindings);
            
            if ($this->action === 'select') {
                return $stmt->fetchAll();
            }
            
            return [
                'rowCount' => $stmt->rowCount(),
                'lastInsertId' => DatabaseConnection::getInstance()->lastInsertId()
            ];
        } finally {
            $this->reset();
        }
    }

    // Query compilation
    protected function compileQuery(): string
    {
        return match ($this->action) {
            'select' => $this->compileSelect(),
            'insert' => $this->compileInsert(),
            'update' => $this->compileUpdate(),
            'delete' => $this->compileDelete(),
            'create' => $this->compileCreateTable(),
            'alter' => $this->compileAlterTable(),
            'drop' => $this->compileDropTable(),
            default => throw new Exception('Invalid query action'),
        };
    }

    public function compileSelect(): string
    {
      
      $wrappedTable = $this->grammar->wrapTable($this->table);
        $wrappedColumns = array_map([$this->grammar, 'wrapColumn'], $this->columns);
        
        $sql = "SELECT " . implode(', ', $wrappedColumns) . " FROM {$wrappedTable}";
      

        // Compile joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            $sql .= " ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        // Compile WHERE conditions
        $sql .= $this->compileWheres();

        // Compile GROUP BY
        if ($this->groupBy) {
            $sql .= " GROUP BY {$this->groupBy}";
        }

        // Compile ORDER BY
        if (!empty($this->orderBy)) {
            $orderClauses = [];
            foreach ($this->orderBy as $order) {
                $orderClauses[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderClauses);
        }

        // Compile LIMIT and OFFSET
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    protected function compileWheres(): string
    {
        if (empty($this->where)) {
            return '';
        }
    
        $sql = '';
        $first = true;
    
        foreach ($this->where as $condition) {
            $boolean = $first ? 'WHERE' : $condition['boolean'];
            $first = false;
    
            switch ($condition['type']) {
                case 'basic':
                    $sql .= " {$boolean} {$condition['column']} {$condition['operator']} ?";
                    break;
    
                case 'in':
                    $placeholders = implode(', ', array_fill(0, count($condition['values']), '?'));
                    $sql .= " {$boolean} {$condition['column']} IN ({$placeholders})";
                    break;
    
                case 'null':
                    $sql .= " {$boolean} {$condition['column']} IS NULL";
                    break;
    
                case 'nested':
                    // Create a sub-builder to compile the nested conditions
                    $subBuilder = clone $this;
                    $subBuilder->where = [];      // reset, we'll fill with the nested ones
                    $subBuilder->bindings = [];   // important to avoid mixing bindings
    
                    // Call the closure, passing the sub-builder
                    call_user_func($condition['query'], $subBuilder);
    
                    // Compile the nested where string (without outer WHERE keyword)
                    $nestedSql = $subBuilder->compileWheres();
                    // Remove the leading 'WHERE' (if present)
                    $nestedSql = preg_replace('/^WHERE /', '', $nestedSql);
    
                    // Wrap in parentheses
                    $sql .= " {$boolean} ({$nestedSql})";
    
                    // Merge bindings from the nested builder
                    $this->bindings = array_merge($this->bindings, $subBuilder->bindings);
                    break;
            }
        }
    
        return $sql;
    }

    protected function compileInsert(): string
    {
        $columns = implode(', ', array_keys($this->insertData));
        $placeholders = implode(', ', array_fill(0, count($this->insertData), '?'));
        return "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
    }

    protected function compileUpdate(): string
    {
        $sql = "UPDATE {$this->table} SET ";
        $sets = [];
        
        foreach (array_keys($this->updateData) as $column) {
            $sets[] = "{$column} = ?";
        }
        
        $sql .= implode(', ', $sets);
        $sql .= $this->compileWheres();
        
        return $sql;
    }

    protected function compileDelete(): string
    {
        $sql = "DELETE FROM {$this->table}";
        $sql .= $this->compileWheres();
        return $sql;
    }

    // Reset builder state
    protected function reset(): void
    {
        $this->columns = ['*'];
        $this->where = [];
        $this->joins = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->bindings = [];
        $this->action = 'select';
        $this->insertData = [];
        $this->updateData = [];
        $this->groupBy = null;
        $this->createTable = '';
        $this->createColumns = [];
        $this->createOptions = [];
        $this->alterTable = '';
        $this->alterActions = [];
    }
    
    public function count(string $column = '*', bool $distinct = false): int
    {
        $expression = "COUNT(" . ($distinct ? "DISTINCT " : "") . "{$column})";
        return $this->getAggregate($expression);
    }

    /**
     * Get sum of column
     */
    public function sum(string $column)
    {
        return $this->getAggregate("SUM($column)");
    }

    /**
     * Get average value
     */
    public function avg(string $column)
    {
        return $this->getAggregate("AVG($column)");
    }

    /**
     * Get minimum value
     */
    public function min(string $column)
    {
        return $this->getAggregate("MIN($column)");
    }

    /**
     * Get maximum value
     */
    public function max(string $column)
    {
        return $this->getAggregate("MAX($column)");
    }

    /**
     * Execute aggregate query
     */
    protected function getAggregate(string $expression)
    {
        $clone = clone $this;
        $clone->groupBy = null;
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = null;
        
        $result = $clone->select(["{$expression} as aggregate"])->first();
        return $result['aggregate'] ?? null;
    }
    
    public function whereDateRange(string $column, string $start, string $end, string $boolean = 'AND'): self
    {
        return $this->where($column, '>=', $start, $boolean)
                   ->where($column, '<=', $end, 'AND');
    }

    /**
     * Add WHERE condition for current week
     */
    public function whereCurrentWeek(string $column, string $boolean = 'AND'): self
    {
        $start = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        return $this->whereDateRange($column, $start, $end, $boolean);
    }

    /**
     * Add WHERE condition for current month
     */
    public function whereCurrentMonth(string $column, string $boolean = 'AND'): self
    {
        $start = date('Y-m-01 00:00:00');
        $end = date('Y-m-t 23:59:59');
        return $this->whereDateRange($column, $start, $end, $boolean);
    }

    /**
     * Add WHERE condition for current year
     */
    public function whereCurrentYear(string $column, string $boolean = 'AND'): self
    {
        $start = date('Y-01-01 00:00:00');
        $end = date('Y-12-31 23:59:59');
        return $this->whereDateRange($column, $start, $end, $boolean);
    }
    
    // Column definition helpers
    public function id(): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("INTEGER");
      return new ColumnBuilder("id", $type, $this->grammar);
    }
    
    
    public function text(string $column): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("TEXT", [255]);
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function boolean(string $column): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("TINYINT", [1]);
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function float(string $column, int $precision = 8, int $scale = 2): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("FLOAT", ["$precision,$scale"]);
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function decimal(string $column, int $precision = 10, int $scale = 2): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("DECIMAL", ["$precision,$scale"]);
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function date(string $column): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("DATE");
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function dateTime(string $column): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("DATETIME");
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function timestamp(string $column): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("timestamp");
      return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    // Foreign key helper
    public function foreignId(string $column): ColumnBuilder
    {
      $type = $this->grammar->compileColumnType("INTEGER");
      return (new ColumnBuilder($column, $type, $this->grammar))->unsigned();
    }
    
    public function createTable(string $table, array $columns, array $options = []): self
    {
        $this->action = 'create';
        $this->createTable = $table;
        $this->createColumns = $columns;
        $this->createOptions = $options;
        return $this;
    }

    // ALTER TABLE operations
    public function alterTable(string $table): self
    {
        $this->action = 'alter';
        $this->alterTable = $table;
        return $this;
    }

    public function addColumn(string $column, string $definition): self
    {
        $this->alterActions[] = [
            'type' => 'add',
            'column' => $column,
            'definition' => $definition
        ];
        return $this;
    }

    public function dropColumn(string $column): self
    {
        $this->alterActions[] = [
            'type' => 'drop',
            'column' => $column
        ];
        return $this;
    }

    public function modifyColumn(string $column, string $definition): self
    {
        $this->alterActions[] = [
            'type' => 'modify',
            'column' => $column,
            'definition' => $definition
        ];
        return $this;
    }

    // DROP TABLE operation
    public function dropTable(string $table): self
    {
        $this->action = 'drop';
        $this->table = $table;
        return $this;
    }
    
    public function compileCreateTable(): string
    {
        $wrappedTable = $this->grammar->wrapTable($this->createTable);
        $columns = [];
        
        foreach ($this->createColumns as $name => $definition) {
            $wrappedName = $this->grammar->wrapColumn($name);
            $columns[] = "{$definition}";
        }
        
        
        return $this->grammar->compileCreateTable(
            $this->createTable,
            $columns,
            $this->createOptions
        );
        
        
    }

    protected function compileAlterTable(): string
    {
        $sql = "ALTER TABLE {$this->alterTable}";
        $actions = [];

        foreach ($this->alterActions as $action) {
            switch ($action['type']) {
                case 'add':
                    $actions[] = "ADD COLUMN {$action['column']} {$action['definition']}";
                    break;
                case 'drop':
                    $actions[] = "DROP COLUMN {$action['column']}";
                    break;
                case 'modify':
                    $actions[] = "MODIFY COLUMN {$action['column']} {$action['definition']}";
                    break;
            }
        }

        return $sql . ' ' . implode(', ', $actions);
    }

    protected function compileDropTable(): string
    {
        return "DROP TABLE {$this->table}";
    }
    
    public function integer(string $column): ColumnBuilder
    {
        $type = $this->grammar->compileColumnType('INT', [11]);
        return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function string(string $column, int $length = 255): ColumnBuilder
    {
        $type = $this->grammar->compileColumnType('string', [$length]);
        return new ColumnBuilder($column, $type, $this->grammar);
    }
    
    public function addIndex(string $indexName, array $columns, string $type = ''): self
    {
        $this->alterActions[] = [
            'type' => 'add_index',
            'name' => $indexName,
            'columns' => $columns,
            'index_type' => $type
        ];
        return $this;
    }
    
    public function dropIndex(string $indexName): self
    {
        $this->alterActions[] = [
            'type' => 'drop_index',
            'name' => $indexName
        ];
        return $this;
    }
    
    public function renameColumn(string $oldName, string $newName): self
    {
        $this->alterActions[] = [
            'type' => 'rename',
            'old' => $oldName,
            'new' => $newName
        ];
        return $this;
    }
    
    /**
     * Create table using Blueprint
     */
    public function createTableWithBlueprint(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, $this);
        call_user_func($callback, $blueprint);
        $blueprint->build();
    }
    
    /**
     * Alter table using Blueprint
     */
    public function alterTableWithBlueprint(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, $this);
        $blueprint->setAction('alter');
        call_user_func($callback, $blueprint);
        $blueprint->build();
    }
    
    /**
     * Drop table if exists
     */
    public function dropTableIfExists(string $table): self
    {
        $this->action = 'drop';
        $this->table = $table;
        // We'll need to override execute method to handle IF EXISTS
        return $this;
    }
    
    /**
     * Get grammar instance
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }
    
    public function getBindings(): array
    {
        return $this->bindings;
    }
    
}