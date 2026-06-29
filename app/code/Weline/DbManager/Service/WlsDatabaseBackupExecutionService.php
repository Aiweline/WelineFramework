<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseBackupExecutionService
{
    private const ACTION_BACKUP_DATABASE = 'backup_database';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const CONFIRMATION_PHRASE = 'RUN_DB_BACKUP';

    public function __construct(
        private readonly WlsDatabaseProfileService $profileService
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<string, mixed> $backupPlan
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     * @return array{success:bool,message:string,artifact_path:string,meta_path:string,bytes:int,checksum:string}
     */
    public function executeFromPanel(
        array $input,
        array $context,
        array $backupPlan,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $artifactPath = '';
        $metaPath = '';
        $temporaryArtifactPath = '';
        $targetConfig = [];
        $auditPayload = [
            'action' => (string)($backupPlan['action'] ?? ''),
            'driver' => (string)($backupPlan['driver'] ?? ''),
            'scope' => (string)($backupPlan['scope'] ?? ''),
            'artifact' => (string)($backupPlan['artifact'] ?? ''),
            'database' => (string)($backupPlan['database'] ?? ''),
            'profile' => $this->auditProfile($projectProfile),
        ];

        try {
            $this->assertExecutionGate($input, $backupPlan);

            $targetConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($targetConfig === null) {
                $targetConfig = [];
                throw new \InvalidArgumentException((string)__('Enable and save the Project Profile before backup execution.'));
            }

            $config = $this->normalizeConnectionConfig($targetConfig);
            $driver = (string)$config['type'];
            $planDriver = (string)($backupPlan['driver'] ?? '');
            $missing = $this->missingConnectionFields($config);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Project backup connection is incomplete: %{1}', [\implode(', ', $missing)]));
            }
            if (!$this->supportsBackupExecutionDriver($driver)) {
                throw new \InvalidArgumentException((string)__('Backup execution currently supports mysql and pgsql profiles.'));
            }
            if ($planDriver !== '' && $planDriver !== $driver) {
                throw new \InvalidArgumentException((string)__('Submitted backup driver does not match the enabled Project Profile.'));
            }
            if (!$this->isSafeIdentifier((string)$config['database'])) {
                throw new \InvalidArgumentException((string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.'));
            }

            $artifact = $this->resolveArtifactPath((string)($backupPlan['artifact'] ?? ''), $driver);
            $artifactPath = $artifact['path'];
            $compressedArtifact = $this->isCompressedSqlArtifact((string)$artifact['name']);
            $dumpPath = $artifactPath;
            if ($compressedArtifact) {
                $temporaryArtifactPath = $this->temporarySqlArtifactPath($artifactPath);
                $dumpPath = $temporaryArtifactPath;
            }
            $startedAt = \microtime(true);
            if ($driver === self::DRIVER_MYSQL) {
                $this->runMysqlDump($config, (string)($backupPlan['scope'] ?? 'schema_and_data'), $dumpPath);
            } else {
                $this->runPgDump($config, (string)($backupPlan['scope'] ?? 'schema_and_data'), $dumpPath);
            }
            if ($compressedArtifact) {
                $this->compressSqlArtifact($dumpPath, $artifactPath);
                if (\is_file($temporaryArtifactPath)) {
                    @\unlink($temporaryArtifactPath);
                }
            }
            $bytes = \filesize($artifactPath);
            if (!\is_int($bytes) || $bytes <= 0) {
                throw new \RuntimeException((string)__('Database backup artifact is empty.'));
            }
            $checksum = \hash_file('sha256', $artifactPath);
            if (!\is_string($checksum) || $checksum === '') {
                throw new \RuntimeException((string)__('Database backup checksum could not be calculated.'));
            }

            $meta = [
                'time' => \date('c'),
                'action' => self::ACTION_BACKUP_DATABASE,
                'driver' => $driver,
                'scope' => (string)($backupPlan['scope'] ?? 'schema_and_data'),
                'artifact' => (string)$artifact['name'],
                'artifact_path' => $artifactPath,
                'bytes' => $bytes,
                'sha256' => $checksum,
                'database' => (string)$config['database'],
                'host' => (string)$config['hostname'],
                'port' => (string)$config['hostport'],
                'username' => $this->maskValue((string)$config['username']),
                'compression' => $compressedArtifact ? 'gzip' : 'none',
                'duration_ms' => (int)\round((\microtime(true) - $startedAt) * 1000),
            ];
            $metaPath = $artifactPath . '.json';
            if (\file_put_contents($metaPath, \json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \LOCK_EX) === false) {
                throw new \RuntimeException((string)__('Unable to write the database backup metadata.'));
            }

            $this->profileService->appendAuditEvent('backup_executed', $auditPayload + [
                'success' => true,
                'artifact' => (string)$artifact['name'],
                'bytes' => $bytes,
                'sha256' => $checksum,
            ]);

            return [
                'success' => true,
                'message' => (string)__('%{1} backup completed successfully.', [$this->driverLabel($driver)]),
                'artifact_path' => $artifactPath,
                'meta_path' => $metaPath,
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $targetConfig);
            if ($temporaryArtifactPath !== '' && \is_file($temporaryArtifactPath)) {
                @\unlink($temporaryArtifactPath);
            }
            $metaWritten = $metaPath !== '' && \is_file($metaPath);
            if ($artifactPath !== '' && \is_file($artifactPath) && !$metaWritten) {
                @\unlink($artifactPath);
            }
            $this->profileService->appendAuditEvent('backup_execute_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
                'artifact_path_state' => $artifactPath !== '' && \is_file($artifactPath) ? 'exists' : 'missing',
            ]);

            return [
                'success' => false,
                'message' => $message,
                'artifact_path' => $artifactPath,
                'meta_path' => $metaPath,
                'bytes' => 0,
                'checksum' => '',
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $backupPlan
     */
    private function assertExecutionGate(array $input, array $backupPlan): void
    {
        if ((string)($input['confirm_backup_execute'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm database backup execution before submitting.'));
        }

        if (\trim((string)($input['confirm_backup_phrase'] ?? '')) !== self::CONFIRMATION_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type RUN_DB_BACKUP to execute the database backup.'));
        }

        if (empty($backupPlan['can_execute'])) {
            throw new \InvalidArgumentException((string)__('Backup plan is not ready for execution.'));
        }

        if ((string)($backupPlan['action'] ?? '') !== self::ACTION_BACKUP_DATABASE) {
            throw new \InvalidArgumentException((string)__('Only backup_database can execute in this slice.'));
        }

        if (!$this->supportsBackupExecutionDriver((string)($backupPlan['driver'] ?? ''))) {
            throw new \InvalidArgumentException((string)__('Backup execution currently supports mysql and pgsql profiles.'));
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConnectionConfig(array $config): array
    {
        $type = \strtolower(\trim((string)($config['type'] ?? $config['driver'] ?? '')));
        $type = $type !== '' ? $type : self::DRIVER_MYSQL;
        return [
            'type' => $type,
            'hostname' => \trim((string)($config['hostname'] ?? $config['host'] ?? '')),
            'hostport' => \trim((string)($config['hostport'] ?? $config['port'] ?? $this->defaultPort($type))),
            'database' => \trim((string)($config['database'] ?? $config['dbname'] ?? $config['name'] ?? '')),
            'username' => \trim((string)($config['username'] ?? $config['user'] ?? '')),
            'password' => (string)($config['password'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function missingConnectionFields(array $config): array
    {
        $missing = [];
        foreach (['hostname', 'hostport', 'database', 'username'] as $field) {
            if (\trim((string)($config[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @return array{name:string,path:string}
     */
    private function resolveArtifactPath(string $artifact, string $driver): array
    {
        $artifact = \trim($artifact);
        if (!$this->isExecutableArtifactName($artifact, $driver)) {
            throw new \InvalidArgumentException($this->backupArtifactHelp($driver));
        }

        $dir = $this->backupDir();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the Database Manager backup directory.'));
        }
        $realDir = \realpath($dir);
        if (!\is_string($realDir) || $realDir === '') {
            throw new \RuntimeException((string)__('Unable to create the Database Manager backup directory.'));
        }

        $path = $realDir . \DIRECTORY_SEPARATOR . $artifact;
        if (!$this->pathWithin($path, $realDir)) {
            throw new \RuntimeException((string)__('The selected backup is outside the Database Manager backup directory.'));
        }
        if (\is_file($path)) {
            throw new \RuntimeException((string)__('Backup artifact already exists; choose a new file name.'));
        }

        return [
            'name' => $artifact,
            'path' => $path,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runPgDump(array $config, string $scope, string $artifactPath): void
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException((string)__('proc_open is required for database backup execution.'));
        }

        $pgDump = $this->findPgDumpBinary();
        $format = $this->artifactDumpFormat($artifactPath);
        $command = [
            $pgDump,
            '--host',
            (string)$config['hostname'],
            '--port',
            (string)$config['hostport'],
            '--username',
            (string)$config['username'],
            '--dbname',
            (string)$config['database'],
            '--format',
            $format,
            '--file',
            $artifactPath,
            '--no-password',
        ];
        if ($scope === 'schema_only') {
            $command[] = '--schema-only';
        } elseif ($scope === 'data_only') {
            $command[] = '--data-only';
        }

        $env = \getenv();
        $env = \is_array($env) ? $env : [];
        if ((string)$config['password'] !== '') {
            $env['PGPASSWORD'] = (string)$config['password'];
        }

        $this->runBackupProcess(
            $command,
            $env,
            $artifactPath,
            (string)__('Unable to start PostgreSQL backup command.'),
            (string)__('PostgreSQL backup command failed.')
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runMysqlDump(array $config, string $scope, string $artifactPath): void
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException((string)__('proc_open is required for database backup execution.'));
        }

        $mysqlDump = $this->findMysqlDumpBinary();
        $command = [
            $mysqlDump,
            '--host=' . (string)$config['hostname'],
            '--port=' . (string)$config['hostport'],
            '--user=' . (string)$config['username'],
            '--protocol=TCP',
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--result-file=' . $artifactPath,
        ];
        if ($scope === 'schema_only') {
            $command[] = '--no-data';
        } elseif ($scope === 'data_only') {
            $command[] = '--no-create-info';
        }
        $command[] = (string)$config['database'];

        $env = \getenv();
        $env = \is_array($env) ? $env : [];
        if ((string)$config['password'] !== '') {
            $env['MYSQL_PWD'] = (string)$config['password'];
        }

        $this->runBackupProcess(
            $command,
            $env,
            $artifactPath,
            (string)__('Unable to start MySQL backup command.'),
            (string)__('MySQL backup command failed.')
        );
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function runBackupProcess(array $command, array $env, string $artifactPath, string $startError, string $failureFallback): void
    {
        $pipes = [];
        $process = @\proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->bpRoot(),
            $env,
            ['bypass_shell' => true]
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException($startError);
        }

        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }
        $stdout = isset($pipes[1]) && \is_resource($pipes[1]) ? (string)\stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) && \is_resource($pipes[2]) ? (string)\stream_get_contents($pipes[2]) : '';
        if (isset($pipes[1]) && \is_resource($pipes[1])) {
            \fclose($pipes[1]);
        }
        if (isset($pipes[2]) && \is_resource($pipes[2])) {
            \fclose($pipes[2]);
        }

        $exitCode = \proc_close($process);
        if ($exitCode !== 0) {
            if (\is_file($artifactPath)) {
                @\unlink($artifactPath);
            }
            $message = \trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException($message !== '' ? \mb_substr($message, 0, 220) : $failureFallback);
        }
    }

    private function findPgDumpBinary(): string
    {
        $names = \PHP_OS_FAMILY === 'Windows' ? ['pg_dump.exe', 'pg_dump'] : ['pg_dump'];
        $path = (string)\getenv('PATH');
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            $dir = \trim($dir, " \t\n\r\0\x0B\"");
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return 'pg_dump';
    }

    private function findMysqlDumpBinary(): string
    {
        $names = \PHP_OS_FAMILY === 'Windows' ? ['mysqldump.exe', 'mysqldump'] : ['mysqldump'];
        $path = (string)\getenv('PATH');
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            $dir = \trim($dir, " \t\n\r\0\x0B\"");
            if ($dir === '') {
                continue;
            }
            foreach ($names as $name) {
                $candidate = \rtrim($dir, '\\/') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return 'mysqldump';
    }

    private function artifactDumpFormat(string $artifactPath): string
    {
        $extension = \strtolower((string)\pathinfo($artifactPath, \PATHINFO_EXTENSION));
        return \in_array($extension, ['dump', 'backup'], true) ? 'custom' : 'plain';
    }

    private function isCompressedSqlArtifact(string $artifact): bool
    {
        return \str_ends_with(\strtolower($artifact), '.sql.gz');
    }

    private function temporarySqlArtifactPath(string $artifactPath): string
    {
        $dir = \dirname($artifactPath);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $dir . \DIRECTORY_SEPARATOR . \basename($artifactPath) . '.' . \bin2hex(\random_bytes(4)) . '.tmp.sql';
            if (!\file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException((string)__('Unable to reserve a temporary database backup artifact.'));
    }

    private function compressSqlArtifact(string $sourcePath, string $targetPath): void
    {
        if (!\function_exists('gzopen') || !\function_exists('gzwrite') || !\function_exists('gzclose')) {
            throw new \RuntimeException((string)__('zlib is required for compressed database backup artifacts.'));
        }

        $source = @\fopen($sourcePath, 'rb');
        if (!\is_resource($source)) {
            throw new \RuntimeException((string)__('Unable to read the temporary database backup artifact.'));
        }

        $target = @\gzopen($targetPath, 'wb6');
        if (!\is_resource($target)) {
            \fclose($source);
            throw new \RuntimeException((string)__('Unable to write the compressed database backup artifact.'));
        }

        $failure = null;
        try {
            while (!\feof($source)) {
                $chunk = \fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('Unable to read the temporary database backup artifact.'));
                }
                if ($chunk === '') {
                    break;
                }
                $written = \gzwrite($target, $chunk);
                if ($written === false || $written !== \strlen($chunk)) {
                    throw new \RuntimeException((string)__('Unable to write the compressed database backup artifact.'));
                }
            }
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }

        \fclose($source);
        $closed = \gzclose($target);
        if ($failure instanceof \Throwable) {
            @\unlink($targetPath);
            throw $failure;
        }
        if ($closed === false) {
            @\unlink($targetPath);
            throw new \RuntimeException((string)__('Unable to close the compressed database backup artifact.'));
        }
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function isExecutableArtifactName(string $artifact, string $driver): bool
    {
        if (!(\preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1
            && !\str_contains($artifact, '..')
            && !\str_contains($artifact, '/')
            && !\str_contains($artifact, '\\')
            && !\str_contains($artifact, ':'))) {
            return false;
        }

        return match ($driver) {
            self::DRIVER_MYSQL => \preg_match('/\.(sql|sql\.gz)$/i', $artifact) === 1,
            self::DRIVER_PGSQL => \preg_match('/\.(sql|sql\.gz|dump|backup)$/i', $artifact) === 1,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $projectProfile
     * @return array<string, mixed>
     */
    private function auditProfile(array $projectProfile): array
    {
        return [
            'profile_key' => (string)($projectProfile['profile_key'] ?? ''),
            'project_id' => (string)($projectProfile['project_id'] ?? ''),
            'domain' => (string)($projectProfile['domain'] ?? ''),
            'project_type' => (string)($projectProfile['project_type'] ?? ''),
            'enabled' => !empty($projectProfile['enabled']),
            'source_connection_key' => (string)($projectProfile['source_connection_key'] ?? ''),
            'password_state' => !empty($projectProfile['password_configured']) || !empty($projectProfile['env_password_configured'])
                ? 'configured'
                : 'empty',
        ];
    }

    /**
     * @param array<string, mixed> $sourceProfile
     * @param array<string, mixed> $targetConfig
     */
    private function sanitizeDatabaseError(string $message, array $sourceProfile, array $targetConfig = []): string
    {
        $message = \trim($message);
        $sensitiveValues = [
            (string)($sourceProfile['password'] ?? ''),
            (string)($sourceProfile['username'] ?? $sourceProfile['user'] ?? ''),
            (string)($targetConfig['password'] ?? ''),
            (string)($targetConfig['username'] ?? $targetConfig['user'] ?? ''),
        ];
        foreach ($sensitiveValues as $value) {
            if ($value !== '') {
                $message = \str_replace($value, '[secret]', $message);
            }
        }

        return \mb_substr($message !== '' ? $message : (string)__('Database backup execution failed.'), 0, 220);
    }

    private function backupDir(): string
    {
        return $this->bpPath('var' . \DIRECTORY_SEPARATOR . 'backups' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'db-manager' . \DIRECTORY_SEPARATOR . 'database');
    }

    private function bpPath(string $relative): string
    {
        return $this->bpRoot() . \DIRECTORY_SEPARATOR . \ltrim($relative, '\\/');
    }

    private function bpRoot(): string
    {
        $root = \defined('BP') ? (string)BP : (string)\getcwd();
        return \rtrim($root, '\\/');
    }

    private function pathWithin(string $path, string $root): bool
    {
        $path = \strtolower(\str_replace('\\', '/', \rtrim($path, '\\/')));
        $root = \strtolower(\str_replace('\\', '/', \rtrim($root, '\\/')));
        return $path === $root || \str_starts_with($path, $root . '/');
    }

    private function maskValue(string $value): string
    {
        $value = \trim($value);
        $length = \strlen($value);
        if ($value === '') {
            return '';
        }
        if ($length <= 2) {
            return \str_repeat('*', $length);
        }

        return \substr($value, 0, 1) . \str_repeat('*', \max(1, $length - 2)) . \substr($value, -1);
    }

    private function supportsBackupExecutionDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
    }

    private function backupArtifactHelp(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL
            ? (string)__('Use a .sql or .sql.gz artifact before MySQL backup execution.')
            : (string)__('Use a .sql, .sql.gz, .dump, or .backup artifact before backup execution.');
    }

    private function defaultPort(string $driver): string
    {
        return match ($driver) {
            self::DRIVER_MYSQL => '3306',
            self::DRIVER_PGSQL => '5432',
            default => '',
        };
    }

    private function driverLabel(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL ? 'MySQL' : 'PostgreSQL';
    }
}
