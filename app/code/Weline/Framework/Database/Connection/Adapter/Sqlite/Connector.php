<?php

declare(strict_types=1);
/**
 * 鏂囦欢淇℃伅
 * 浣滆€咃細閭逛竾鎵?
 * 缃戝悕锛氱椋庨泚椋?Aiweline)
 * 缃戠珯锛歸ww.aiweline.com/bbs.aiweline.com
 * 宸ュ叿锛歅hpStorm
 * 鏃ユ湡锛?021/6/21
 * 鏃堕棿锛?1:45
 * 鎻忚堪锛氭鏂囦欢婧愮爜鐢盇iweline锛堢鏋泚椋烇級寮€鍙戯紝璇峰嬁闅忔剰淇敼婧愮爜锛?
 */

namespace Weline\Framework\Database\Connection\Adapter\Sqlite;

use PDO;
use PDOException;
use Weline\Framework\App\Env;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect\SqliteIdentifierFormatter;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Compiler\Dialect\SqliteDialect;
use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Connection\PdoConnection;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultTableNameStrategy;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionLease;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\DatabaseRetryTimeoutException;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Helper\Standar;
use Weline\Framework\Database\Retry\RetryBudget;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;

final class Connector extends Query implements ConnectorInterface
{
    public const REBUILD_MARKER = '/* WELINE_SQLITE_REBUILD */';
    public const DDL_STATEMENT_SEPARATOR = "\n-- WELINE_DDL_STATEMENT\n";

    private const MAX_BOOTSTRAP_ATTEMPTS = 32;
    private const DEFAULT_REQUEST_BOOTSTRAP_BUDGET_MS = 50;
    private const MAX_REQUEST_BOOTSTRAP_BUDGET_MS = 150;
    private const MAX_CLI_BOOTSTRAP_BUDGET_MS = 30_000;

    public function __construct(
        private readonly ConfigProvider $configProvider
    ) {
        $identifierFormatter = new SqliteIdentifierFormatter();
        $tableStrategy = new DefaultTableNameStrategy(
            $identifierFormatter,
            $this->configProvider->getPrefix() ?: ''
        );
        parent::__construct(
            $identifierFormatter,
            $tableStrategy
        );
        $this->db_name = $this->configProvider->getDatabase();
    }

    /** Connector 鑷韩鍗虫寔鏈夎繛鎺ワ紝浣滀负 Query 浣跨敤鏃剁洿鎺ヨ繑鍥烇紝閬垮厤渚濊禆 SqlTrait 鐨?$this->connection */
    public function getConnectionInterface(): DbConnectionInterface
    {
        return $this->getWrappedConnection();
    }

    public function getConnector(): ConnectorInterface
    {
        return $this;
    }

    public function getConnection(): ConnectorInterface
    {
        return $this;
    }

    protected ?PDO $link = null;
    protected ?DbConnectionInterface $wrappedConnection = null;
    protected ?Query $query = null;
    private ?ConnectionLease $lease = null;

    private ?SqliteDialect $dialect = null;

    private function getDialect(): SqliteDialect
    {
        return $this->dialect ??= new SqliteDialect();
    }

    static function processName(string $name): string
    {
        return str_replace(['`', '"'], '', $name);
    }

