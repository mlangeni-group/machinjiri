<?php

namespace Mlangeni\Machinjiri\Core\Database\Factory;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\DatabaseException;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;

use ReflectionClass;
use Faker\Factory as FakerFactory;
use Faker\Generator;

class FactoryManager
{
    protected Container $container;
    protected string $factoriesPath;
    protected Generator $faker;
    protected array $createdFactories = [];
    
    public bool $created = false;
    
    public function __construct(Container $container)
    {
        $this->container = $container;
        
        $this->factoriesPath = $container->getRootPath() . 'database/factories/';
        
        // Initialize Faker
        if (!class_exists(FakerFactory::class)) {
            throw DatabaseException::FactoryError(
                "FakerPHP library is required. Install with: composer require fakerphp/faker");
        }
        
        $this->faker = FakerFactory::create();
    }
    
    /**
     * Create a new factory file with Mlangeni\Machinjiri\Database namespace
     */
    public function make(string $model, bool $overwrite = false): string
    {
        $filename = $this->getFactoryFileName($model);
        $dir = $this->factoriesPath;
        
        $fullPath = $dir . $filename;
        
        // Check if file already exists
        if (file_exists($fullPath) && !$overwrite) {
            throw DatabaseException::FactoryError("Factory file '{$filename}' already exists. Use --force to overwrite.");
        }
        
        $className = $this->getClassName($model);
        $tableName = $this->getTableName($model);
        $namespace = $this->getFactoryNamespace();
        
        $stub = $this->getStubContent('factory');
        
        $content = str_replace(
            ['{{Namespace}}', '{{ClassName}}', '{{TableName}}', '{{Columns}}'],
            [
                $namespace,
                $className, 
                $tableName,
                $this->generateColumns($model, $tableName)
            ],
            $stub
        );
        
        if (file_put_contents($fullPath, $content) === false) {
            throw DatabaseException::FactoryError("Failed to create factory file: {$fullPath}");
        }
        $this->created = true;
        return $fullPath;
    }
    
    /**
     * Run a specific factory to create records
     */
    public function run(string $model, int $count = 1, array $attributes = []): array
    {
        $factoryInstance = $this->getFactoryClass($model);
        
        $records = [];
        $startTime = microtime(true);
        
        for ($i = 0; $i < $count; $i++) {
            $record = $factoryInstance->definition();
            $record = array_merge($record, $attributes);
            
            // Insert into database
            $tableName = $factoryInstance->getTableName() ?? $this->getTableName($model);
            
            $query = new QueryBuilder($tableName);
            $result = $query->insert($record)->execute();
            
            $record['id'] = $result['lastInsertId'] ?? null;
            $records[] = $record;
        }
        
        $endTime = microtime(true);
        
        $this->createdFactories[] = [
            'model' => $model,
            'count' => $count,
            'time' => round($endTime - $startTime, 4)
        ];
        
        return [
            'model' => $model,
            'count' => $count,
            'records' => $records,
            'status' => 'success',
            'time' => round($endTime - $startTime, 4)
        ];
    }
    
