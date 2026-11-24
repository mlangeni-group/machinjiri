<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Database\Utility;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Database\Config\DatabaseConfiguration;
use Mlangeni\Machinjiri\Core\FileSystem\FileSystemManager;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupManager
{
    public function __construct(
        private readonly DatabaseConfiguration $configuration,
        private readonly string $backupDirectory,
        private readonly ?FileSystemManager $fileSystems = null,
        private readonly ?string $diskName = null
    ) {
        if ($backupDirectory === '' && $fileSystems === null) {
            throw new RuntimeException('Database backup directory cannot be empty.');
        }
    }

    public static function fromContainer(Container $app): self
    {
        $backupConfig = $app->configurations['database_backup'] ?? [];
        if (!is_array($backupConfig)) {
            throw new RuntimeException('database_backup configuration must be an array.');
        }

        $directory = $backupConfig['directory'] ?? '';
        $diskName = $backupConfig['disk'] ?? null;
        if (!is_string($directory) || ($diskName !== null && !is_string($diskName))) {
            throw new RuntimeException('Database backup directory and disk configuration must be strings.');
        }

        $filesystemConfig = $app->configurations['filesystem'] ?? [];
        if (!is_array($filesystemConfig)) {
            throw new RuntimeException('filesystem configuration must be an array.');
        }
        $fileSystems = $app->bound(FileSystemManager::class)
            ? $app->resolve(FileSystemManager::class)
            : new FileSystemManager($filesystemConfig);
        $configuration = $app->resolve(DatabaseConfiguration::class);
        if (!$fileSystems instanceof FileSystemManager || !$configuration instanceof DatabaseConfiguration) {
            throw new RuntimeException('Database backup services are not configured correctly.');
        }

        return new self(
            $configuration,
            $directory,
            $fileSystems,
            $diskName
        );
    }

    public function backup(?string $path = null, bool $overwrite = false): string
    {
        if ($path === '') {
            throw new RuntimeException('Backup destination cannot be empty.');
        }

        if ($this->fileSystems !== null) {
            return $this->backupToFileSystem($path, $overwrite);
        }

        return $this->backupLocally($path, $overwrite);
    }

    private function backupLocally(?string $path, bool $overwrite): string
    {
        $driver = $this->configuration->getDriver();
        $destination = $path ?? $this->defaultBackupPath($driver);
        $this->prepareDestination($destination, $overwrite);

        $temporaryPath = $destination . '.tmp.' . bin2hex(random_bytes(8));
        $previousUmask = umask(0077);

        try {
            match ($driver) {
                'mysql' => $this->backupMySql($temporaryPath),
                'pgsql' => $this->backupPostgres($temporaryPath),
                'sqlite' => $this->backupSqlite($temporaryPath),
                'mongodb' => $this->backupMongo($temporaryPath),
                default => throw new RuntimeException("Database backup is not supported for driver '{$driver}'."),
            };

            if (!is_file($temporaryPath) || filesize($temporaryPath) === 0) {
                throw new RuntimeException('The database backup command did not produce an output file.');
            }

            if (!chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Unable to secure the temporary database backup file.');
            }
            if (!rename($temporaryPath, $destination)) {
                throw new RuntimeException("Unable to move the backup to '{$destination}'.");
            }

            return $destination;
        } finally {
            umask($previousUmask);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function restore(string $path): void
    {
        if ($path === '') {
            throw new RuntimeException('Backup path cannot be empty.');
        }

        if ($this->fileSystems !== null) {
            $this->restoreFromFileSystem($path);
            return;
        }

        $this->restoreFromFile($path);
    }

    private function restoreFromFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Backup file '{$path}' does not exist or is not readable.");
        }

        $driver = $this->configuration->getDriver();
        $connection = $this->configuration->getConnCfg();

        match ($driver) {
            'mysql' => $this->restoreMySql($path, $connection),
            'pgsql' => $this->restorePostgres($path, $connection),
            'sqlite' => $this->restoreSqlite($path, $connection),
            'mongodb' => $this->restoreMongo($path),
            default => throw new RuntimeException("Database restore is not supported for driver '{$driver}'."),
        };
    }

    public function defaultBackupPath(?string $driver = null): string
    {
        $driver ??= $this->configuration->getDriver();
        $extension = match ($driver) {
            'mysql', 'pgsql' => 'sql',
            'sqlite' => 'sqlite',
            'mongodb' => 'archive.gz',
            default => throw new RuntimeException("Database backup is not supported for driver '{$driver}'."),
        };

        $filename = sprintf(
            'database-%s-%s.%s',
            $driver,
            date('Ymd-His'),
            bin2hex(random_bytes(3))
        ) . '.' . $extension;

        if ($this->fileSystems !== null) {
            $directory = trim(str_replace('\\', '/', $this->backupDirectory), '/');
            return $directory === '' ? $filename : $directory . '/' . $filename;
        }

        return rtrim($this->backupDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    }

    private function backupToFileSystem(?string $path, bool $overwrite): string
    {
        $driver = $this->configuration->getDriver();
        $destination = $this->normalizeDiskPath($path ?? $this->defaultBackupPath($driver));
        $disk = $this->fileSystems->disk($this->diskName);
        $destinationExists = $disk->exists($destination);
        if ($destinationExists && !$overwrite) {
            throw new RuntimeException("Backup file '{$destination}' already exists; pass overwrite to replace it.");
        }

        $temporaryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'machinjiri-db-backup-' . bin2hex(random_bytes(12));
        $temporaryRemotePath = $destination . '.upload-' . bin2hex(random_bytes(8));
        $previousRemotePath = null;
        $previousUmask = umask(0077);

        try {
            $this->writeDatabaseDump($driver, $temporaryPath);
            if (!is_file($temporaryPath) || filesize($temporaryPath) === 0) {
                throw new RuntimeException('The database backup command did not produce an output file.');
            }

            $localSize = filesize($temporaryPath);
            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the temporary database backup.');
            }
            try {
                if (!$disk->writeStream($temporaryRemotePath, $stream)) {
                    throw new RuntimeException("Unable to upload backup file '{$destination}'.");
                }
            } finally {
                fclose($stream);
            }

            if (!$disk->exists($temporaryRemotePath) || $disk->size($temporaryRemotePath) !== $localSize) {
                throw new RuntimeException('Uploaded database backup size does not match the local dump.');
            }

            if ($destinationExists) {
                $previousRemotePath = $destination . '.previous-' . bin2hex(random_bytes(8));
                if (!$disk->move($destination, $previousRemotePath)) {
                    throw new RuntimeException("Unable to stage existing backup '{$destination}'.");
                }
            }

            try {
                if (!$disk->move($temporaryRemotePath, $destination)) {
                    throw new RuntimeException("Unable to finalize backup file '{$destination}'.");
                }
            } catch (Throwable $e) {
                if ($previousRemotePath !== null && $disk->exists($previousRemotePath)) {
                    if (!$disk->move($previousRemotePath, $destination)) {
                        throw new RuntimeException(
                            "Backup upload failed and the previous backup could not be restored from '{$previousRemotePath}'.",
                            0,
                            $e
                        );
                    }
                }
                throw $e;
            }

            if ($previousRemotePath !== null) {
                $disk->delete($previousRemotePath);
            }

            return $destination;
        } finally {
            umask($previousUmask);
            if ($disk->exists($temporaryRemotePath)) {
                $disk->delete($temporaryRemotePath);
            }
            if (is_file($temporaryPath) && !unlink($temporaryPath)) {
                throw new RuntimeException('Unable to remove the temporary database backup.');
            }
        }
    }

    private function restoreFromFileSystem(string $path): void
    {
        $path = $this->normalizeDiskPath($path);
        $stream = $this->fileSystems->disk($this->diskName)->readStream($path);
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to read backup file '{$path}' from the configured filesystem.");
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'machinjiri-db-restore-');
        if ($temporaryPath === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create a temporary restore file.');
        }

        try {
            $destination = fopen($temporaryPath, 'wb');
            if ($destination === false) {
                throw new RuntimeException('Unable to open the temporary restore file.');
            }
            try {
                $bytes = stream_copy_to_stream($stream, $destination);
                if ($bytes === false || $bytes === 0 || !fflush($destination)) {
                    throw new RuntimeException('Unable to stage the backup from the configured filesystem.');
                }
            } finally {
                fclose($destination);
            }
            if (!chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Unable to secure the temporary restore file.');
            }
            $this->restoreFromFile($temporaryPath);
        } finally {
            fclose($stream);
            if (is_file($temporaryPath) && !unlink($temporaryPath)) {
                throw new RuntimeException('Unable to remove the temporary restore file.');
            }
        }
    }

    private function writeDatabaseDump(string $driver, string $destination): void
    {
        match ($driver) {
            'mysql' => $this->backupMySql($destination),
            'pgsql' => $this->backupPostgres($destination),
            'sqlite' => $this->backupSqlite($destination),
            'mongodb' => $this->backupMongo($destination),
            default => throw new RuntimeException("Database backup is not supported for driver '{$driver}'."),
        };
    }

    private function normalizeDiskPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_ends_with($path, '/')
            || preg_match('~(^|/)\.\.?(/|$)~', $path)
        ) {
            throw new RuntimeException('Backup paths on a filesystem disk must be non-empty relative paths without traversal segments.');
        }

        return $path;
    }

    private function prepareDestination(string $destination, bool $overwrite): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create backup directory '{$directory}'.");
        }

        if (file_exists($destination) && !$overwrite) {
            throw new RuntimeException("Backup file '{$destination}' already exists; pass overwrite to replace it.");
        }
    }

    private function backupMySql(string $destination): void
    {
        $connection = $this->configuration->getConnCfg();
        $command = [
            'mysqldump',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($connection['port'] ?? 3306),
            '--user=' . ($connection['username'] ?? ''),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--databases',
            $connection['database'] ?? throw new RuntimeException('MySQL database name is not configured.'),
        ];

        $this->runToFile($command, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], $destination);
    }

    private function restoreMySql(string $path, array $connection): void
    {
        $command = [
            'mysql',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($connection['port'] ?? 3306),
            '--user=' . ($connection['username'] ?? ''),
            $connection['database'] ?? throw new RuntimeException('MySQL database name is not configured.'),
        ];

        $this->runWithInput($command, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], $path);
    }

    private function backupPostgres(string $destination): void
    {
        $connection = $this->configuration->getConnCfg();
        $command = [
            'pg_dump',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($connection['port'] ?? 5432),
            '--username=' . ($connection['username'] ?? ''),
            '--no-password',
            '--dbname=' . ($connection['database'] ?? throw new RuntimeException('PostgreSQL database name is not configured.')),
        ];

        $this->runToFile($command, ['PGPASSWORD' => (string) ($connection['password'] ?? '')], $destination);
    }

    private function restorePostgres(string $path, array $connection): void
    {
        $command = [
            'psql',
            '--no-psqlrc',
            '--set=ON_ERROR_STOP=1',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($connection['port'] ?? 5432),
            '--username=' . ($connection['username'] ?? ''),
            '--no-password',
            '--dbname=' . ($connection['database'] ?? throw new RuntimeException('PostgreSQL database name is not configured.')),
        ];

        $this->runWithInput($command, ['PGPASSWORD' => (string) ($connection['password'] ?? '')], $path);
    }

    private function backupSqlite(string $destination): void
    {
        $database = $this->configuration->getConnCfg()['database'] ?? null;
        if (!is_string($database) || $database === '' || $database === ':memory:' || !is_file($database)) {
            throw new RuntimeException('SQLite backups require an existing file-backed database.');
        }

        $connection = new PDO('sqlite:' . $database);
        $connection->exec('VACUUM INTO ' . $connection->quote($destination));
    }

    private function restoreSqlite(string $path, array $connection): void
    {
        $database = $connection['database'] ?? null;
        if (!is_string($database) || $database === '' || $database === ':memory:') {
            throw new RuntimeException('SQLite restore requires a file-backed database.');
        }

        $backup = new PDO('sqlite:' . $path);
        $integrity = $backup->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new RuntimeException("SQLite backup failed integrity check: {$integrity}");
        }

        $directory = dirname($database);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create SQLite database directory '{$directory}'.");
        }

        $temporaryPath = $database . '.restore.' . bin2hex(random_bytes(8));
        try {
            if (!copy($path, $temporaryPath)) {
                throw new RuntimeException("Unable to stage SQLite backup '{$path}'.");
            }
            if (!chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Unable to secure the staged SQLite database.');
            }
            if (!rename($temporaryPath, $database)) {
                throw new RuntimeException("Unable to replace SQLite database '{$database}'.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function backupMongo(string $destination): void
    {
        $configFile = $this->createMongoConfig();
        try {
            $this->runProcess(['mongodump', '--config=' . $configFile, '--archive=' . $destination, '--gzip', '--quiet']);
        } finally {
            unlink($configFile);
        }
    }

    private function restoreMongo(string $path): void
    {
        $configFile = $this->createMongoConfig();
        try {
            $this->runProcess([
                'mongorestore',
                '--config=' . $configFile,
                '--archive=' . $path,
                '--gzip',
                '--drop',
                '--stopOnError',
                '--quiet',
            ]);
        } finally {
            unlink($configFile);
        }
    }

    private function createMongoConfig(): string
    {
        $connection = $this->configuration->getConnCfg();
        $uri = $connection['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            $host = $connection['host'] ?? 'localhost';
            $port = $connection['port'] ?? 27017;
            $database = rawurlencode((string) ($connection['database'] ?? ''));
            $credentials = '';
            if (!empty($connection['username'])) {
                $credentials = rawurlencode((string) $connection['username'])
                    . ':' . rawurlencode((string) ($connection['password'] ?? '')) . '@';
            }
            $uri = "mongodb://{$credentials}{$host}:{$port}/{$database}";

            $authSource = $connection['options']['authSource'] ?? null;
            if (is_string($authSource) && $authSource !== '') {
                $uri .= '?authSource=' . rawurlencode($authSource);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'machinjiri-mongo-');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary MongoDB tool configuration.');
        }
        try {
            $encodedUri = json_encode($uri, JSON_UNESCAPED_SLASHES);
            if ($encodedUri === false || file_put_contents($path, 'uri: ' . $encodedUri . PHP_EOL) === false) {
                throw new RuntimeException('Unable to write temporary MongoDB tool configuration.');
            }
            if (!chmod($path, 0600)) {
                throw new RuntimeException('Unable to secure temporary MongoDB tool configuration.');
            }
        } catch (\Throwable $e) {
            unlink($path);
            throw $e;
        }

        return $path;
    }

    private function runToFile(array $command, array $environment, string $destination): void
    {
        $handle = fopen($destination, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create temporary backup file '{$destination}'.");
        }

        try {
            $this->runProcess($command, $environment, static function (string $type, string $buffer) use ($handle): void {
                if ($type !== Process::OUT) {
                    return;
                }

                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($buffer, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write database backup output.');
                    }
                    $offset += $written;
                }
            });
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush database backup output.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function runWithInput(array $command, array $environment, string $path): void
    {
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException("Unable to open backup file '{$path}'.");
        }

        try {
            $this->runProcess($command, $environment, null, $input);
        } finally {
            fclose($input);
        }
    }

    private function runProcess(
        array $command,
        array $environment = [],
        ?callable $outputHandler = null,
        mixed $input = null
    ): void {
        $process = new Process($command, null, $environment);
        $process->setTimeout(null);
        if ($input !== null) {
            $process->setInput($input);
        }
        $process->run($outputHandler);

        if (!$process->isSuccessful()) {
            $error = $this->redactSecrets(trim($process->getErrorOutput()));
            throw new RuntimeException(sprintf(
                '%s failed with exit code %d%s',
                $command[0],
                $process->getExitCode() ?? -1,
                $error === '' ? '.' : ': ' . $error
            ));
        }
    }

    private function redactSecrets(string $error): string
    {
        $connection = $this->configuration->getConnCfg();
        $secrets = [
            $connection['password'] ?? null,
            $connection['uri'] ?? null,
        ];

        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $error = str_replace($secret, '[redacted]', $error);
                $error = str_replace(rawurlencode($secret), '[redacted]', $error);
            }
        }

        return $error;
    }
}