    public function create(): static
    {
        if ($this->link !== null && $this->lease?->isActive()) {
            return $this;
        }
        if ($this->link !== null || $this->lease !== null) {
            $this->close();
        }

        $db_type = $this->configProvider->getDbType();
        if (!in_array($db_type, PDO::getAvailableDrivers(), true)) {
            $availableDrivers = implode(',', PDO::getAvailableDrivers());
            $installHint = PHP_OS_FAMILY === 'Windows'
                ? ' Windows: enable php_pdo_sqlite.dll and php_sqlite3.dll in php.ini.'
                : ' Linux: install/enable the pdo_sqlite and sqlite3 PHP extensions, then restart PHP.';
            throw new LinkException(__('SQLite driver is not available: %{1}. Available drivers: %{2}.%{3}', [$db_type, $availableDrivers, $installHint]));
        }

        $bootstrapBudget = null;
        $bootstrapBusyAttempts = 0;
        $bootstrapLastBusyException = null;
        $bootstrapCompletionReserveMicroseconds = 8_000;
        
        // 浠庤繛鎺ユ睜鑾峰彇杩炴帴
        $lease = ConnectionPool::acquire(
            $this->configProvider,
            function () use (
                $db_type,
                &$bootstrapBudget,
                &$bootstrapBusyAttempts,
                &$bootstrapLastBusyException,
                &$bootstrapCompletionReserveMicroseconds
            ) {
                $path = (string)($this->configProvider->getData('path') ?: $this->configProvider->getDatabase() ?: ':memory:');
                if ($path !== ':memory:') {
                    $dir = dirname($path);
                    if ($dir !== '' && $dir !== '.' && !is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                        throw new LinkException(__('SQLite database directory is not writable: %{1}', [$dir]));
                    }
                }
                $dsn = "{$db_type}:{$path}";
                $options = $this->configProvider->getOptions();
                $connection = new PDO($dsn, null, null, $options);
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $bootstrapBudget = $this->newBootstrapBudget();

                // PDO SQLite's native busy handler blocks the whole PHP/WLS
                // thread. Disable it before any file-touching bootstrap SQL;
                // all busy/locked waits below are owned by one RetryBudget.
                $this->disableNativeBootstrapBusyTimeout($connection);
                $this->executeBootstrapOperation(
                    static fn() => $connection->exec('PRAGMA case_sensitive_like = OFF;'),
                    $bootstrapBudget,
                    $bootstrapBusyAttempts,
                    $bootstrapLastBusyException,
                    $bootstrapCompletionReserveMicroseconds
                );
                $this->executeBootstrapOperation(
                    static fn() => $connection->exec('PRAGMA foreign_keys = ON;'),
                    $bootstrapBudget,
                    $bootstrapBusyAttempts,
                    $bootstrapLastBusyException,
                    $bootstrapCompletionReserveMicroseconds
                );

                $preSql = $this->normalizeBootstrapPreSql($this->configProvider->getPreSql());
                if ($preSql !== '') {
                    // Execute one statement at a time so a busy failure never
                    // replays an earlier statement that already committed.
                    foreach ($this->splitSqlStatements($preSql) as $statement) {
                        $this->executeBootstrapOperation(
                            static fn() => $connection->exec($statement),
                            $bootstrapBudget,
                            $bootstrapBusyAttempts,
                            $bootstrapLastBusyException,
                            $bootstrapCompletionReserveMicroseconds
                        );
                    }
                }

                // A legacy pre_sql may contain PRAGMA busy_timeout=N. Ensure
                // no pooled connection can retain a native blocking handler.
                $this->disableNativeBootstrapBusyTimeout($connection);
                return $connection;
            }
        );
        $this->lease = $lease;
        $this->link = $lease->getConnection();
        try {
            $bootstrapBudget ??= $this->newBootstrapBudget();
            try {
                $this->disableNativeBootstrapBusyTimeout($this->link);
                $version = (string)$this->executeBootstrapOperation(
                    fn() => $this->link->query('SELECT sqlite_version()')->fetchColumn(),
                    $bootstrapBudget,
                    $bootstrapBusyAttempts,
                    $bootstrapLastBusyException,
                    $bootstrapCompletionReserveMicroseconds
                );
                $this->getDialect()->validateVersion($version);
            } catch (DatabaseRetryTimeoutException $e) {
                throw $e;
            } catch (PDOException $e) {
                // Busy/locked is already a structured timeout; every other
                // PDO failure keeps its original type and stack.
                throw $e;
            } catch (\Throwable $e) {
                w_log_warning(__('SQLite 鐗堟湰鏍￠獙鏈€氳繃锛堣繛鎺ュ凡寤虹珛锛屽崌绾у彲缁х画锛夛細%{1}', [$e->getMessage()]), [], 'database_version.log');
            }
            $this->wrappedConnection = new PdoConnection($this->link, 'sqlite');
        } catch (\Throwable $e) {
            $this->discardCurrentConnection();
            throw $e;
        }
        return $this;
    }

    /**
     * Run one bootstrap operation inside the immutable connection deadline.
     * Native SQLite waiting is disabled before this method is entered, so a
     * busy/locked call returns immediately and can yield cooperatively in WLS.
     */
    private function executeBootstrapOperation(
        callable $operation,
        RetryBudget $budget,
        int &$busyAttempts,
        ?PDOException &$lastBusyException,
        int &$completionReserveMicroseconds
    ): mixed
    {
        $stageAttempts = 0;

        while ($busyAttempts < self::MAX_BOOTSTRAP_ATTEMPTS) {
            if ($budget->remainingMicroseconds() <= $completionReserveMicroseconds) {
                throw $this->newBootstrapTimeoutException(
                    'deadline_exhausted',
                    $busyAttempts,
                    $budget,
                    $lastBusyException
                );
            }

            $stageAttempts++;
            $attemptStartedAtNanoseconds = (float)\hrtime(true);
            try {
                return $operation();
            } catch (PDOException $e) {
                if (!$this->isDatabaseLockedError($e)) {
                    throw $e;
                }

                $lastBusyException = $e;
                $busyAttempts++;
                $lastAttemptMicroseconds = (int)\max(
                    1,
                    \ceil(((float)\hrtime(true) - $attemptStartedAtNanoseconds) / 1_000)
                );
                $completionReserveMicroseconds = \max(
                    8_000,
                    ($lastAttemptMicroseconds * 2) + 2_000
                );
                if ($budget->isExpired()) {
                    throw $this->newBootstrapTimeoutException(
                        'deadline_exhausted',
                        $busyAttempts,
                        $budget,
                        $e
                    );
                }
                if ($busyAttempts >= self::MAX_BOOTSTRAP_ATTEMPTS) {
                    throw $this->newBootstrapTimeoutException(
                        'attempt_limit',
                        $busyAttempts,
                        $budget,
                        $e
                    );
                }

                $this->waitBeforeRetry(
                    $stageAttempts,
                    $budget,
                    $e,
                    $completionReserveMicroseconds
                );
            }
        }

        throw new \LogicException('SQLite bootstrap retry loop terminated without a result.');
    }

