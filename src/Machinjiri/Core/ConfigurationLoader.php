<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Artisans\Helpers\DotEnv;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Loads and validates application/database configuration, and provides
 * memoised access to parsed .env variables.
 */
class ConfigurationLoader
{
    /**
     * Cache of parsed environment variables, keyed by base path.
     *
     * @var array<string, array<string,mixed>|null>
     */
    private static array $envCache = [];

    private string $configDir;

    public function __construct(string $configDir)
    {
        $this->configDir = rtrim($configDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    /**
     * @return array{app: array, database: array}
     * @throws MachinjiriException
     */
    public function load(?Container $container): array
    {
        $appConfig      = $this->configDir . 'app.php';
        $databaseConfig = $this->configDir . 'database.php';

        $this->validate($appConfig, $databaseConfig, $container);

        return [
            'app'      => $this->loadAppConfiguration($appConfig, $container),
            'database' => $this->loadDatabaseConfiguration($databaseConfig),
        ];
    }

    private function validate(string $appConfig, string $databaseConfig, ?Container $container): void
    {
        $envVars = self::loadEnv($container);

        if (! $this->isReadableFile($appConfig) && ! $envVars) {
            throw new MachinjiriException(
                'App configuration error. Due to empty or unreadable environment file or no app configuration script in config folder.',
                102
            );
        }

        if (! $this->isReadableFile($databaseConfig) && ! $envVars) {
            throw new MachinjiriException(
                'Database configuration error. Due to empty or unreadable environment file or no database configuration script in config folder.',
                103
            );
        }
    }

    private function loadAppConfiguration(string $configPath, ?Container $container): array
    {
        $config  = $this->isReadableFile($configPath) ? include $configPath : [];
        $envVars = self::loadEnv($container);

        if (! is_array($config) && $envVars) {
            return [
                'app_name'        => $envVars['APP_NAME']        ?? '',
                'app_version'     => $envVars['APP_VERSION']     ?? '',
                // FIX: prefer APP_KEY; fall back to legacy APP_DEBUG for BC.
                'app_key'         => $envVars['APP_KEY']         ?? $envVars['APP_DEBUG'] ?? '',
                'app_env'         => $envVars['APP_ENV']         ?? '',
                'app_url'         => $envVars['APP_URL']         ?? '',
                'app_maintenance' => $envVars['APP_MAINTENANCE'] ?? false,
            ];
        }

        return $config;
    }

    private function loadDatabaseConfiguration(string $configPath): array
    {
        $config = $this->isReadableFile($configPath) ? include $configPath : [];

        if (! is_array($config)) {
            throw new MachinjiriException(
                "Database Error: Configuration 'database.php' not found in config folder",
                104
            );
        }

        $driver = $config['default'] ?? null;
        if (empty($driver)) {
            throw new MachinjiriException('Database Error: Default driver not specified', 105);
        }

        if (empty($config['connections']) || count($config['connections']) === 0) {
            throw new MachinjiriException('Database Error: no connections defined in config', 106);
        }

        if (! isset($config['connections'][$driver])) {
            throw new MachinjiriException(
                "Database Error: The configuration for default driver [{$driver}] does not match any in connection configuration",
                107
            );
        }

        if (count($config['connections'][$driver]) === 0) {
            throw new MachinjiriException(
                "Database Error: The configuration for default driver [{$driver}] is not set.",
                108
            );
        }

        $connection = $config['connections'][$driver];

        if (! empty($config['pool'])) {
            $connection['pool'] = $config['pool'];
        }

        return $connection;
    }

    private function isReadableFile(string $path): bool
    {
        return is_file($path) && is_readable($path);
    }

    // -----------------------------------------------------------------
    // Environment (memoised)
    // -----------------------------------------------------------------

    /**
     * Load .env variables for a container or a raw path.
     *
     * Results are cached per base path so repeated calls in the same
     * request hit the cache instead of re-parsing the file.
     *
     * @return array<string,mixed>|null  Null when no variables were parsed.
     */
    public static function loadEnv(?Container $container, ?string $path = null): ?array
    {
        $key = $path ?? Container::$appBasePath ?? '__default__';

        if (array_key_exists($key, self::$envCache)) {
            return self::$envCache[$key];
        }

        $dotEnv = $path === null
            ? new DotEnv($container, true)
            : new DotEnv(null, true, $path);

        $dotEnv->load();
        $variables = $dotEnv->getVariables();

        return self::$envCache[$key] = (count($variables) > 0 ? $variables : null);
    }

    /** @return array<string,mixed> */
    public static function loadEnvArray(?Container $container, ?string $path = null): array
    {
        return self::loadEnv($container, $path) ?? [];
    }

    public static function clearEnvCache(): void
    {
        self::$envCache = [];
    }
}