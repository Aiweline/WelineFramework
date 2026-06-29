<?php
declare(strict_types=1);

namespace Weline\DbManager\Service\Adapter;

class WlsDatabasePostgreSqlPlainRestoreAdapter
{
    public function __construct(
        private readonly string $backupDir,
        private readonly string $bpRoot
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @return array{adapter:string,reset_mode:string,schema_count:int}
     */
    public function restore(array $config, string $artifactPath): array
    {
        if (!\function_exists('proc_open')) {
            throw new \RuntimeException((string)__('proc_open is required for database restore execution.'));
        }

        $pdo = $this->connect($config);
        $lockAcquired = false;
        $schemaCount = 0;
        try {
            $lockAcquired = $this->acquireRestoreLock($pdo);
            if (!$lockAcquired) {
                throw new \RuntimeException((string)__('Another PostgreSQL plain SQL restore appears to be running.'));
            }

            $schemaCount = $this->assertResetSafe($pdo);
            $this->resetPublicSchema($pdo, (string)$config['username']);
            $this->runPsqlRestore($config, $artifactPath);
        } finally {
            if ($lockAcquired) {
                $this->releaseRestoreLock($pdo);
            }
        }

        return [
            'adapter' => 'psql_schema_reset',
            'reset_mode' => 'public_schema',
            'schema_count' => \max(1, $schemaCount),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connect(array $config): \PDO
    {
        $dsn = \sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            (string)$config['hostname'],
            (string)$config['hostport'],
            (string)$config['database']
        );

        return new \PDO(
            $dsn,
            (string)$config['username'],
            (string)$config['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }

    private function acquireRestoreLock(\PDO $pdo): bool
    {
        return $this->postgresqlBool($this->fetchScalar($pdo, 'SELECT pg_try_advisory_lock(927716, 20260622)'));
    }

    private function releaseRestoreLock(\PDO $pdo): void
    {
        try {
            $this->fetchScalar($pdo, 'SELECT pg_advisory_unlock(927716, 20260622)');
        } catch (\Throwable) {
        }
    }

    private function assertResetSafe(\PDO $pdo): int
    {
        $activeSessions = (int)$this->fetchScalar(
            $pdo,
            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND state <> 'idle'"
        );
        if ($activeSessions > 0) {
            throw new \RuntimeException((string)__('PostgreSQL plain SQL restore is blocked while other active sessions are using the target database.'));
        }

        $preparedTransactions = (int)$this->fetchScalar(
            $pdo,
            'SELECT count(*) FROM pg_prepared_xacts WHERE database = current_database()'
        );
        if ($preparedTransactions > 0) {
            throw new \RuntimeException((string)__('PostgreSQL plain SQL restore is blocked while prepared transactions exist.'));
        }

        $otherAdvisoryLocks = (int)$this->fetchScalar(
            $pdo,
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND granted AND pid <> pg_backend_pid()"
        );
        if ($otherAdvisoryLocks > 0) {
            throw new \RuntimeException((string)__('PostgreSQL plain SQL restore is blocked while another advisory lock is active.'));
        }

        $schemas = $this->fetchStringList(
            $pdo,
            "SELECT nspname FROM pg_namespace WHERE nspname NOT IN ('pg_catalog', 'information_schema') AND nspname NOT LIKE 'pg_toast%' AND nspname NOT LIKE 'pg_temp_%' AND nspname NOT LIKE 'pg_toast_temp_%' ORDER BY nspname"
        );
        $unexpectedSchemas = \array_values(\array_filter(
            $schemas,
            static fn (string $schema): bool => $schema !== 'public'
        ));
        if ($unexpectedSchemas !== []) {
            throw new \RuntimeException((string)__('PostgreSQL plain SQL restore public-schema mode is blocked by extra schemas: %{1}', [\implode(', ', $unexpectedSchemas)]));
        }

        return \count($schemas);
    }

    private function resetPublicSchema(\PDO $pdo, string $username): void
    {
        $username = \trim($username);
        if ($username === '') {
            throw new \RuntimeException((string)__('PostgreSQL restore user is required before public schema reset.'));
        }

        $quotedUser = $this->quoteIdentifier($username);
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
        $pdo->exec('CREATE SCHEMA public AUTHORIZATION ' . $quotedUser);
        $pdo->exec('GRANT ALL ON SCHEMA public TO ' . $quotedUser);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runPsqlRestore(array $config, string $artifactPath): void
    {
        $command = [
            $this->findPsqlBinary(),
            '--host',
            (string)$config['hostname'],
            '--port',
            (string)$config['hostport'],
            '--username',
            (string)$config['username'],
            '--dbname',
            (string)$config['database'],
            '--no-password',
            '--single-transaction',
            '--set=ON_ERROR_STOP=1',
        ];

        $env = \getenv();
        $env = \is_array($env) ? $env : [];
        if ((string)$config['password'] !== '') {
            $env['PGPASSWORD'] = (string)$config['password'];
        }

        $this->runRestoreProcess(
            $command,
            $env,
            $artifactPath,
            (string)__('Unable to start PostgreSQL plain SQL restore command.'),
            (string)__('PostgreSQL plain SQL restore command failed.')
        );
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function runRestoreProcess(
        array $command,
        array $env,
        string $artifactPath,
        string $startError,
        string $failureFallback
    ): void {
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
            $this->bpRoot,
            $env,
            ['bypass_shell' => true]
        );
        if (!\is_resource($process)) {
            @\unlink($stdoutPath);
            @\unlink($stderrPath);
            throw new \RuntimeException($startError);
        }

        $failure = null;
        try {
            if (!isset($pipes[0]) || !\is_resource($pipes[0])) {
                throw new \RuntimeException((string)__('Unable to open restore command input pipe.'));
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
            throw new \RuntimeException($message !== '' ? \mb_substr($message, 0, 220) : $failureFallback);
        }
    }

    /**
     * @param resource $stdin
     */
    private function streamArtifactToProcess(string $artifactPath, $stdin): void
    {
        if (\str_ends_with(\strtolower($artifactPath), '.sql.gz')) {
            if (!\function_exists('gzopen') || !\function_exists('gzread') || !\function_exists('gzclose')) {
                throw new \RuntimeException((string)__('zlib is required for compressed database restore artifacts.'));
            }
            $handle = @\gzopen($artifactPath, 'rb');
            if (!\is_resource($handle)) {
                throw new \RuntimeException((string)__('Compressed restore artifact could not be opened.'));
            }
            try {
                while (!\gzeof($handle)) {
                    $chunk = \gzread($handle, 1024 * 1024);
                    if ($chunk === false) {
                        throw new \RuntimeException((string)__('Compressed restore artifact could not be read.'));
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
            throw new \RuntimeException((string)__('Restore artifact could not be opened.'));
        }
        try {
            while (!\feof($handle)) {
                $chunk = \fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException((string)__('Restore artifact could not be read.'));
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
                throw new \RuntimeException((string)__('Unable to stream restore artifact into the database client.'));
            }
            $offset += $written;
        }
    }

    private function fetchScalar(\PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException((string)__('PostgreSQL safety query failed.'));
        }

        return $statement->fetchColumn();
    }

    /**
     * @return array<int, string>
     */
    private function fetchStringList(\PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException((string)__('PostgreSQL safety query failed.'));
        }

        $values = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $values[] = (string)$value;
        }

        return $values;
    }

    private function postgresqlBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(\strtolower((string)$value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }

    private function findPsqlBinary(): string
    {
        $names = \PHP_OS_FAMILY === 'Windows' ? ['psql.exe', 'psql'] : ['psql'];
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

        return 'psql';
    }

    private function temporaryProcessOutputPath(): string
    {
        $dir = $this->backupDir;
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new \RuntimeException((string)__('Unable to create the Database Manager backup directory.'));
        }

        $path = \tempnam($dir, 'restore-process-');
        if (!\is_string($path) || $path === '') {
            throw new \RuntimeException((string)__('Unable to reserve restore process output storage.'));
        }

        return $path;
    }
}