    private function disableNativeBootstrapBusyTimeout(PDO $connection): void
    {
        $connection->exec('PRAGMA busy_timeout = 0');
    }

    private function normalizeBootstrapPreSql(string $preSql): string
    {
        $preSql = \trim($preSql);
        if ($preSql === '') {
            return '';
        }

        // Preserve legacy PRAGMA position while forcing a non-blocking handler
        // before any later journal/schema statement in the same pre_sql batch.
        $normalized = \preg_replace_callback(
            '/(\bPRAGMA\s+(?:[a-zA-Z0-9_]+\.)?busy_timeout\s*(?:=|\()\s*)\d+(\s*\)?)/i',
            static fn(array $matches): string => $matches[1] . '0' . $matches[2],
            $preSql
        );
        if ($normalized === null) {
            throw new \RuntimeException(
                'Unable to normalize SQLite bootstrap pre_sql: ' . \preg_last_error_msg()
            );
        }

        return $normalized;
    }

    private function newBootstrapBudget(): RetryBudget
    {
        $requestBudget = (int)Env::get(
            'db.retry.sqlite.request_budget_ms',
            self::DEFAULT_REQUEST_BOOTSTRAP_BUDGET_MS
        );
        $requestBudget = \max(1, \min(self::MAX_REQUEST_BOOTSTRAP_BUDGET_MS, $requestBudget));

        if (!Runtime::isCli()) {
            return RetryBudget::fromMilliseconds($requestBudget);
        }

        $configuredCliBudget = Env::get('db.retry.sqlite.cli_budget_ms', null);
        if ($configuredCliBudget === null || $configuredCliBudget === '') {
            return RetryBudget::fromMilliseconds($requestBudget);
        }

        return RetryBudget::fromMilliseconds(
            \max(1, \min(self::MAX_CLI_BOOTSTRAP_BUDGET_MS, (int)$configuredCliBudget))
        );
    }

    private function newBootstrapTimeoutException(
        string $reason,
        int $attempts,
        RetryBudget $budget,
        ?PDOException $previous
    ): DatabaseRetryTimeoutException
    {
        $cooperativeWaitAvailable = !Runtime::isPersistent()
            || (SchedulerSystem::isSchedulerActive() && \Fiber::getCurrent() instanceof \Fiber);

        return new DatabaseRetryTimeoutException(
            driver: 'sqlite',
            reason: $reason,
            attempts: $attempts,
            budgetMilliseconds: $budget->budgetMilliseconds(),
            elapsedMilliseconds: $budget->elapsedMilliseconds(),
            cooperativeWaitAvailable: $cooperativeWaitAvailable,
            previous: $previous
        );
    }

    public function getWrappedConnection(): DbConnectionInterface
    {
        $this->create();
        if ($this->wrappedConnection === null) {
            $this->wrappedConnection = new PdoConnection($this->link, 'sqlite');
        }
        return $this->wrappedConnection;
    }

    public function query(string $sql): QueryInterface
    {
        $this->create();
        return parent::query($sql);
    }

    public function close(): void
    {
        $lease = $this->detachCurrentConnection();
        $lease?->release();
    }

    private function discardCurrentConnection(): void
    {
        $lease = $this->detachCurrentConnection();
        $lease?->discard();
    }

    private function detachCurrentConnection(): ?ConnectionLease
    {
        $lease = $this->lease;
        $this->lease = null;
        $this->link = null;
        $this->wrappedConnection = null;
        return $lease;
    }

    public function __clone()
    {
        // Clones are query objects, not aliases for a checked-out PDO.
        $this->lease = null;
        $this->link = null;
        $this->wrappedConnection = null;
        $this->query = null;
        $this->PDOStatement = null;
    }

    /**
     * 鏋愭瀯鍑芥暟锛氱‘淇濊繛鎺ュ湪浣跨敤鍚庤褰掕繕鍒拌繛鎺ユ睜
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @deprecated 璇蜂娇鐢?getWrappedConnection() 鑾峰彇杩炴帴骞惰皟鐢ㄥ叾鏂规硶锛屽悗缁増鏈彲鑳界Щ闄?
     */
    public function getLink(): PDO
    {
        $this->create();
        return $this->link;
    }

