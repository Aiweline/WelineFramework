<?php
declare(strict_types=1);

namespace Weline\DbManager\Service;

class WlsDatabaseLifecycleExecutionService
{
    private const CONFIRMATION_PHRASE = 'RUN_DB_LIFECYCLE';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';
    private const SECRET_PLACEHOLDER = '<profile-secret>';

    public function __construct(
        private readonly WlsDatabaseProfileService $profileService
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $context
     * @param array<string, mixed> $lifecyclePlan
     * @param array<string, mixed> $projectProfile
     * @param array<string, mixed> $sourceProfile
     * @return array{success:bool,message:string,executed_count:int,verification_count:int}
     */
    public function executeFromPanel(
        array $input,
        array $context,
        array $lifecyclePlan,
        array $projectProfile,
        array $sourceProfile
    ): array {
        $auditPayload = [
            'action' => (string)($lifecyclePlan['action'] ?? ''),
            'driver' => (string)($lifecyclePlan['driver'] ?? ''),
            'database' => (string)($lifecyclePlan['database'] ?? ''),
            'username' => $this->maskValue((string)($lifecyclePlan['username'] ?? '')),
            'host' => (string)($lifecyclePlan['host'] ?? ''),
            'grant_mode' => (string)($lifecyclePlan['grant_mode'] ?? ''),
            'profile' => $this->auditProfile($projectProfile),
        ];

        $targetConfig = [];
        try {
            $adapterPlan = \is_array($lifecyclePlan['adapter_plan'] ?? null)
                ? (array)$lifecyclePlan['adapter_plan']
                : [];
            $this->assertExecutionGate($input, $lifecyclePlan, $adapterPlan);

            $sourceConfig = $this->normalizeSourceConfig($sourceProfile, (string)($lifecyclePlan['driver'] ?? ''));
            $missing = $this->missingSourceFields($sourceConfig);
            if ($missing !== []) {
                throw new \InvalidArgumentException((string)__('Source DBA connection is incomplete: %{1}', [\implode(', ', $missing)]));
            }

            $targetConfig = $this->profileService->buildConnectionConfigForContextWithSource($context, $sourceProfile);
            if ($targetConfig === null) {
                $targetConfig = [];
                throw new \InvalidArgumentException((string)__('Enable and save the Project Profile before executing lifecycle SQL.'));
            }

            $sourcePdo = $this->openSourcePdo($sourceConfig);
            $this->probeConnection($sourcePdo);

            $statements = $this->prepareExecutableStatements(
                \array_values((array)($adapterPlan['statements'] ?? [])),
                $sourcePdo,
                (string)($targetConfig['password'] ?? '')
            );
            $verificationQueries = $this->verificationQueries((array)($adapterPlan['verification_queries'] ?? []));
            if ($statements === []) {
                throw new \InvalidArgumentException((string)__('Lifecycle SQL plan has no executable statements.'));
            }

            $executed = 0;
            $targetDatabasePdo = null;
            foreach ($statements as $statement) {
                $statementPdo = $sourcePdo;
                if ($statement['connection'] === 'target_database') {
                    $targetDatabasePdo ??= $this->openTargetDatabasePdo(
                        $sourceConfig,
                        (string)($targetConfig['database'] ?? ($lifecyclePlan['database'] ?? ''))
                    );
                    $statementPdo = $targetDatabasePdo;
                }

                $statementPdo->exec($statement['sql']);
                $executed++;
            }

            $verified = 0;
            foreach ($verificationQueries as $query) {
                $result = $sourcePdo->query($query);
                if ($result !== false) {
                    $result->fetchAll(\PDO::FETCH_ASSOC);
                    $verified++;
                }
            }

            $this->profileService->appendAuditEvent('lifecycle_executed', $auditPayload + [
                'success' => true,
                'executed_count' => $executed,
                'verification_count' => $verified,
            ]);

            return [
                'success' => true,
                'message' => (string)__('Lifecycle SQL executed successfully.'),
                'executed_count' => $executed,
                'verification_count' => $verified,
            ];
        } catch (\Throwable $throwable) {
            $message = $this->sanitizeDatabaseError($throwable->getMessage(), $sourceProfile, $targetConfig);
            $this->profileService->appendAuditEvent('lifecycle_execute_failed', $auditPayload + [
                'success' => false,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'executed_count' => 0,
                'verification_count' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $lifecyclePlan
     * @param array<string, mixed> $adapterPlan
     */
    private function assertExecutionGate(array $input, array $lifecyclePlan, array $adapterPlan): void
    {
        if ((string)($input['confirm_lifecycle_execute'] ?? '0') !== '1') {
            throw new \InvalidArgumentException((string)__('Confirm lifecycle SQL execution before submitting.'));
        }

        $expectedPhrase = (string)($adapterPlan['confirmation_phrase'] ?? self::CONFIRMATION_PHRASE);
        $phrase = \trim((string)($input['confirm_lifecycle_phrase'] ?? ''));
        if ($phrase !== $expectedPhrase) {
            throw new \InvalidArgumentException((string)__('Type RUN_DB_LIFECYCLE to execute lifecycle SQL.'));
        }

        if (empty($lifecyclePlan['can_execute'])) {
            throw new \InvalidArgumentException((string)__('Lifecycle plan is not ready for execution.'));
        }

        if ((string)($adapterPlan['state'] ?? '') !== 'planned') {
            throw new \InvalidArgumentException((string)__('Adapter SQL plan is not ready for execution.'));
        }

        $driver = (string)($lifecyclePlan['driver'] ?? '');
        if (!\in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true)) {
            throw new \InvalidArgumentException((string)__('Lifecycle execution currently supports only mysql or pgsql.'));
        }
    }

    /**
     * @param array<int, mixed> $rawStatements
     * @return array<int, array{label:string,sql:string,connection:string}>
     */
    private function prepareExecutableStatements(array $rawStatements, \PDO $pdo, string $targetPassword): array
    {
        $statements = [];
        foreach ($rawStatements as $rawStatement) {
            if (!\is_array($rawStatement)) {
                continue;
            }

            $sql = \trim((string)($rawStatement['sql'] ?? ''));
            if ($sql === '') {
                continue;
            }

            if (\str_contains($sql, self::SECRET_PLACEHOLDER)) {
                if ($targetPassword === '') {
                    throw new \InvalidArgumentException((string)__('Project Profile password is required for this lifecycle action.'));
                }

                $quotedPassword = $pdo->quote($targetPassword);
                if ($quotedPassword === false) {
                    throw new \RuntimeException((string)__('Database password could not be quoted safely by PDO.'));
                }

                $sql = \str_replace("'" . self::SECRET_PLACEHOLDER . "'", $quotedPassword, $sql);
            }

            if (\str_contains($sql, self::SECRET_PLACEHOLDER)) {
                throw new \InvalidArgumentException((string)__('Lifecycle SQL still contains an unresolved secret placeholder.'));
            }

            $statements[] = [
                'label' => (string)($rawStatement['label'] ?? __('SQL Statement')),
                'sql' => $sql,
                'connection' => $this->normalizeStatementConnection((string)($rawStatement['connection'] ?? 'source')),
            ];
        }

        return $statements;
    }

    /**
     * @param array<string, mixed> $sourceConfig
     */
    private function openTargetDatabasePdo(array $sourceConfig, string $targetDatabase): \PDO
    {
        $targetDatabase = \trim($targetDatabase);
        if ($targetDatabase === '') {
            throw new \InvalidArgumentException((string)__('Target database is required for this lifecycle statement.'));
        }

        if ((string)($sourceConfig['type'] ?? '') !== self::DRIVER_PGSQL) {
            throw new \InvalidArgumentException((string)__('Target-database lifecycle statements currently require pgsql.'));
        }

        $targetConfig = $sourceConfig;
        $targetConfig['database'] = $targetDatabase;

        return $this->openSourcePdo($targetConfig);
    }

    private function normalizeStatementConnection(string $connection): string
    {
        $connection = \strtolower(\trim($connection));
        return \in_array($connection, ['source', 'target_database'], true) ? $connection : 'source';
    }

    /**
     * @param array<int, mixed> $rawQueries
     * @return array<int, string>
     */
    private function verificationQueries(array $rawQueries): array
    {
        $queries = [];
        foreach ($rawQueries as $query) {
            $query = \trim((string)$query);
            if ($query !== '' && !\str_contains($query, self::SECRET_PLACEHOLDER)) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    private function probeConnection(\PDO $pdo): void
    {
        $statement = $pdo->query('SELECT 1');
        if ($statement === false) {
            throw new \RuntimeException((string)__('Source DBA connection test failed.'));
        }
        $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $sourceProfile
     * @return array<string, mixed>
     */
    private function normalizeSourceConfig(array $sourceProfile, string $fallbackDriver): array
    {
        $type = \strtolower(\trim((string)($sourceProfile['type'] ?? $sourceProfile['driver'] ?? $fallbackDriver)));
        if ($type === '') {
            $type = self::DRIVER_MYSQL;
        }

        return [
            'type' => $type,
            'hostname' => \trim((string)($sourceProfile['hostname'] ?? $sourceProfile['host'] ?? '')),
            'hostport' => \trim((string)($sourceProfile['hostport'] ?? $sourceProfile['port'] ?? $this->defaultPort($type))),
            'database' => \trim((string)($sourceProfile['database'] ?? $sourceProfile['dbname'] ?? $sourceProfile['name'] ?? '')),
            'username' => \trim((string)($sourceProfile['username'] ?? $sourceProfile['user'] ?? '')),
            'password' => (string)($sourceProfile['password'] ?? ''),
            'charset' => \trim((string)($sourceProfile['charset'] ?? ($type === self::DRIVER_MYSQL ? 'utf8mb4' : ''))),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    private function missingSourceFields(array $config): array
    {
        $missing = [];
        foreach (['hostname', 'hostport', 'username'] as $field) {
            if (\trim((string)($config[$field] ?? '')) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function openSourcePdo(array $config): \PDO
    {
        $type = (string)$config['type'];
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
        ];

        if ($type === self::DRIVER_PGSQL) {
            $database = \trim((string)$config['database']);
            $dsn = \sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string)$config['hostname'],
                (string)$config['hostport'],
                $database !== '' ? $database : 'postgres'
            );

            return new \PDO($dsn, (string)$config['username'], (string)$config['password'], $options);
        }

        $dsn = \sprintf(
            'mysql:host=%s;port=%s%s%s',
            (string)$config['hostname'],
            (string)$config['hostport'],
            \trim((string)$config['database']) !== '' ? ';dbname=' . (string)$config['database'] : '',
            \trim((string)$config['charset']) !== '' ? ';charset=' . (string)$config['charset'] : ''
        );

        return new \PDO($dsn, (string)$config['username'], (string)$config['password'], $options);
    }

    /**
     * @param array<string, mixed> $sourceProfile
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

        return \mb_substr($message !== '' ? $message : (string)__('Lifecycle SQL execution failed.'), 0, 220);
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

    private function defaultPort(string $type): string
    {
        return match ($type) {
            self::DRIVER_PGSQL => '5432',
            default => '3306',
        };
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
}
