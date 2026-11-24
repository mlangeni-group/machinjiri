<?php

namespace Mlangeni\Machinjiri\Core\Database\Grammars;

class MySqlGrammar extends Grammar
{
    public function compileAutoIncrement(): string
    {
        return 'AUTO_INCREMENT';
    }

    public function compileColumnType(string $type, array $parameters = []): string
    {
        $type = strtoupper($type);
        
        return match ($type) {
            'INTEGER' => $this->compileIntegerType($parameters),
            'INT' => $this->compileIntegerType($parameters),
            'TINYINT' => $this->compileTinyIntType($parameters),
            'STRING' => $this->compileStringType($parameters),
            'TEXT' => 'TEXT',
            'FLOAT' => $this->compileFloatType($parameters),
            'DECIMAL' => $this->compileDecimalType($parameters),
            'DATE' => 'DATE',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'TIMESTAMP',
            default => throw new \InvalidArgumentException("Unsupported MySQL column type: {$type}")
        };
    }

    public function wrapTable(string $table): string
    {
        return $this->wrapIdentifier($this->tablePrefix . $table);
    }

    public function wrapColumn(string $column): string
    {
        return $this->wrapIdentifier($column);
    }

    protected function compileIntegerType(array $parameters): string
    {
        $length = $this->validateLength($parameters[0] ?? 11, 'integer');
        return $length === 11 ? 'INTEGER' : "INT({$length})";
    }

    protected function compileTinyIntType(array $parameters): string
    {
        $length = $this->validateLength($parameters[0] ?? 1, 'tiny integer');
        return "TINYINT({$length})";
    }

    protected function compileStringType(array $parameters): string
    {
        $length = $this->validateLength($parameters[0] ?? 255, 'string');
        return "VARCHAR({$length})";
    }

    protected function compileFloatType(array $parameters): string
    {
        if (!empty($parameters)) {
            $values = $this->validatePrecisionScale($parameters);
            return 'FLOAT(' . implode(',', $values) . ')';
        }
        return 'FLOAT';
    }

    protected function compileDecimalType(array $parameters): string
    {
        if (!empty($parameters)) {
            $values = $this->validatePrecisionScale($parameters);
            return 'DECIMAL(' . implode(',', $values) . ')';
        }
        return 'DECIMAL';
    }

    private function wrapIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new \InvalidArgumentException('MySQL identifiers cannot be empty');
        }

        $segments = explode('.', $identifier);
        $wrapped = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                throw new \InvalidArgumentException('Invalid MySQL identifier');
            }
            $wrapped[] = '`' . str_replace('`', '``', $segment) . '`';
        }

        return implode('.', $wrapped);
    }

    private function validateLength(mixed $length, string $columnType): int
    {
        if (is_int($length)) {
            $length = $length;
        } elseif (is_string($length) && preg_match('/^[1-9][0-9]*$/', $length)) {
            $length = (int) $length;
        } else {
            throw new \InvalidArgumentException("Invalid {$columnType} length");
        }

        if ($length < 1 || $length > 65535) {
            throw new \InvalidArgumentException("Invalid {$columnType} length");
        }

        return $length;
    }

    private function validatePrecisionScale(array $parameters): array
    {
        if (count($parameters) === 1 && is_string($parameters[0]) && str_contains($parameters[0], ',')) {
            $parameters = explode(',', $parameters[0]);
        }

        if (count($parameters) !== 2) {
            throw new \InvalidArgumentException('MySQL FLOAT and DECIMAL types require precision and scale');
        }

        $precision = $this->validateUnsignedInteger($parameters[0], 'precision');
        $scale = $this->validateUnsignedInteger($parameters[1], 'scale');

        if ($scale > $precision || $precision > 65) {
            throw new \InvalidArgumentException('Invalid MySQL FLOAT or DECIMAL precision and scale');
        }

        return [$precision, $scale];
    }

    private function validateUnsignedInteger(mixed $value, string $name): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', trim($value))) {
            $value = (int) trim($value);
            return $value;
        }
        throw new \InvalidArgumentException("Invalid MySQL {$name}");
    }

    public function compileCreateTable(string $table, array $columns, array $options = []): string
    {
        $sql = parent::compileCreateTable($table, $columns);
        
        if (!empty($options)) {
            $optionStrings = [];
            foreach ($options as $key => $value) {
                $key = strtolower((string) $key);
                $optionStrings[] = match ($key) {
                    'engine' => 'ENGINE=' . $this->validateSimpleOptionValue($value, 'engine'),
                    'charset' => 'DEFAULT CHARSET=' . $this->validateSimpleOptionValue($value, 'charset'),
                    'collate' => 'DEFAULT COLLATE=' . $this->validateSimpleOptionValue($value, 'collate'),
                    'row_format' => 'ROW_FORMAT=' . $this->validateRowFormat($value),
                    'comment' => 'COMMENT=' . $this->quoteOptionString($value),
                    default => throw new \InvalidArgumentException("Unsupported MySQL table option: {$key}")
                };
            }
            $sql .= ' ' . implode(' ', $optionStrings);
        }
        
        return $sql;
    }

    private function validateSimpleOptionValue(mixed $value, string $option): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new \InvalidArgumentException("Invalid MySQL {$option} option");
        }
        return $value;
    }

    private function validateRowFormat(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid MySQL row format');
        }

        $value = strtoupper($value);
        if (!in_array($value, ['DEFAULT', 'DYNAMIC', 'FIXED', 'COMPRESSED', 'REDUNDANT', 'COMPACT'], true)) {
            throw new \InvalidArgumentException('Invalid MySQL row format');
        }
        return $value;
    }

    private function quoteOptionString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid MySQL table comment');
        }
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }
}