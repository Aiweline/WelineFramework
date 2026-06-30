<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseMigrationExecutionService
{
    private const ACTION_MIGRATION_DRY_RUN = 'migration_dry_run';
    private const ACTION_BACKUP_DATABASE = 'backup_database';
    private const DRIVER_MYSQL = 'mysql';
    private const PREFLIGHT_PHRASE = 'CHECK_DB_MIGRATION';
    private const EXECUTION_PHRASE = 'RUN_DB_MIGRATION';

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
     * @return array{success:bool,message:string,artifact_path:string,pre_migration_artifact:string,bytes:int,checksum:string,verification_count:int}
     */
    public function executeFromPanel(
        array $input,
        array $context,
        array $backupPlan,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $artifactPath = '';
        $preMigrationArtifact = '';
        $targetConfig = [];
        $risk = '';
        $auditPayload = [
            'action' => (string)($backupPlan['action'] ?? ''),
            'driver' => (string)($backupPlan['driver'] ?? ''),
            'artifact' => (string)($backupPlan['artifact'] ?? ''),
            'database' => (string)($backupPlan['database'] ?? ''),
            'migration_target' => (string)($backupPlan['migration_target'] ?? ''),
            'profile' => $this->auditProfile($projectProfile),
        ];

        try {
            $this->assertExecutionGate($input, $backupPlan);

            $preflight = (new WlsDatabaseMigrationPreflightService($this->profileService))->preflightFromPanel(
                $input,
                $context,
                $backupPlan,
                $projectProfile,
                $sourceProfile
            );
            if (empty($preflight['success'])) {
                throw new \RuntimeException((string)($preflight['message'] ?? __('Migration preflight failed.')));
            }
            $risk = (string)($preflight['risk'] ?? '');

            $targetConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($targetConfig === null) {
                $targetConfig = [];
                throw new \InvalidArgumentException((string)__('Enable and save the Project Profile before migration execution.'));
            }

            $config = $this->normalizeConnectionConfig($targetConfig);
            $driver = (string)$config['type'];
            $planDriver = (string)($backupPlan['driver'] ?? '');
            $missing = $this->missingConnectionFields($config);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Project migration connection is incomplete: %{1}', [\implode(', ', $missing)]));
            }
            if (!$this->supportsMigrationExecutionDriver($driver)) {
                throw new \InvalidArgumentException((string)__('Migration execution currently supports mysql Project Profiles only.'));
            }
            if ($planDriver !== '' && $planDriver !== $driver) {
                throw new \InvalidArgumentException((string)__('Submitted migration driver does not match the enabled Project Profile.'));
            }
            if (!$this->isSafeIdentifier((string)$config['database'])) {
                throw new \InvalidArgumentException((string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.'));
            }

            $artifact = $this->resolveExistingMigrationArtifactPath((string)($backupPlan['artifact'] ?? ''));
            $artifactPath = $artifact['path'];
            $bytes = \filesize($artifactPath);
            if (!\is_int($bytes) || $bytes <= 0) {
                throw new \RuntimeException((string)__('Migration artifact is empty.'));
            }
            $checksum = \hash_file('sha256', $artifactPath);
            if (!\is_string($checksum) || $checksum === '') {
                throw new \RuntimeException((string)__('Migration artifact checksum could not be calculated.'));
            }
            if ((string)($preflight['checksum'] ?? '') !== '' && (string)$preflight['checksum'] !== $checksum) {
                throw new \RuntimeException((string)__('Migration artifact checksum changed after preflight; rebuild the plan before execution.'));
            }

            $preMigrationArtifact = $this->createPreMigrationBackup(
                $input,
                $context,
                $projectProfile,
                $sourceProfile,
                $driver,
                (string)$config['database']
            );

            $startedAt = \microtime(true);
            $this->runMysqlMigrationImport($config, $artifactPath);
            $verificationCount = $this->verifyMigratedConnection($config);

            $this->profileService->appendAuditEvent('migration_executed', $auditPayload + [
                'success' => true,
                'artifact' => (string)$artifact['name'],
                'bytes' => $bytes,
                'sha256' => $checksum,
                'migration_target' => (string)($backupPlan['migration_target'] ?? ''),
                'risk' => $risk,
                'pre_migration_artifact' => $preMigrationArtifact,
                'verification_count' => $verificationCount,
                'adapter' => 'mysql_import',
                'duration_ms' => (int)\round((\microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'success' => true,
                'message' => (string)__('Database migration import completed successfully after pre-migration backup.'),
                'artifact_path' => $artifactPath,
                'pre_migration_artifact' => $preMigrationArtifact,
                'bytes' => $bytes,
                'checksum' => $checksum,
                'verification_count' => $verificationCount,
            ];
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $targetConfig);
            $this->profileService->appendAuditEvent('migration_execute_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
                'risk' => $risk,
                'pre_migration_artifact' => $preMigrationArtifact,
                'artifact_path_state' => $artifactPath !== '' && \is_file($artifactPath) ? 'exists' : 'missing',
            ]);

            return [
                'success' => false,
                'message' => $message,
                'artifact_path' => $artifactPath,
                'pre_migration_artifact' => $preMigrationArtifact,
                'bytes' => 0,
                'checksum' => '',
                'verification_count' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $backupPlan
     */
    private function assertExecutionGate(array $input, array $backupPlan): void
    {
        if ((string)($input['confirm_migration_preflight'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm migration preflight before executing migration.'));
        }

        if (\trim((string)($input['confirm_migration_phrase'] ?? '')) !== self::PREFLIGHT_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type CHECK_DB_MIGRATION before executing migration.'));
        }

        if ((string)($input['confirm_migration_execute'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm database migration execution before submitting.'));
        }

        if (\trim((string)($input['confirm_migration_execute_phrase'] ?? '')) !== self::EXECUTION_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type RUN_DB_MIGRATION to execute the database migration import.'));
        }

        if (empty($backupPlan['can_migration_execute'])) {
            throw new \InvalidArgumentException((string)__('Migration plan is not ready for execution.'));
        }

        if ((string)($backupPlan['action'] ?? '') !== self::ACTION_MIGRATION_DRY_RUN) {
            throw new \InvalidArgumentException((string)__('Only migration_dry_run can execute migration import in this slice.'));
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
    private function resolveExistingMigrationArtifactPath(string $artifact): array
    {
        $artifact = \trim($artifact);
        if (!$this->isExecutableMigrationArtifact($artifact)) {
            throw new \InvalidArgumentException((string)__('Use a .sql or .sql.gz backup artifact before MySQL migration execution.'));
        }

        $dir = $this->backupDir();
        $realDir = \realpath($dir);
        if (!\is_string($realDir) || $realDir === '') {
            throw new \RuntimeException((string)__('Database Manager backup directory does not exist.'));
        }

        $path = $realDir . \DIRECTORY_SEPARATOR . $artifact;
        if (!$this->pathWithin($path, $realDir) || \is_link($path)) {
            throw new \RuntimeException((string)__('The selected migration artifact is outside the Database Manager backup directory.'));
        }

        $realPath = \realpath($path);
        if (!\is_string($realPath) || $realPath === '' || !$this->pathWithin($realPath, $realDir)) {
            throw new \RuntimeException((string)__('Migration artifact was not found inside the Database Manager backup directory.'));
        }
        if (!\is_file($realPath) || !\is_readable($realPath)) {
            throw new \RuntimeException((string)__('Migration artifact is not readable.'));
        }

        return [
            'name' => $artifact,
            'path' => $realPath,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     */
    private function createPreMigrationBackup(
        array $input,
        array $context,
        array $projectProfile,
        array $sourceProfile,
        string $driver,
        string $database
    ): string {
        $artifact = $this->preMigrationArtifactName($database);
        $backupPlan = (new WlsDatabaseBackupPlanService())->buildPlan(
            [
                'backup_action' => self::ACTION_BACKUP_DATABASE,
                'backup_scope' => 'schema_and_data',
                'backup_artifact' => $artifact,
            ],
            $projectProfile,
            $sourceProfile
        );
        if (empty($backupPlan['can_execute'])) {
            throw new \RuntimeException((string)($backupPlan['execution_label'] ?? __('Pre-migration backup is not ready.')));
        }

        $result = (new WlsDatabaseBackupExecutionService($this->profileService))->executeFromPanel(
            [
                'confirm_backup_execute' => '1',
                'confirm_backup_phrase' => 'RUN_DB_BACKUP',
            ] + $input,
            $context,
            $backupPlan,
            $projectProfile,
            $sourceProfile
        );
        if (empty($result['success'])) {
            throw new \RuntimeException((string)($result['message'] ?? __('Pre-migration backup failed.')));
        }

        return $artifact;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runMysqlMigrationImport(array $config, string $artifactPath): void
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException((string)__('proc_open is required for database migration execution.'));
        }

        $command = [
            $this->findMysqlClientBinary(),
            '--host=' . (string)$config['hostname'],
            '--port=' . (string)$config['hostport'],
            '--user=' . (string)$config['username'],
            '--protocol=TCP',
            '--default-character-set=utf8mb4',
            (string)$config['database'],
        ];

        $env = \getenv();
        $env = \is_array($env) ? $env : [];
        if ((string)$config['password'] !== '') {
            $env['MYSQL_PWD'] = (string)$config['password'];
        }

        $this->runImportProcess($command, $env, $artifactPath);
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function runImportProcess(array $command, array $env, string $artifactPath): void
    {
        $stdoutPath = $this->temporaryProcessOutputPath();
        $stderrPath = $this->temporaryProcessOutputPath();
        $pipes = [];
        $process = @\proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ],
            $pipes,
            $this->bpRoot(),
            $env,
            ['bypass_shell' => true]
        );
        if (!\is_resource($process)) {
            @\unlink($stdoutPath);
            @\unlink($stderrPath);
            throw new \RuntimeException((string)__('Unable to start MySQL migration import command.'));
        }

        $failure = null;
        try {
            if (!isset($pipes[0]) || !\is_resource($pipes[0])) {
                throw new \RuntimeException((string)__('Unable to open migration import input pipe.'));
            }
            $this->streamArtifactToProcess($artifactPath, $pipes[0]);
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }

        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }

        $exitCode = \proc_close($process);
        $stdout = \is_file($stdoutPath) ? (string)\file_get_contents($stdoutPath) : '';
        $stderr = \is_file($stderrPath) ? (string)\file_get_contents($stderrPath) : '';
        @\unlink($stdoutPath);
        @\unlink($stderrPath);

        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        if ($exitCode !== 0) {
            $message = \trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException($message !== '' ? \mb_substr($message, 0, 220) : (string)__('MySQL migration import command failed.'));
        }
    }

    /**
     * @param resource $stdin
     */
    private function streamArtifactToProcess(string $artifactPath, $stdin): void
    {
        if (\str_ends_with(\strtolower($artifactPath), '.sql.gz')) {
            if (!\function_exists('gzopen') || !\function_exists('gzread') || !\function_exists('gzclose')) {
                throw new \RuntimeException((string)__('zlib is required for compressed database migration artifacts.'));
            }
            $handle = @\gzopen($artifactPath, 'rb');
            if (!\is_resource($handle)) {
                throw new \RuntimeException((string)__('Compressed migration artifact could not be opened.'));
            }
            try {
                while (!\gzeof($handle)) {
                    $chunk = \gzread($handle, 1024 * 1024);
                    if ($chunk === false) {
                        throw new \RuntimeException((string)__('Compressed migration artifact could not be read.'));
                    }
                    if ($chunk === '') {
                        break;
                    }
                    $this->writeChunk($stdin, $chunk);
                }
            } finally {
                \gzclose($handle);
            }
            return;
        }

        $handle = @\fopen($artifactPath, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException((string)__('Migration artifact could not be opened.'));
        }
        try {
            while (!\feof($handle)) {
                $chunk = \fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('Migration artifact could not be read.'));
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeChunk($stdin, $chunk);
            }
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @param resource $stdin
     */
    private function writeChunk($stdin, string $chunk): void
    {
        $offset = 0;
        $length = \strlen($chunk);
        while ($offset < $length) {
            $written = \fwrite($stdin, \substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException((string)__('Unable to stream migration artifact into the database client.'));
            }
            $offset += $written;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function verifyMigratedConnection(array $config): int
    {
        if (!\extension_loaded('pdo') || !\extension_loaded('pdo_mysql')) {
            throw new \RuntimeException((string)__('pdo_mysql is not available for migration verification.'));
        }

        $pdo = new \PDO(
            \sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string)$config['hostname'],
                (string)$config['hostport'],
                (string)$config['database']
            ),
            (string)$config['username'],
            (string)$config['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $statement = $pdo->query('SELECT 1');
        if ($statement === false) {
            throw new \RuntimeException((string)__('Migration verification query failed.'));
        }
        $statement->fetchColumn();

        return 1;
    }

    private function findMysqlClientBinary(): string
    {
        $names = \PHP_OS_FAMILY === 'Windows'
            ? ['mysql.exe', 'mariadb.exe', 'mysql', 'mariadb']
            : ['mysql', 'mariadb'];
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

        return 'mysql';
    }

    private function temporaryProcessOutputPath(): string
    {
        $dir = $this->backupDir();
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the Database Manager backup directory.'));
        }

        $path = \tempnam($dir, 'migration-process-');
        if (!\is_string($path) || $path === '') {
            throw new \RuntimeException((string)__('Unable to reserve migration process output storage.'));
        }

        return $path;
    }

    private function preMigrationArtifactName(string $database): string
    {
        $seed = \preg_replace('/[^A-Za-z0-9_.-]+/', '-', \trim($database)) ?: 'database';
        $seed = \mb_substr(\trim($seed, '.-') ?: 'database', 0, 64);

        return 'pre-migration-' . $seed . '-' . \date('Ymd-His') . '-' . \bin2hex(\random_bytes(4)) . '.sql';
    }

    private function isExecutableMigrationArtifact(string $artifact): bool
    {
        return \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(sql|sql\.gz)$/i', $artifact) === 1
            && !\str_contains($artifact, '..')
            && !\str_contains($artifact, '/')
            && !\str_contains($artifact, '\\')
            && !\str_contains($artifact, ':');
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function supportsMigrationExecutionDriver(string $driver): bool
    {
        return $driver === self::DRIVER_MYSQL;
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
        foreach ([$this->backupDir(), $this->bpRoot()] as $pathValue) {
            if ($pathValue !== '') {
                $message = \str_replace($pathValue, '[path]', $message);
                $message = \str_replace(\str_replace('\\', '/', $pathValue), '[path]', $message);
            }
        }

        return \mb_substr($message !== '' ? $message : (string)__('Database migration execution failed.'), 0, 220);
    }

    private function defaultPort(string $driver): string
    {
        return $driver === self::DRIVER_MYSQL ? '3306' : '';
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
}
