<?php
declare(strict_types=1);

namespace Weline\DbManager\Service\Adapter;

class WlsDatabaseLifecycleSqlPlanAdapter
{
    private const ACTION_CREATE_DATABASE = 'create_database';
    private const ACTION_CREATE_USER = 'create_user';
    private const ACTION_GRANT_USER = 'grant_user';
    private const DRIVER_MYSQL = 'mysql';
    private const DRIVER_PGSQL = 'pgsql';

    /**
     * @return array<string, mixed>
     */
    public function emptyPlan(): array
    {
        $state = 'idle';

        return [
            'state' => $state,
            'state_label' => (string)__('等待生命周期动作'),
            'adapter_label' => '-',
            'execution_label' => $state === 'planned'
                ? (string)__('等待面板确认后可通过 POST 执行')
                : (string)__('仅预览，不执行 SQL'),
            'confirmation_phrase' => 'RUN_DB_LIFECYCLE',
            'audit_event' => 'db.lifecycle.preview',
            'statements' => [],
            'verification_queries' => [],
            'rollback_guidance' => [],
            'errors' => [],
            'warnings' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        string $action,
        string $driver,
        string $database,
        string $username,
        string $host,
        string $grantMode,
        bool $hasPasswordSecret
    ): array {
        if ($action === '') {
            return $this->emptyPlan();
        }

        $driver = $this->normalizeDriver($driver);
        $errors = [];
        $warnings = [];

        if (!\in_array($driver, [self::DRIVER_MYSQL, self::DRIVER_PGSQL], true)) {
            $errors[] = (string)__('生命周期 SQL 计划目前只支持 mysql 或 pgsql。');
        }
        if ($this->needsDatabase($action) && !$this->isSafeIdentifier($database)) {
            $errors[] = (string)__('数据库名未通过适配器 SQL 白名单。');
        }
        if ($this->needsUser($action) && !$this->isSafeUsername($username)) {
            $errors[] = (string)__('用户名未通过适配器 SQL 白名单。');
        }
        if ($action === self::ACTION_CREATE_USER && !$hasPasswordSecret) {
            $errors[] = (string)__('创建用户 SQL 计划需要 Project Profile 或 env profile 中已有密码。');
        }
        if ($driver === self::DRIVER_MYSQL && $this->needsUser($action) && $host === '') {
            $errors[] = (string)__('MySQL 用户 SQL 计划需要明确 Host Scope。');
        }
        if ($driver === self::DRIVER_PGSQL && $host !== '' && $this->needsUser($action)) {
            $warnings[] = (string)__('PostgreSQL role 不使用 Host Scope；该值只会保留在面板上下文中。');
        }

        if ($errors !== []) {
            return $this->basePlan($driver, 'blocked', (string)__('SQL 计划被阻断'), [], [], [], $errors, $warnings);
        }

        return match ($driver) {
            self::DRIVER_PGSQL => $this->pgsqlPlan($action, $database, $username, $grantMode, $warnings),
            default => $this->mysqlPlan($action, $database, $username, $host, $grantMode, $warnings),
        };
    }

    /**
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function mysqlPlan(string $action, string $database, string $username, string $host, string $grantMode, array $warnings): array
    {
        $account = $this->mysqlString($username) . '@' . $this->mysqlString($host);
        $databaseIdentifier = $this->mysqlIdentifier($database);

        $statements = match ($action) {
            self::ACTION_CREATE_DATABASE => [
                [
                    'label' => (string)__('创建数据库'),
                    'sql' => 'CREATE DATABASE IF NOT EXISTS ' . $databaseIdentifier . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
                ],
            ],
            self::ACTION_CREATE_USER => [
                [
                    'label' => (string)__('创建用户'),
                    'sql' => 'CREATE USER IF NOT EXISTS ' . $account . " IDENTIFIED BY '<profile-secret>';",
                ],
            ],
            self::ACTION_GRANT_USER => [
                [
                    'label' => (string)__('授予权限'),
                    'sql' => 'GRANT ' . $this->mysqlPrivileges($grantMode) . ' ON ' . $databaseIdentifier . '.* TO ' . $account . ';',
                ],
            ],
            default => [],
        };

        $verification = match ($action) {
            self::ACTION_CREATE_DATABASE => [
                "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $this->mysqlString($database) . ';',
            ],
            self::ACTION_CREATE_USER => [
                'SELECT USER, HOST FROM mysql.user WHERE USER = ' . $this->mysqlString($username) . ' AND HOST = ' . $this->mysqlString($host) . ';',
            ],
            self::ACTION_GRANT_USER => [
                'SHOW GRANTS FOR ' . $account . ';',
            ],
            default => [],
        };

        return $this->basePlan(
            self::DRIVER_MYSQL,
            'planned',
            (string)__('SQL 计划已生成'),
            $statements,
            $verification,
            $this->rollbackGuidance($action),
            [],
            $warnings
        );
    }

    /**
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function pgsqlPlan(string $action, string $database, string $username, string $grantMode, array $warnings): array
    {
        $databaseIdentifier = $this->pgsqlIdentifier($database);
        $roleIdentifier = $this->pgsqlIdentifier($username);

        $statements = match ($action) {
            self::ACTION_CREATE_DATABASE => [
                [
                    'label' => (string)__('创建数据库'),
                    'sql' => 'CREATE DATABASE ' . $databaseIdentifier . " ENCODING 'UTF8';",
                ],
            ],
            self::ACTION_CREATE_USER => [
                [
                    'label' => (string)__('创建角色'),
                    'sql' => 'CREATE ROLE ' . $roleIdentifier . " LOGIN PASSWORD '<profile-secret>';",
                ],
            ],
            self::ACTION_GRANT_USER => $this->pgsqlGrantStatements($databaseIdentifier, $roleIdentifier, $grantMode),
            default => [],
        };

        $verification = match ($action) {
            self::ACTION_CREATE_DATABASE => [
                'SELECT datname FROM pg_database WHERE datname = ' . $this->pgsqlString($database) . ';',
            ],
            self::ACTION_CREATE_USER => [
                'SELECT rolname FROM pg_roles WHERE rolname = ' . $this->pgsqlString($username) . ';',
            ],
            self::ACTION_GRANT_USER => [
                'SELECT datname FROM pg_database WHERE datname = ' . $this->pgsqlString($database) . ';',
                'SELECT rolname FROM pg_roles WHERE rolname = ' . $this->pgsqlString($username) . ';',
            ],
            default => [],
        };

        return $this->basePlan(
            self::DRIVER_PGSQL,
            'planned',
            (string)__('SQL 计划已生成'),
            $statements,
            $verification,
            $this->rollbackGuidance($action),
            [],
            $warnings
        );
    }

    /**
     * @param array<int, array<string, string>> $statements
     * @param array<int, string> $verificationQueries
     * @param array<int, string> $rollbackGuidance
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private function basePlan(
        string $driver,
        string $state,
        string $stateLabel,
        array $statements,
        array $verificationQueries,
        array $rollbackGuidance,
        array $errors,
        array $warnings
    ): array {
        return [
            'state' => $state,
            'state_label' => $stateLabel,
            'adapter_label' => $driver !== '' ? $driver . ' lifecycle adapter' : '-',
            'execution_label' => (string)__('仅预览，不执行 SQL'),
            'confirmation_phrase' => 'RUN_DB_LIFECYCLE',
            'audit_event' => 'db.lifecycle.' . ($state === 'planned' ? 'plan_ready' : 'blocked'),
            'statements' => $statements,
            'verification_queries' => $verificationQueries,
            'rollback_guidance' => $rollbackGuidance,
            'errors' => \array_values($errors),
            'warnings' => \array_values($warnings),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function pgsqlGrantStatements(string $databaseIdentifier, string $roleIdentifier, string $grantMode): array
    {
        if ($grantMode === 'schema_owner') {
            return [
                [
                    'label' => (string)__('数据库权限'),
                    'sql' => 'GRANT ALL PRIVILEGES ON DATABASE ' . $databaseIdentifier . ' TO ' . $roleIdentifier . ';',
                ],
                [
                    'label' => (string)__('Schema 权限'),
                    'sql' => 'GRANT ALL PRIVILEGES ON SCHEMA public TO ' . $roleIdentifier . ';',
                    'connection' => 'target_database',
                ],
                [
                    'label' => (string)__('表权限'),
                    'sql' => 'GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO ' . $roleIdentifier . ';',
                    'connection' => 'target_database',
                ],
                [
                    'label' => (string)__('序列权限'),
                    'sql' => 'GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO ' . $roleIdentifier . ';',
                    'connection' => 'target_database',
                ],
            ];
        }

        $tablePrivileges = $grantMode === 'read_only'
            ? 'SELECT'
            : 'SELECT, INSERT, UPDATE, DELETE';
        $sequencePrivileges = $grantMode === 'read_only'
            ? 'USAGE, SELECT'
            : 'USAGE, SELECT, UPDATE';
        $schemaPrivileges = $grantMode === 'read_only'
            ? 'USAGE'
            : 'USAGE, CREATE';

        return [
            [
                'label' => (string)__('连接权限'),
                'sql' => 'GRANT CONNECT ON DATABASE ' . $databaseIdentifier . ' TO ' . $roleIdentifier . ';',
            ],
            [
                'label' => (string)__('Schema 使用权限'),
                'sql' => 'GRANT ' . $schemaPrivileges . ' ON SCHEMA public TO ' . $roleIdentifier . ';',
                'connection' => 'target_database',
            ],
            [
                'label' => (string)__('表权限'),
                'sql' => 'GRANT ' . $tablePrivileges . ' ON ALL TABLES IN SCHEMA public TO ' . $roleIdentifier . ';',
                'connection' => 'target_database',
            ],
            [
                'label' => (string)__('序列权限'),
                'sql' => 'GRANT ' . $sequencePrivileges . ' ON ALL SEQUENCES IN SCHEMA public TO ' . $roleIdentifier . ';',
                'connection' => 'target_database',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function rollbackGuidance(string $action): array
    {
        return match ($action) {
            self::ACTION_CREATE_DATABASE => [
                (string)__('数据库删除必须走单独的破坏性确认流程。'),
                (string)__('执行前应先确认没有项目 Profile 正在使用目标数据库。'),
            ],
            self::ACTION_CREATE_USER => [
                (string)__('用户删除必须走单独的破坏性确认流程。'),
                (string)__('执行前应先确认目标用户没有被其他 Profile 或外部系统使用。'),
            ],
            self::ACTION_GRANT_USER => [
                (string)__('权限回收必须记录当前 grants 快照。'),
                (string)__('执行前应准备最小权限回退语句，并保留验证查询结果。'),
            ],
            default => [],
        };
    }

    private function normalizeDriver(string $driver): string
    {
        $driver = \strtolower(\trim($driver));
        return $driver !== '' ? $driver : self::DRIVER_MYSQL;
    }

    private function needsDatabase(string $action): bool
    {
        return \in_array($action, [self::ACTION_CREATE_DATABASE, self::ACTION_GRANT_USER], true);
    }

    private function needsUser(string $action): bool
    {
        return \in_array($action, [self::ACTION_CREATE_USER, self::ACTION_GRANT_USER], true);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $identifier) === 1;
    }

    private function isSafeUsername(string $username): bool
    {
        return \preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,62}$/', $username) === 1;
    }

    private function mysqlIdentifier(string $identifier): string
    {
        return '`' . \str_replace('`', '``', $identifier) . '`';
    }

    private function pgsqlIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }

    private function mysqlString(string $value): string
    {
        return "'" . \str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    private function pgsqlString(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    private function mysqlPrivileges(string $grantMode): string
    {
        return match ($grantMode) {
            'read_only' => 'SELECT',
            'schema_owner' => 'ALL PRIVILEGES',
            default => 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX',
        };
    }
}
