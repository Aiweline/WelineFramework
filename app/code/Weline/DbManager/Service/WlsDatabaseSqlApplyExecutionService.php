<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseSqlApplyExecutionService
{
    private const ACTION_SQL_APPLY = 'sql_apply';
    private const ACTION_BACKUP_DATABASE = 'backup_database';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const CONFIRMATION_PHRASE = 'RUN_DB_SQL_APPLY';
    private const MAX_SQL_BYTES = 262144;
    private const MAX_STATEMENTS = 20;

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
     * @return array{success:bool,message:string,artifact_path:string,pre_backup_artifact:string,statement_count:int,checksum:string}
     */
    public function executeFromPanel(
        array $input,
        array $context,
        array $backupPlan,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $artifactPath = '';
        $preBackupArtifact = '';
        $targetConfig = [];
        $auditPayload = [
            'action' => (string)($backupPlan['action'] ?? ''),
            'driver' => (string)($backupPlan['driver'] ?? ''),
            'artifact' => (string)($backupPlan['artifact'] ?? ''),
            'database' => (string)($backupPlan['database'] ?? ''),
            'profile' => $this->auditProfile($projectProfile),
        ];

        try {
            $this->assertExecutionGate($input, $backupPlan);

            $targetConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($targetConfig === null) {
                $targetConfig = [];
                throw new \InvalidArgumentException((string)__('Enable and save the Project Profile before SQL apply execution.'));
            }

            $config = $this->normalizeConnectionConfig($targetConfig);
            $driver = (string)$config['type'];
            $planDriver = (string)($backupPlan['driver'] ?? '');
            $missing = $this->missingConnectionFields($config);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Project SQL apply connection is incomplete: %{1}', [\implode(', ', $missing)]));
            }
            if (!$this->supportsSqlApplyDriver($driver)) {
                throw new \InvalidArgumentException((string)__('SQL apply currently supports mysql and pgsql profiles.'));
            }
            if ($planDriver !== '' && $planDriver !== $driver) {
                throw new \InvalidArgumentException((string)__('Submitted SQL apply driver does not match the enabled Project Profile.'));
            }
            if (!$this->isSafeIdentifier((string)$config['database'])) {
                throw new \InvalidArgumentException((string)__('Database name must start with a letter or underscore and contain only letters, numbers, and underscores.'));
            }

            $artifact = $this->resolveExistingSqlArtifactPath((string)($backupPlan['artifact'] ?? ''));
            $artifactPath = $artifact['path'];
            $sql = $this->readSqlArtifact($artifactPath);
            $statements = $this->splitSqlStatements($sql);
            $this->assertAllowedStatements($statements);
            $checksum = \hash_file('sha256', $artifactPath);
            if (!\is_string($checksum) || $checksum === '') {
                throw new \RuntimeException((string)__('SQL apply artifact checksum could not be calculated.'));
            }

            $preBackupArtifact = $this->createPreApplyBackup(
                $context,
                $projectProfile,
                $sourceProfile,
                $driver,
                (string)$config['database']
            );

            $startedAt = \microtime(true);
            $pdo = $this->openPdo($config);
            $this->executeStatements($pdo, $statements, $driver);
            $statement = $pdo->query('SELECT 1');
            if ($statement === false) {
                throw new \RuntimeException((string)__('SQL apply verification query failed.'));
            }
            $statement->fetchColumn();
            $pdo = null;

            $this->profileService->appendAuditEvent('sql_apply_executed', $auditPayload + [
                'success' => true,
                'artifact' => (string)$artifact['name'],
                'artifact_sha256' => $checksum,
                'pre_apply_artifact' => $preBackupArtifact,
                'statement_count' => \count($statements),
                'duration_ms' => (int)\round((\microtime(true) - $startedAt) * 1000),
                'allowlist' => 'additive_ddl',
            ]);

            return [
                'success' => true,
                'message' => (string)__('SQL apply completed successfully after pre-apply backup.'),
                'artifact_path' => $artifactPath,
                'pre_backup_artifact' => $preBackupArtifact,
                'statement_count' => \count($statements),
                'checksum' => $checksum,
            ];
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $targetConfig);
            $this->profileService->appendAuditEvent('sql_apply_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
                'pre_apply_artifact' => $preBackupArtifact,
                'artifact_path_state' => $artifactPath !== '' && \is_file($artifactPath) ? 'exists' : 'missing',
            ]);

            return [
                'success' => false,
                'message' => $message,
                'artifact_path' => $artifactPath,
                'pre_backup_artifact' => $preBackupArtifact,
                'statement_count' => 0,
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
        if ((string)($input['confirm_sql_apply_execute'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm SQL apply execution before submitting.'));
        }

        if (\trim((string)($input['confirm_sql_apply_phrase'] ?? '')) !== self::CONFIRMATION_PHRASE) {
            throw new \InvalidArgumentException((string)__('Type RUN_DB_SQL_APPLY to execute SQL apply.'));
        }

        if (empty($backupPlan['can_sql_apply_execute'])) {
            throw new \InvalidArgumentException((string)__('SQL apply plan is not ready for execution.'));
        }

        if ((string)($backupPlan['action'] ?? '') !== self::ACTION_SQL_APPLY) {
            throw new \InvalidArgumentException((string)__('Only sql_apply can run SQL apply execution.'));
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
            'charset' => \trim((string)($config['charset'] ?? ($type === self::DRIVER_MYSQL ? 'utf8mb4' : ''))),
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
    private function resolveExistingSqlArtifactPath(string $artifact): array
    {
        $artifact = \trim($artifact);
        if (!$this->isSqlApplyArtifact($artifact)) {
            throw new \InvalidArgumentException((string)__('Use a reviewed .sql or .sql.gz artifact before SQL apply execution.'));
        }

        $dir = $this->backupDir();
        $realDir = \realpath($dir);
        if (!\is_string($realDir) || $realDir === '') {
            throw new \RuntimeException((string)__('Database Manager backup directory does not exist.'));
        }

        $path = $realDir . \DIRECTORY_SEPARATOR . $artifact;
        if (!$this->pathWithin($path, $realDir) || \is_link($path)) {
            throw new \RuntimeException((string)__('The selected SQL apply artifact is outside the Database Manager backup directory.'));
        }

        $realPath = \realpath($path);
        if (!\is_string($realPath) || $realPath === '' || !$this->pathWithin($realPath, $realDir)) {
            throw new \RuntimeException((string)__('SQL apply artifact was not found inside the Database Manager backup directory.'));
        }
        if (!\is_file($realPath) || !\is_readable($realPath)) {
            throw new \RuntimeException((string)__('SQL apply artifact is not readable.'));
        }

        return [
            'name' => $artifact,
            'path' => $realPath,
        ];
    }

    private function readSqlArtifact(string $artifactPath): string
    {
        $compressed = \str_ends_with(\strtolower($artifactPath), '.sql.gz');
        $sql = $compressed ? $this->readCompressedSql($artifactPath) : \file_get_contents($artifactPath);
        if (!\is_string($sql) || \trim($sql) === '') {
            throw new \RuntimeException((string)__('SQL apply artifact is empty.'));
        }
        if (\strlen($sql) > self::MAX_SQL_BYTES) {
            throw new \RuntimeException((string)__('SQL apply artifact exceeds the guarded size limit.'));
        }

        return $this->normalizeSqlContent($sql);
    }

    private function readCompressedSql(string $artifactPath): string
    {
        if (!\function_exists('gzopen') || !\function_exists('gzread') || !\function_exists('gzclose')) {
            throw new \RuntimeException((string)__('zlib is required for compressed SQL apply artifacts.'));
        }

        $handle = @\gzopen($artifactPath, 'rb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException((string)__('Unable to read the compressed SQL apply artifact.'));
        }

        $sql = '';
        try {
            while (!\gzeof($handle)) {
                $chunk = \gzread($handle, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('Unable to read the compressed SQL apply artifact.'));
                }
                $sql .= $chunk;
                if (\strlen($sql) > self::MAX_SQL_BYTES) {
                    throw new \RuntimeException((string)__('SQL apply artifact exceeds the guarded size limit.'));
                }
            }
        } finally {
            \gzclose($handle);
        }

        return $sql;
    }

    private function normalizeSqlContent(string $sql): string
    {
        $sql = \preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $sql = \preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $lines = \preg_split('/\R/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = \ltrim((string)$line);
            if ($trimmed === '' || \str_starts_with($trimmed, '--') || \str_starts_with($trimmed, '#')) {
                continue;
            }
            $clean[] = (string)$line;
        }

        return \trim(\implode("\n", $clean));
    }

    /**
     * @return array<int, string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = '';
        $length = \strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== '') {
                $buffer .= $char;
                if ($char === $quote && !$this->isEscaped($sql, $i)) {
                    if ($quote === '\'' && $i + 1 < $length && $sql[$i + 1] === '\'') {
                        $buffer .= $sql[$i + 1];
                        $i++;
                        continue;
                    }
                    $quote = '';
                }
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === ';') {
                $statement = \trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = \trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        if ($statements === []) {
            throw new \InvalidArgumentException((string)__('SQL apply artifact does not contain executable statements.'));
        }
        if (\count($statements) > self::MAX_STATEMENTS) {
            throw new \InvalidArgumentException((string)__('SQL apply artifact contains too many statements for this guarded slice.'));
        }

        return $statements;
    }

    private function isEscaped(string $sql, int $position): bool
    {
        $slashes = 0;
        for ($i = $position - 1; $i >= 0 && $sql[$i] === '\\'; $i--) {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    /**
     * @param array<int, string> $statements
     */
    private function assertAllowedStatements(array $statements): void
    {
        foreach ($statements as $statement) {
            $normalized = \trim(\preg_replace('/\s+/', ' ', $statement) ?? $statement);
            if ($normalized === '') {
                continue;
            }
            if (\str_contains($normalized, "\0") || \str_starts_with($normalized, '\\')) {
                throw new \InvalidArgumentException((string)__('SQL apply artifact contains unsupported control syntax.'));
            }
            if (\preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE|REPLACE|MERGE|CALL|EXEC|EXECUTE|GRANT|REVOKE|USE|LOAD\s+DATA|LOAD\s+XML|COPY|VACUUM|ANALYZE|LOCK|UNLOCK|SET\s+PASSWORD)\b/i', $normalized) === 1) {
                throw new \InvalidArgumentException((string)__('SQL apply artifact contains a blocked SQL keyword.'));
            }
            if (\preg_match('/\bCREATE\s+DATABASE\b|\bALTER\s+DATABASE\b/i', $normalized) === 1) {
                throw new \InvalidArgumentException((string)__('SQL apply artifact cannot create or alter databases.'));
            }
            if (!$this->isAllowedAdditiveStatement($normalized)) {
                throw new \InvalidArgumentException((string)__('SQL apply only allows additive CREATE TABLE, CREATE INDEX, or ALTER TABLE ADD statements in this slice.'));
            }
        }
    }

    private function isAllowedAdditiveStatement(string $statement): bool
    {
        return \preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?[`"A-Za-z0-9_.]+/i', $statement) === 1
            || \preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(CONCURRENTLY\s+)?(IF\s+NOT\s+EXISTS\s+)?[`"A-Za-z0-9_.]+/i', $statement) === 1
            || \preg_match('/^ALTER\s+TABLE\s+[`"A-Za-z0-9_.]+\s+ADD\s+/i', $statement) === 1;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     */
    private function createPreApplyBackup(array $context, array $projectProfile, array $sourceProfile, string $driver, string $database): string
    {
        $artifact = $this->preApplyArtifactName($driver, $database);
        $backupPlan = (new WlsDatabaseBackupPlanService())->buildPlan(
            [
                'backup_action' => self::ACTION_BACKUP_DATABASE,
                'backup_scope' => 'schema_and_data',
                'backup_artifact' => $artifact,
            ],
            $projectProfile,
            ['type' => $driver, 'database' => $database]
        );
        $result = (new WlsDatabaseBackupExecutionService($this->profileService))->executeFromPanel(
            [
                'confirm_backup_execute' => '1',
                'confirm_backup_phrase' => 'RUN_DB_BACKUP',
            ],
            $context,
            $backupPlan,
            $projectProfile,
            $sourceProfile
        );
        if (empty($result['success'])) {
            throw new \RuntimeException((string)($result['message'] ?? __('Pre-apply backup failed.')));
        }

        return $artifact;
    }

    private function preApplyArtifactName(string $driver, string $database): string
    {
        $seed = \preg_replace('/[^A-Za-z0-9_.-]+/', '-', $database) ?: 'database';
        $suffix = $driver === self::DRIVER_PGSQL ? '.backup' : '.sql';

        return \mb_substr(\trim($seed, '.-') ?: 'database', 0, 70)
            . '-pre-sql-apply-'
            . \date('Ymd-His')
            . '-'
            . \bin2hex(\random_bytes(3))
            . $suffix;
    }

    /**
     * @param array<int, string> $statements
     */
    private function executeStatements(\PDO $pdo, array $statements, string $driver): void
    {
        $useTransaction = $driver === self::DRIVER_PGSQL;
        if ($useTransaction) {
            $pdo->beginTransaction();
        }

        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            if ($useTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $throwable) {
            if ($useTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function openPdo(array $config): \PDO
    {
        $driver = (string)$config['type'];
        $extension = $driver === self::DRIVER_PGSQL ? 'pdo_pgsql' : 'pdo_mysql';
        if (!\extension_loaded('pdo') || !\extension_loaded($extension)) {
            throw new \RuntimeException((string)__('%{1} is not available for this runtime.', [$extension]));
        }

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
        ];
        if ($driver === self::DRIVER_PGSQL) {
            return new \PDO(
                \sprintf('pgsql:host=%s;port=%s;dbname=%s', (string)$config['hostname'], (string)$config['hostport'], (string)$config['database']),
                (string)$config['username'],
                (string)$config['password'],
                $options
            );
        }

        return new \PDO(
            \sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', (string)$config['hostname'], (string)$config['hostport'], (string)$config['database'], (string)$config['charset']),
            (string)$config['username'],
            (string)$config['password'],
            $options
        );
    }

    private function isSqlApplyArtifact(string $artifact): bool
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

    private function supportsSqlApplyDriver(string $driver): bool
    {
        return \in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true);
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

        return \mb_substr($message !== '' ? $message : (string)__('SQL apply execution failed.'), 0, 220);
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

    private function defaultPort(string $driver): string
    {
        return match ($driver) {
            self::DRIVER_MYSQL => '3306',
            self::DRIVER_PGSQL => '5432',
            default => '',
        };
    }
}