    /**
     * 浣跨敤 SQLite 鍘熺敓 REINDEX 閲嶅缓琛ㄧ储寮曪紙@since SQLite 3.45+锛?
     */
    public function reindex(string $table): bool
    {
        $table = self::processName($table);
        if (str_contains($table, '.')) {
            $parts = explode('.', $table, 2);
            $table = $parts[1] ?? $table;
        }
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        try {
            $this->getConnectionInterface()->execute('REINDEX ' . $quoted);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getIndexFields(string $table): array
    {
        $table = $this->resolveSqliteTable($table);
        // 鑾峰彇琛ㄧ殑绱㈠紩鍒楄〃
        $indexList = $this->query("PRAGMA index_list('$table')")->fetch();

        $indexFields = [];

        foreach ($indexList as $index) {
            // 鑾峰彇绱㈠紩鐨勮缁嗕俊鎭?
            $indexInfo = $this->query("PRAGMA index_info('{$index['name']}')")->fetch();

            foreach ($indexInfo as $info) {
                $indexFields[] = [
                    'Table' => $table,
                    'Non_unique' => $index['unique'] ? 0 : 1,
                    'Key_name' => $index['name'],
                    'Seq_in_index' => $info['seqno'],
                    'Column_name' => $info['name'],
                    'Collation' => 'A', // SQLite 榛樿浣跨敤浜岃繘鍒舵帓搴?
                ];
            }
        }

        return $indexFields;
    }

    public function dev()
    {
        return "
# 鏌ヨ琛ㄧ殑绱㈠紩瀛楁骞舵嫾鎺ユ垚绱㈠紩閲嶅缓SQL
SET @rebuild_indexer_schema = 'weline';
SET @rebuild_indexer_table = 'm_contact';
SET @rebuild_indexer_sql = '';

SELECT GROUP_CONCAT(index_field.rebuild_field_sql)
INTO @rebuild_indexer_sql
FROM (SELECT--   i.TABLE_NAME,
--   i.INDEX_NAME,
--   GROUP_CONCAT( i.COLUMN_NAME ) AS COLUMN_NAME,
            CONCAT(
                    ' DROP ',
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' PRIMARY KEY ', ' INDEX '),
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' ', i.INDEX_NAME),
                    ' , ADD ',
                    IF
                    (i.NON_UNIQUE = '0', IF(i.INDEX_NAME = 'PRIMARY', ' ', ' UNIQUE '), ''),
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' PRIMARY KEY ', ' INDEX '),
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' ', i.INDEX_NAME),
                    '(',
                    GROUP_CONCAT('`', i.COLUMN_NAME, '`'), IF(i.COLLATION = 'A', ' ASC ', ' DESC '),
                    ')',
                    ' COMMENT \'',
                    i.INDEX_COMMENT,
                    '\' USING ',
                    i.INDEX_TYPE
            ) AS rebuild_field_sql
      FROM INFORMATION_SCHEMA.STATISTICS i
      WHERE i.TABLE_SCHEMA = @rebuild_indexer_schema
        AND i.TABLE_NAME = @rebuild_indexer_table
      GROUP BY i.INDEX_NAME
      ORDER BY i.SEQ_IN_INDEX)
         AS index_field;