    /**
     * Run factories for multiple models
     */
    public function runMultiple(array $definitions): array
    {
        $results = [];
        
        foreach ($definitions as $model => $config) {
            $count = is_array($config) ? ($config['count'] ?? 1) : $config;
            $attributes = is_array($config) ? ($config['attributes'] ?? []) : [];
            
            try {
                $results[$model] = $this->run($model, $count, $attributes);
            } catch (MachinjiriException $e) {
                $results[$model] = [
                    'model' => $model,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Create database schema from factory definitions
     */
    public function migrate(array $models = []): array
    {
        if (empty($models)) {
            $models = $this->getAllModels();
        }
        
        $results = [];
        
        foreach ($models as $model) {
            try {
                $factoryInstance = $this->getFactoryClass($model);
                
                if (method_exists($factoryInstance, 'migration')) {
                    $migration = $factoryInstance->migration();
                    
                    if (is_array($migration)) {
                        $tableName = $factoryInstance->getTableName() ?? $this->getTableName($model);
                        
                        $query = new QueryBuilder('');
                        $sql = $query->createTable($tableName, $migration)->compileCreateTable();
                        
                        DatabaseConnection::executeQuery($sql);
                        
                        $results[$model] = [
                            'status' => 'created',
                            'table' => $tableName,
                            'columns' => count($migration)
                        ];
                    }
                }
            } catch (\Exception $e) {
                $results[$model] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * List all available factories
     */
    public function list(): array
    {
        $files = glob($this->factoriesPath . '*.php');
        $factories = [];
        
        foreach ($files as $file) {
            $filename = basename($file, '.php');
            $model = $this->getModelFromFileName($filename);
            
            $factories[] = [
                'file' => $filename,
                'model' => $model,
                'path' => $file,
                'class' => $this->getClassName($model),
                'full_class' => $this->getFactoryNamespace() . $this->getClassName($model)
            ];
        }
        
        return $factories;
    }

    private function getFactoryClass(string $model): ?object 
    {
        $filename = $this->getFactoryFileName($model);
        $fullPath = $this->factoriesPath . $filename;
        
        if (!file_exists($fullPath)) {
            throw DatabaseException::FactoryError("Factory file not found: {$filename}");
        }
        
        require_once $fullPath;
        
        $className = $this->getClassName($model);
        $namespace = $this->getFactoryNamespace();
        $fullClassName = $namespace . '\\' . $className;
        
        if (!class_exists($fullClassName) ) {
            throw DatabaseException::FactoryError("Factory class '{$namespace}\\{$className}' not found in file.");
        }

        $factory = new $fullClassName($this->faker);

        if (!method_exists($factory, 'definition')) {
            throw DatabaseException::FactoryError("Method definition in '{$namespace}\\{$className}' not found.");
        }

        return $factory;
    }
    
    /**
     * Generate fake data without saving to database
     */
    public function fake(string $model, int $count = 1, array $attributes = []): array
    {
        $factoryInstance = $this->getFactoryClass($model);
        
        $records = [];
        
        for ($i = 0; $i < $count; $i++) {
            $record = $factoryInstance->definition();
            $record = array_merge($record, $attributes);
            $records[] = $record;
        }
        
        return $records;
    }
    
    /**
     * Get stub content for factory with Mlangeni\Machinjiri\Database namespace
     */
    protected function getStubContent(string $type): string
    {
        $stubPath = __DIR__ . '/stubs/' . $type . '.stub';
        
        if (file_exists($stubPath)) {
            $content = file_get_contents($stubPath);
            // Replace namespace placeholder with correct namespace
            $content = str_replace('{{Namespace}}', $this->getFactoryNamespace(), $content);
            return $content;
        }
        
        // Default stub content with Mlangeni\Machinjiri\Database namespace
        return <<<'EOD'
<?php

namespace {{Namespace}};

use Faker\Generator;
use Mlangeni\Machinjiri\Core\Database\Contracts\FactoryInterface;

class {{ClassName}} implements FactoryInterface
{
    protected Generator $faker;
    protected string $table = '{{TableName}}';
    
    public function __construct(Generator $faker)
    {
        $this->faker = $faker;
    }
    
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
{{Columns}}
        ];
    }
    
    /**
     * Get the table name for this factory.
     */
    public function getTableName(): string
    {
        return $this->table;
    }
    
    /**
     * Optional: Define database migration for this model.
     */
    public function migration(): array
    {
        return [
            // Example column definitions:
            // 'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            // 'name' => 'VARCHAR(255) NOT NULL',
            // 'email' => 'VARCHAR(255) UNIQUE',
            // 'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ];
    }
    
    /**
     * Optional: Define states for the model.
     */
    public function states(): array
    {
        return [
            // 'admin' => function() {
            //     return ['role' => 'admin'];
            // },
            // 'active' => function() {
            //     return ['active' => true];
            // },
        ];
    }
}
EOD;
    }
    
    /**
     * Generate column definitions based on common patterns
     */
    protected function generateColumns(string $model, string $tableName): string
    {
        $columns = [];
        
        // Common column patterns based on model/table name
        $commonColumns = [
            'name' => "\$this->faker->name,",
            'email' => "\$this->faker->unique()->safeEmail,",
            'title' => "\$this->faker->sentence(3),",
            'content' => "\$this->faker->paragraphs(3, true),",
            'description' => "\$this->faker->text(200),",
            'price' => "\$this->faker->randomFloat(2, 1, 1000),",
            'quantity' => "\$this->faker->numberBetween(1, 100),",
            'status' => "\$this->faker->randomElement(['active', 'inactive', 'pending']),",
            'phone' => "\$this->faker->phoneNumber,",
            'address' => "\$this->faker->address,",
            'city' => "\$this->faker->city,",
            'country' => "\$this->faker->country,",
            'zip_code' => "\$this->faker->postcode,",
            'url' => "\$this->faker->url,",
            'image' => "\$this->faker->imageUrl(),",
            'password' => "'" . password_hash('password', PASSWORD_DEFAULT) . "',",
            'remember_token' => "bin2hex(random_bytes(10)),",
            'created_at' => "\$this->faker->dateTimeThisYear()->format('Y-m-d H:i:s'),",
            'updated_at' => "\$this->faker->dateTimeThisYear()->format('Y-m-d H:i:s'),",
        ];
        
        // Determine which columns to include based on table name
        $includeColumns = ['created_at', 'updated_at'];
        
        if (str_contains($tableName, 'user')) {
            $includeColumns = array_merge($includeColumns, ['name', 'email', 'password']);
        } elseif (str_contains($tableName, 'product')) {
            $includeColumns = array_merge($includeColumns, ['name', 'description', 'price', 'quantity']);
        } elseif (str_contains($tableName, 'post') || str_contains($tableName, 'article')) {
            $includeColumns = array_merge($includeColumns, ['title', 'content']);
        } elseif (str_contains($tableName, 'category')) {
            $includeColumns = array_merge($includeColumns, ['name', 'description']);
        }
        
        // Add the columns
        foreach ($includeColumns as $column) {
            if (isset($commonColumns[$column])) {
                $columns[] = "            '{$column}' => {$commonColumns[$column]}";
            }
        }
        
        // Add ID if it's not auto-incrementing
        $columns[] = "            // 'id' => \$this->faker->numberBetween(1, 1000),";
        
        return implode("\n", $columns);
    }
    
    /**
     * Helper methods
     */
    protected function getFactoryFileName(string $model): string
    {
        return $this->getTableName($model) . '_factory.php';
    }
    
    protected function getClassName(string $model): string
    {
        return ucfirst($this->camelCase($model)) . 'Factory';
    }
    
    protected function getModelFromFileName(string $filename): string
    {
        $name = str_replace('_factory', '', $filename);
        return ucfirst($this->camelCase($name));
    }
    
    protected function getTableName(string $model): string
    {
        return $this->snakeCase($model);
    }
    
    /**
     * Get factory namespace: Mlangeni\Machinjiri\Database\Factories
     */
    protected function getFactoryNamespace(): string
    {
        return 'Mlangeni\Machinjiri\Database\Factories';
    }
    
    protected function getAllModels(): array
    {
        $files = glob($this->factoriesPath . '*.php');
        $models = [];
        
        foreach ($files as $file) {
            $filename = basename($file, '.php');
            $model = $this->getModelFromFileName($filename);
            $models[] = $model;
        }
        
        return $models;
    }
    
    /**
     * String helper methods
     */
    protected function camelCase(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        $value = str_replace(' ', '', $value);
        return lcfirst($value);
    }
    
    protected function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<=\\w)(?=[A-Z])/', '_$1', $value);
        return strtolower($value);
    }
    
    /**
     * Get statistics about created factories
     */
    public function getStatistics(): array
    {
        return [
            'total_created' => count($this->createdFactories),
            'factories' => $this->createdFactories,
            'path' => $this->factoriesPath,
            'namespace' => $this->getFactoryNamespace(),
            'exists' => is_dir($this->factoriesPath)
        ];
    }
    
    /**
     * Get Faker instance
     */
    public function getFaker(): Generator
    {
        return $this->faker;
    }
    
    /**
     * Create multiple factory files at once
     */
    public function makeMultiple(array $models, bool $overwrite = false): array
    {
        $results = [];
        
        foreach ($models as $model) {
            try {
                $path = $this->make($model, $overwrite);
                $results[$model] = [
                    'status' => 'created',
                    'path' => $path,
                    'class' => $this->getClassName($model),
                    'namespace' => $this->getFactoryNamespace()
                ];
            } catch (MachinjiriException $e) {
                $results[$model] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Register factory namespace with autoloader
     */
    public function registerAutoload(): void
    {
        $namespace = rtrim($this->getFactoryNamespace(), '\\');
        $path = $this->factoriesPath;
        
        spl_autoload_register(function ($class) use ($namespace, $path) {
            // Check if the class is in our namespace
            if (strpos($class, $namespace) === 0) {
                // Remove namespace prefix
                $relativeClass = substr($class, strlen($namespace));
                
                // Convert namespace separators to directory separators
                $relativePath = str_replace('\\', '/', $relativeClass);
                
                // Build the full path
                $file = $path . $relativePath . '.php';
                
                // If the file exists, require it
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });
    }
}