SELECT CONCAT('ALTER TABLE `', @rebuild_indexer_schema, '`.`', @rebuild_indexer_table, '`',
              @rebuild_indexer_sql) AS rebuild_indexer_sql;";
    }

    /**
     * @DESC          # 璇诲彇鍒涘缓琛⊿QL
     *
     * @AUTH    绉嬫灚闆侀
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:08
     * 鍙傛暟鍖猴細
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getCreateTableSql(string $table_name): string
    {
        $table_name = $this->resolveSqliteTable($table_name);
        // 鑾峰彇琛ㄧ殑鍏冩暟鎹?
        $tableMeta = $this->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table_name'")->fetch();

        if ($tableMeta === false) {
            throw new \Exception("Table '$table_name' does not exist.");
        }
        $createSql = trim((string)($tableMeta[0]['sql'] ?? ''));
        if ($createSql === '') {
            return '';
        }
        $statement = $this->getWrappedConnection()->prepare(
            "SELECT sql FROM sqlite_master WHERE tbl_name = :table AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name"
        );
        $statement->execute([':table' => $table_name]);
        $statements = [$createSql];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $sql) {
            $sql = trim((string)$sql);
            if ($sql !== '') {
                $statements[] = $sql;
            }
        }
        return implode(self::DDL_STATEMENT_SEPARATOR, $statements);
    }

    public function getConfigProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Table\Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Table\Alter::class)->setConnection($this);
    }

    public function dropTableIfExists(string $table): void
    {
        $quoted = $this->quoteTable($this->resolveSqliteTable($table));
        $this->query("DROP TABLE IF EXISTS {$quoted}")->fetch();
    }

    public function tableExist(string $table_name): bool
    {
        $table_name = $this->resolveSqliteTable($table_name);
        try {
            $res = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table_name}'; ")->fetch();
            if (empty($res)) {
                return false;
            }
            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /** @inheritDoc */
    public function getExistingTables(array $tableNames): array
    {
        $physicalToLookup = [];
        foreach ($tableNames as $input) {
            $lookup = trim(str_replace(['`', '"'], '', (string)$input));
            if (str_contains($lookup, '.')) {
                $parts = explode('.', $lookup);
                $lookup = trim((string)end($parts));
            }
            if ($lookup !== '') {
                $physicalToLookup[$this->resolveSqliteTable((string)$input)][] = $lookup;
            }
        }
        if ($physicalToLookup === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($physicalToLookup), '?'));
        $statement = $this->getWrappedConnection()->prepare(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ({$placeholders})"
        );
        $statement->execute(array_keys($physicalToLookup));
        $existing = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $physical) {
            foreach ($physicalToLookup[(string)$physical] ?? [] as $lookup) {
                $existing[] = $lookup;
            }
        }
        return array_values(array_unique($existing));
    }

    public function getVersion(): string
    {
        // 鏌ヨ鏁版嵁搴撶増鏈彿
        return $this->link->getAttribute(PDO::ATTR_CLIENT_VERSION);
    }

    public function hasField(string $table, string $field): bool
    {
        $table = $this->resolveSqliteTable($table);
        $field = self::processName($field);
        $sql = "SELECT name FROM pragma_table_info('{$table}') WHERE name LIKE '{$field}';";
        $res = $this->query($sql)->fetch();
        return (bool)$res;
    }

    public function hasIndex(string $table, string $idx_name): bool
    {
        $table = $this->resolveSqliteTable($table);
        $idx_name = self::processName($idx_name);
        $standardName = Standar::getIndexName($this->formatTableName($table), $idx_name);
        $statement = $this->getWrappedConnection()->prepare(
            "SELECT name FROM pragma_index_list(:table) WHERE name IN (:raw, :standard) LIMIT 1"
        );
        $statement->execute([':table' => $table, ':raw' => $idx_name, ':standard' => $standardName]);
        return $statement->fetchColumn() !== false;
    }

    public function getQuery(): QueryInterface
    {
        return $this;
    }

    /** @inheritDoc */
    public function getTableComment(string $table): string
    {
        return '';
    }

    /** @inheritDoc */
    public function getTableColumns(string $table): array
    {
        $table = $this->resolveSqliteTable($table);
        $rows = $this->query("PRAGMA table_info(" . $this->getLink()->quote($table) . ")")->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $uniqueColumns = [];
        foreach ($this->getTableIndexes($table) as $index) {
            if (!empty($index['unique']) && count((array)($index['columns'] ?? [])) === 1) {
                $uniqueColumns[(string)$index['columns'][0]] = true;
            }
        }
        $primaryKeyCount = count(array_filter($rows, static fn(array $row): bool => (int)($row['pk'] ?? 0) > 0));
        $list = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? '';
            $typeInfo = $this->normalizeSqliteColumnType((string) ($row['type'] ?? ''));
            $notnull = (int) ($row['notnull'] ?? 0);
            $pk = (int) ($row['pk'] ?? 0);
            $default = $row['dflt_value'] ?? null;
            $list[] = [
                'name' => $name,
                'type' => $typeInfo['type'],
                'length' => $typeInfo['length'],
                'nullable' => $pk > 0 ? false : $notnull === 0,
                'primary_key' => $pk > 0,
                'auto_increment' => $primaryKeyCount === 1 && $pk > 0 && in_array($typeInfo['type'], ['integer', 'int'], true),
                'default' => $this->normalizeSqliteDefault($default),
                'comment' => '',
                'unique' => isset($uniqueColumns[$name]),
            ];
        }
        return $list;
    }

    /** @return array{type:string,length:int|string|null} */
    private function normalizeSqliteColumnType(string $rawType): array
    {
        $rawType = strtolower(trim($rawType));
        if ($rawType === '') {
            return ['type' => 'text', 'length' => null];
        }
        if (preg_match('/^([a-z][a-z0-9_]*)\s*\((.+)\)$/i', $rawType, $m)) {
            $length = trim($m[2]);
            return [
                'type' => strtolower($m[1]),
                'length' => ctype_digit($length) ? (int) $length : $length,
            ];
        }
        return ['type' => $rawType, 'length' => null];
    }

    private function normalizeSqliteDefault(mixed $default): mixed
    {
        if ($default === null) {
            return null;
        }
        $value = trim((string) $default);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $value = trim(substr($value, 1, -1));
        }
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (strcasecmp($value, "datetime('now')") === 0) {
            return 'CURRENT_TIMESTAMP';
        }
        return $value;
    }

    /** @inheritDoc */
    public function getTableIndexes(string $table): array
    {
        $table = $this->resolveSqliteTable($table);
        $indexList = $this->query("PRAGMA index_list(" . $this->getLink()->quote($table) . ")")->fetchArray();
        if (!is_array($indexList)) {
            return [];
        }
        $list = [];
        foreach ($indexList as $idx) {
            $name = $idx['name'] ?? '';
            if (str_starts_with((string) $name, 'sqlite_autoindex_')) {
                continue;
            }
            $unique = (bool) ($idx['unique'] ?? false);
            $info = $this->query("PRAGMA index_info(" . $this->getLink()->quote($name) . ")")->fetchArray();
            $columns = [];
            if (is_array($info)) {
                foreach ($info as $r) {
                    $columns[] = $r['name'] ?? '';
                }
            }
            $list[] = ['name' => $name, 'columns' => $columns, 'unique' => $unique];
        }
        return $list;
    }

    /** @inheritDoc */
    public function quoteTable(string $table): string
    {
        return $this->getDialect()->quoteTable($table);
    }

    /** @inheritDoc */
    public function quoteIdentifier(string $identifier): string
    {
        return $this->getDialect()->quoteIdentifier($identifier);
    }

    /** @inheritDoc */
    public function buildAlterAddColumnSql(string $table, array $col): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $def = $this->sqliteColumnDef($col);
        return "ALTER TABLE {$t} ADD COLUMN {$def}";
    }

    /** @inheritDoc */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string
    {
        [$definitions, $suffix] = $this->sqliteTableDefinitions($table);
        $columnName = (string)($col['name'] ?? '');
        $replacement = $this->sqliteColumnDef($col);
        $hasTablePrimaryKey = false;
        foreach ($definitions as $definition) {
            if (preg_match('/^\s*(?:CONSTRAINT\s+[^\s]+\s+)?PRIMARY\s+KEY\b/i', $definition) === 1) {
                $hasTablePrimaryKey = true;
                break;
            }
        }
        if ($hasTablePrimaryKey && !empty($col['primaryKey'])) {
            $replacement = trim((string)preg_replace('/\s+PRIMARY\s+KEY(?:\s+AUTOINCREMENT)?\b/i', '', $replacement));
        }

        $replaced = false;
        foreach ($definitions as $index => $definition) {
            if (strcasecmp((string)$this->sqliteDefinitionColumnName($definition), $columnName) === 0) {
                $definitions[$index] = $replacement;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            throw new \RuntimeException(__("SQLite 表 %{1} 不存在待修改列 %{2}", [$table, $columnName]));
        }

        return $this->sqliteBuildRecreateTableSql($table, $definitions, $suffix);
    }

    /** @inheritDoc */
    public function buildAlterDropColumnSql(string $table, string $colName): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $c = $d->quoteIdentifier($colName);
        return "ALTER TABLE {$t} DROP COLUMN {$c}";
    }

    /** @inheritDoc */
    public function buildAlterTableCommentSql(string $table, string $comment): string
    {
        return '';
    }

    /** @inheritDoc */
    public function buildAddIndexSql(string $table, array $idx): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $name = $d->quoteIdentifier($idx['name'] ?? '');
        $cols = array_map(fn (string $c) => $d->quoteIdentifier($c), $idx['columns'] ?? []);
        $colList = implode(',', $cols);
        $type = strtoupper($idx['type'] ?? 'INDEX');
        if ($type === 'UNIQUE') {
            return "CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$t} ({$colList})";
        }
        return "CREATE INDEX IF NOT EXISTS {$name} ON {$t} ({$colList})";
    }

    /** @inheritDoc */
    public function buildDropIndexSql(string $table, string $indexName): string
    {
        $n = $this->getDialect()->quoteIdentifier($indexName);
        return "DROP INDEX IF EXISTS {$n}";
    }

    /** @inheritDoc */
    public function buildAddForeignKeySql(string $table, array $fk): string
    {
        [$definitions, $suffix] = $this->sqliteTableDefinitions($table);
        $constraintName = trim((string)($fk['name'] ?? ''));
        foreach ($definitions as $definition) {
            if (strcasecmp((string)$this->sqliteForeignKeyConstraintName($definition), $constraintName) === 0) {
                return $this->sqliteBuildRecreateTableSql($table, $definitions, $suffix);
            }
        }

        $d = $this->getDialect();
        $columns = array_map(fn(string $column): string => $d->quoteIdentifier($column), (array)($fk['columns'] ?? []));
        $referenceColumns = array_map(
            fn(string $column): string => $d->quoteIdentifier($column),
            (array)($fk['referencesColumns'] ?? [])
        );
        if ($constraintName === '' || $columns === [] || $referenceColumns === []) {
            throw new \InvalidArgumentException(__('SQLite 外键定义不完整'));
        }
        $referenceTable = $this->formatTableName((string)($fk['referencesTable'] ?? ''));
        $definition = 'CONSTRAINT ' . $d->quoteIdentifier($constraintName)
            . ' FOREIGN KEY (' . implode(',', $columns) . ')'
            . ' REFERENCES ' . $referenceTable . ' (' . implode(',', $referenceColumns) . ')';
        if (!empty($fk['onDeleteCascade'])) {
            $definition .= ' ON DELETE CASCADE';
        }
        if (!empty($fk['onUpdateCascade'])) {
            $definition .= ' ON UPDATE CASCADE';
        }
        $definitions[] = $definition;

        return $this->sqliteBuildRecreateTableSql($table, $definitions, $suffix);
    }

    /** @inheritDoc */
    public function buildDropForeignKeySql(string $table, string $fkName): string
    {
        [$definitions, $suffix] = $this->sqliteTableDefinitions($table);
        $removed = false;
        foreach ($definitions as $index => $definition) {
            if (strcasecmp((string)$this->sqliteForeignKeyConstraintName($definition), $fkName) === 0) {
                unset($definitions[$index]);
                $removed = true;
                break;
            }
        }

        // 旧 SQLite 表可能没有保存约束名，DbSchemaReader 会以 fk_<id> 表示。
        if (!$removed && preg_match('/^fk_(\d+)$/', $fkName, $matches) === 1) {
            $foreignKeys = $this->getTableForeignKeys($table);
            $target = null;
            foreach ($foreignKeys as $foreignKey) {
                if (($foreignKey['name'] ?? '') === $fkName) {
                    $target = $foreignKey;
                    break;
                }
            }
            if (is_array($target)) {
                foreach ($definitions as $index => $definition) {
                    if ($this->sqliteForeignKeyDefinitionMatches($definition, $target)) {
                        unset($definitions[$index]);
                        break;
                    }
                }
            }
        }

        return $this->sqliteBuildRecreateTableSql($table, array_values($definitions), $suffix);
    }

    /** @return array{0:list<string>,1:string} */
    private function sqliteTableDefinitions(string $table): array
    {
        $createSql = $this->getCreateTableSql(self::processName($table));
        if (str_contains($createSql, self::DDL_STATEMENT_SEPARATOR)) {
            $createSql = explode(self::DDL_STATEMENT_SEPARATOR, $createSql, 2)[0];
        }
        $open = strpos($createSql, '(');
        $close = strrpos($createSql, ')');
        if ($open === false || $close === false || $close <= $open) {
            throw new \RuntimeException(__('SQLite 表结构无法解析: %{1}', $table));
        }

        return [
            $this->sqliteSplitDefinitions(substr($createSql, $open + 1, $close - $open - 1)),
            trim(substr($createSql, $close + 1)),
        ];
    }

    /** @return list<string> */
    private function sqliteSplitDefinitions(string $body): array
    {
        $definitions = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($body);
        for ($index = 0; $index < $length; $index++) {
            $char = $body[$index];
            if ($quote !== null) {
                $buffer .= $char;
                $endQuote = $quote === '[' ? ']' : $quote;
                if ($char === $endQuote) {
                    if ($quote !== '[' && $index + 1 < $length && $body[$index + 1] === $endQuote) {
                        $buffer .= $body[++$index];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (in_array($char, ["'", '"', '`', '['], true)) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($char === ',' && $depth === 0) {
                if (trim($buffer) !== '') {
                    $definitions[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $definitions[] = trim($buffer);
        }
        return $definitions;
    }

    private function sqliteDefinitionColumnName(string $definition): ?string
    {
        if (preg_match('/^\s*(?:CONSTRAINT|PRIMARY|UNIQUE|CHECK|FOREIGN)\b/i', $definition) === 1) {
            return null;
        }
        if (preg_match('/^\s*(?:"((?:""|[^"])*)"|`((?:``|[^`])*)`|\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*))/', $definition, $matches) !== 1) {
            return null;
        }
        $name = $matches[1] !== '' ? str_replace('""', '"', $matches[1])
            : ($matches[2] !== '' ? str_replace('``', '`', $matches[2])
                : ($matches[3] !== '' ? $matches[3] : $matches[4]));
        return $name !== '' ? $name : null;
    }

    private function sqliteForeignKeyConstraintName(string $definition): ?string
    {
        if (preg_match('/^\s*CONSTRAINT\s+(?:"((?:""|[^"])*)"|`((?:``|[^`])*)`|\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*))\s+FOREIGN\s+KEY\b/i', $definition, $matches) !== 1) {
            return null;
        }
        return $matches[1] !== '' ? str_replace('""', '"', $matches[1])
            : ($matches[2] !== '' ? str_replace('``', '`', $matches[2])
                : ($matches[3] !== '' ? $matches[3] : $matches[4]));
    }

    /** @param array<string,mixed> $foreignKey */
    private function sqliteForeignKeyDefinitionMatches(string $definition, array $foreignKey): bool
    {
        if (stripos($definition, 'FOREIGN KEY') === false) {
            return false;
        }
        $normalized = strtolower(str_replace(['`', '"', '[', ']', ' ', "\n", "\r", "\t"], '', $definition));
        $columns = strtolower(implode(',', (array)($foreignKey['columns'] ?? [])));
        $referenceTable = strtolower(self::processName((string)($foreignKey['ref_table'] ?? '')));
        $referenceColumns = strtolower(implode(',', (array)($foreignKey['ref_columns'] ?? [])));
        return str_contains($normalized, 'foreignkey(' . $columns . ')')
            && str_contains($normalized, 'references' . $referenceTable . '(' . $referenceColumns . ')');
    }

    /** @param list<string> $definitions */
    private function sqliteBuildRecreateTableSql(string $table, array $definitions, string $suffix): string
    {
        $rawTable = $this->resolveSqliteTable($table);
        $quotedTable = $this->quoteIdentifier($rawTable);
        $temporary = $rawTable . '__weline_rebuild_' . bin2hex(random_bytes(6));
        $quotedTemporary = $this->quoteIdentifier($temporary);
        $columns = [];
        foreach ($this->getTableColumns($rawTable) as $column) {
            $name = trim((string)($column['name'] ?? $column['Field'] ?? ''));
            if ($name !== '') {
                $columns[] = $name;
            }
        }
        if ($columns === []) {
            throw new \RuntimeException(__('SQLite 表 %{1} 没有可复制列', $table));
        }
        $quotedColumns = array_map(fn(string $column): string => $this->quoteIdentifier($column), $columns);
        $suffixSql = $suffix !== '' ? ' ' . rtrim($suffix, ';') : '';
        $statements = [
            "CREATE TABLE {$quotedTemporary} (\n  " . implode(",\n  ", $definitions) . "\n){$suffixSql}",
            "INSERT INTO {$quotedTemporary} (" . implode(',', $quotedColumns) . ") SELECT " . implode(',', $quotedColumns) . " FROM {$quotedTable}",
            "DROP TABLE {$quotedTable}",
            "ALTER TABLE {$quotedTemporary} RENAME TO {$quotedTable}",
        ];

        $connection = $this->getWrappedConnection();
        $statement = $connection->prepare(
            "SELECT type, name, sql FROM sqlite_master WHERE tbl_name = :table AND type IN ('index', 'trigger') AND sql IS NOT NULL ORDER BY type, name"
        );
        $statement->execute([':table' => $rawTable]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $schemaObject) {
            $sql = trim((string)($schemaObject['sql'] ?? ''));
            if ($sql !== '') {
                $statements[] = rtrim($sql, ';');
            }
        }

        return self::REBUILD_MARKER . "\n" . implode(self::DDL_STATEMENT_SEPARATOR, $statements);
    }

    private function sqliteColumnDef(array $col): string
    {
        $c = $this->getDialect()->quoteIdentifier($col['name'] ?? '');
        $type = strtoupper($col['type'] ?? 'TEXT');
        $len = $col['length'] ?? null;
        $sqliteAutoIncrementPrimary = !empty($col['autoIncrement']) && !empty($col['primaryKey']);
        // SQLite only permits AUTOINCREMENT on the exact token INTEGER PRIMARY KEY.
        $typeLen = $sqliteAutoIncrementPrimary ? 'INTEGER' : ($len ? "{$type}({$len})" : $type);
        $opts = [];
        if (!empty($col['primaryKey'])) {
            $opts[] = 'PRIMARY KEY';
        }
        if (!empty($col['autoIncrement']) && !empty($col['primaryKey'])) {
            $opts[] = 'AUTOINCREMENT';
        }
        if (empty($col['nullable']) && empty($col['primaryKey'])) {
            $opts[] = 'NOT NULL';
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $d = $col['default'];
            $opts[] = is_string($d) && strtoupper($d) === 'CURRENT_TIMESTAMP'
                ? "DEFAULT (datetime('now'))"
                : (is_string($d) ? "DEFAULT '" . str_replace("'", "''", $d) . "'" : "DEFAULT {$d}");
        }
        if (!empty($col['unique']) && empty($col['primaryKey'])) {
            $opts[] = 'UNIQUE';
        }
        $optStr = implode(' ', $opts);
        return "{$c} {$typeLen} {$optStr}";
    }

    /** @inheritDoc */
    public function getTableForeignKeys(string $table): array
    {
        $table = $this->resolveSqliteTable($table);
        $rows = $this->query("PRAGMA foreign_key_list(" . $this->getLink()->quote($table) . ")")->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $grouped = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $sequence = (int)($row['seq'] ?? 0);
            $grouped[$id] ??= [
                'name' => 'fk_' . $id,
                'columns' => [],
                'ref_table' => (string)($row['table'] ?? ''),
                'ref_columns' => [],
                'on_delete_cascade' => strtoupper((string)($row['on_delete'] ?? '')) === 'CASCADE',
                'on_update_cascade' => strtoupper((string)($row['on_update'] ?? '')) === 'CASCADE',
            ];
            $grouped[$id]['columns'][$sequence] = (string)($row['from'] ?? '');
            $grouped[$id]['ref_columns'][$sequence] = (string)($row['to'] ?? '');
        }
        [$definitions] = $this->sqliteTableDefinitions($table);
        foreach ($grouped as $id => &$foreignKey) {
            ksort($foreignKey['columns']);
            ksort($foreignKey['ref_columns']);
            $foreignKey['columns'] = array_values($foreignKey['columns']);
            $foreignKey['ref_columns'] = array_values($foreignKey['ref_columns']);
            foreach ($definitions as $definition) {
                $constraintName = $this->sqliteForeignKeyConstraintName($definition);
                if ($constraintName !== null && $this->sqliteForeignKeyDefinitionMatches($definition, $foreignKey)) {
                    $foreignKey['name'] = $constraintName;
                    break;
                }
            }
        }
        unset($foreignKey);
        return array_values($grouped);
    }

    /** @inheritDoc */
    public function getDefaultTableAdditional(): string
    {
        return '';
    }

    private function resolveSqliteTable(string $table): string
    {
        $formatted = self::processName($this->formatTableName($table));
        if (str_contains($formatted, '.')) {
            $parts = explode('.', $formatted);
            return trim((string)end($parts));
        }
        return trim($formatted);
    }
}
