<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2025/01/XX
 * 时间：11:45
 * 描述：PostgreSQL 数据库连接适配器
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

use PDO;
use PDOException;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlIdentifierFormatter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlTableNameStrategy;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Alter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalTableChangeInterface;
use Weline\Framework\Database\Connection\Api\AtomicPhysicalViewChangeInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalTableIdentityProviderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableKeysetReaderInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalTableSnapshotInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewIdentity;
use Weline\Framework\Database\Connection\Api\PhysicalViewMetadataInterface;
use Weline\Framework\Database\Connection\Api\PhysicalViewSnapshotInterface;
use Weline\Framework\Database\Compiler\Dialect\PgsqlDialect;
use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Connection\PdoConnection;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\CreatesTableFromSchemaTrait;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionLease;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\TransactionContext;

final class Connector extends Query implements
    ConnectorInterface,
    PhysicalTableMetadataInterface,
    AtomicPhysicalTableChangeInterface,
    PhysicalTableKeysetReaderInterface,
    PhysicalTableIdentityProviderInterface,
    PhysicalTableSnapshotInterface,
    PhysicalViewMetadataInterface,
    AtomicPhysicalViewChangeInterface,
    PhysicalViewSnapshotInterface
{
    use CreatesTableFromSchemaTrait;

    private PgsqlTableNameStrategy $tableStrategy;

    public function __construct(
        private readonly ?ConfigProvider $configProvider
    ) {
        $identifierFormatter = new PgsqlIdentifierFormatter();
        $this->tableStrategy = new PgsqlTableNameStrategy(
            $identifierFormatter,
            $this->configProvider->getPrefix() ?: '',
            'public'
        );
        parent::__construct(
            $identifierFormatter,
            $this->tableStrategy
        );
        $this->db_name = $this->configProvider->getDatabase() ?: 'public';
    }

    /** Connector 自身即持有连接，作为 Query 使用时直接返回，避免依赖 SqlTrait 的 $this->connection */
    public function getConnectionInterface(): DbConnectionInterface
    {
        return $this->getWrappedConnection();
    }

    /**
     * SqlTrait 依赖 $this->connection；本类为真实连接器，未设置该属性。
     * 若不覆盖，调用 getConnector()/getConnection() 会误报「连接未设置」。
     */
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
    protected ?PDO $_original_pdo = null; // 原始 PDO 引用，用于克隆后的对象访问

    private ?PgsqlDialect $dialect = null;

    private function getDialect(): PgsqlDialect
    {
        return $this->dialect ??= new PgsqlDialect();
    }

    public function create(): static
    {
        if ($this->link !== null && $this->lease?->isActive()) {
            // 🔧 修复：如果连接已存在，确保引用也被设置
            $this->_original_pdo = $this->link;
            return $this;
        }
        if ($this->link !== null || $this->lease !== null) {
            $this->close();
        }

        $db_type = $this->configProvider->getDbType();
        $availableDrivers = PDO::getAvailableDrivers();
        if (!in_array($db_type, $availableDrivers, true)) {
            $installHint = PHP_OS_FAMILY === 'Windows'
                ? 'Windows: enable php_pdo_pgsql.dll and php_pgsql.dll in php.ini.'
                : 'Linux: run "php bin/w env:install", or install/enable the pdo_pgsql PHP extension, then restart PHP.';
            throw new LinkException(sprintf(
                'PostgreSQL driver is not available: %s. Available drivers: %s. %s',
                $db_type,
                implode(',', $availableDrivers),
                $installHint,
            ));
        }

        // 从连接池获取连接
        $lease = ConnectionPool::acquire(
            $this->configProvider,
            function () {
                // PostgreSQL DSN 格式: pgsql:host=hostname;port=5432;dbname=database;user=username;password=password
                $dsn = "pgsql:host={$this->configProvider->getHostName()};port={$this->configProvider->getHostPort()};dbname={$this->configProvider->getDatabase()}";
                if ($this->configProvider->getCharset()) {
                    $dsn .= ";options='--client_encoding={$this->configProvider->getCharset()}'";
                }
                
                try {
                    $connection = new PDO($dsn, $this->configProvider->getUsername(), $this->configProvider->getPassword(), $this->configProvider->getOptions());
                    // 确保错误模式设置为异常模式（即使配置中已设置，这里也明确设置一次，确保生效）
                    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    if ($this->configProvider->getPreSql()) {
                        $connection->exec($this->configProvider->getPreSql());
                    }
                    // 设置字符集
                    if ($this->configProvider->getCharset()) {
                        $connection->exec("SET NAMES '{$this->configProvider->getCharset()}'");
                    }
                    return $connection;
                } catch (PDOException $e) {
                    throw new LinkException($e->getMessage());
                }
            }
        );
        $this->lease = $lease;
        $this->link = $lease->getConnection();
        $this->_original_pdo = $this->link;
        try {
            // 初始化 SchemaConfig（统一管理 schema）
            SchemaConfig::setPdo($this->link);

            // 设置 PDO 到 TableNameStrategy，使其能够动态获取 current_schema
            $this->tableStrategy->setPdo($this->link);

            $serverVersion = (string)$this->link->getAttribute(PDO::ATTR_SERVER_VERSION);
            try {
                $this->getDialect()->validateVersion($serverVersion);
            } catch (\Throwable $e) {
                w_log_warning(__('PostgreSQL 版本校验未通过（连接已建立，升级可继续）：%{1}', [$e->getMessage()]), [], 'database_version.log');
            }
            $this->wrappedConnection = new PdoConnection($this->link, 'pgsql');
        } catch (\Throwable $e) {
            $this->discardCurrentConnection();
            throw $e;
        }
        return $this;
    }

    public function getWrappedConnection(): DbConnectionInterface
    {
        $this->create();
        if ($this->wrappedConnection === null) {
            $this->wrappedConnection = new PdoConnection($this->link, 'pgsql');
        }
        // 确保 TableNameStrategy 有 PDO 引用
        if ($this->link !== null) {
            $this->tableStrategy->setPdo($this->link);
        }
        return $this->wrappedConnection;
    }

    protected function reconnectAfterDisconnect(\PDOException $exception): bool
    {
        unset($exception);

        $this->discardCurrentConnection();

        try {
            $this->create();
            return $this->link instanceof PDO;
        } catch (\Throwable) {
            return false;
        }
    }

    public function close(): void
    {
        $lease = $this->detachCurrentConnection();
        $lease?->release();
    }

    private function discardCurrentConnection(): void
    {
        $link = $this->link;
        $lease = $this->detachCurrentConnection();
        SchemaConfig::reset();
        if ($lease !== null) {
            $lease->discard();
        } elseif ($link instanceof PDO) {
            ConnectionPool::discardConnection($link, $this->configProvider);
        }
    }

    private function detachCurrentConnection(): ?ConnectionLease
    {
        $lease = $this->lease;
        $this->lease = null;
        $this->link = null;
        $this->_original_pdo = null;
        $this->wrappedConnection = null;
        return $lease;
    }

    public function __clone()
    {
        // The clone lazily acquires a distinct lease; it must not alias the
        // original connector's ownership token or raw PDO.
        $this->lease = null;
        $this->link = null;
        $this->_original_pdo = null;
        $this->wrappedConnection = null;
        $this->query = null;
        $this->PDOStatement = null;
    }

    /**
     * 析构函数：确保连接在使用后被归还到连接池
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @deprecated 请使用 getWrappedConnection() 获取连接并调用其方法，后续版本可能移除
     */
    public function getLink(): PDO
    {
        // Match Mysql/Sqlite: lazily (re)acquire when link is missing or lease ended.
        // requestEndCleanup may return the PDO while this process retains the Connector.
        if ($this->link === null || !$this->lease?->isActive()) {
            $this->create();
        }
        if ($this->link === null) {
            throw new LinkException(__('数据库连接未初始化'));
        }

        // 🔧 修复：直接返回原始 PDO 对象，不再使用包装器
        // SQL 转换逻辑已移到 Query 类中处理
        return $this->link;
    }

    public function reindex(string $table): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        if (empty($schema)) {
            $schema = SchemaConfig::getCurrentSchema();
        }

        // PostgreSQL 重建索引
        $sql = "REINDEX TABLE \"{$schema}\".\"{$table}\"";
        try {
            $this->query($sql)->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getIndexFields(string $table): array
    {
        $table = str_replace(['`', '"'], '', $table);
        $schema = SchemaConfig::getCurrentSchema();
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        
        // PostgreSQL 查询索引信息
        $sql = <<<SQL
SELECT 
    i.relname AS "Key_name",
    a.attname AS "Column_name",
    ix.indisunique AS "Non_unique",
    a.attnum AS "Seq_in_index",
    CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS "Non_unique"
FROM 
    pg_class t,
    pg_class i,
    pg_index ix,
    pg_attribute a,
    pg_namespace n
WHERE 
    t.oid = ix.indrelid
    AND i.oid = ix.indexrelid
    AND a.attrelid = t.oid
    AND a.attnum = ANY(ix.indkey)
    AND t.relkind = 'r'
    AND n.oid = t.relnamespace
    AND n.nspname = '{$schema}'
    AND t.relname = '{$table}'
ORDER BY 
    i.relname, a.attnum
SQL;
        
        $result = $this->query($sql)->fetchArray();
        return $result ?? [];
    }

    /**
     * @DESC          # 读取创建表SQL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:08
     * 参数区：
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getCreateTableSql(string $table_name): string
    {
        [$schema, $table] = $this->parseSchemaTable($this->formatTableName($table_name));
        return $this->buildPhysicalCreateTableSql($schema, $table);
    }

    public function getPhysicalCreateTableSql(PhysicalTableIdentity $identity): string
    {
        return $this->buildPhysicalCreateTableSql($identity->namespace(), $identity->table());
    }

    private function buildPhysicalCreateTableSql(string $schema, string $table): string
    {
        $connection = $this->getWrappedConnection();
        $columnStatement = $connection->prepare(<<<'SQL'
SELECT
    a.attname,
    pg_catalog.format_type(a.atttypid, a.atttypmod) AS formatted_type,
    a.attnotnull,
    a.attidentity,
    a.attgenerated,
    pg_get_expr(ad.adbin, ad.adrelid) AS default_expr,
    col_description(a.attrelid, a.attnum) AS column_comment
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
LEFT JOIN pg_catalog.pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
WHERE n.nspname = :schema
  AND c.relname = :table
  AND c.relkind IN ('r', 'p')
  AND a.attnum > 0
  AND NOT a.attisdropped
ORDER BY a.attnum
SQL);
        $columnStatement->execute([':schema' => $schema, ':table' => $table]);
        $columns = $columnStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($columns === []) {
            return '';
        }

        $definitions = [];
        $columnComments = [];
        foreach ($columns as $column) {
            $type = (string)($column['formatted_type'] ?? 'text');
            $default = trim((string)($column['default_expr'] ?? ''));
            $identity = (string)($column['attidentity'] ?? '');
            $generated = (string)($column['attgenerated'] ?? '');
            if ($identity === '' && preg_match('/^nextval\(/i', $default) === 1) {
                $normalizedType = strtolower($type);
                $type = match ($normalizedType) {
                    'bigint' => 'bigserial',
                    'smallint' => 'smallserial',
                    default => 'serial',
                };
                $default = '';
            }
            $definition = $this->quoteIdentifier((string)$column['attname']) . ' ' . $type;
            if ($identity !== '') {
                $definition .= $identity === 'a'
                    ? ' GENERATED ALWAYS AS IDENTITY'
                    : ' GENERATED BY DEFAULT AS IDENTITY';
            } elseif ($generated !== '') {
                $definition .= ' GENERATED ALWAYS AS (' . $default . ') STORED';
                $default = '';
            }
            if ($default !== '') {
                $definition .= ' DEFAULT ' . $default;
            }
            if (!empty($column['attnotnull'])) {
                $definition .= ' NOT NULL';
            }
            $definitions[] = $definition;
            $comment = $column['column_comment'] ?? null;
            if (is_string($comment) && $comment !== '') {
                $columnComments[(string)$column['attname']] = $comment;
            }
        }

        $constraintStatement = $connection->prepare(<<<'SQL'
SELECT conname, pg_get_constraintdef(oid, true) AS definition
FROM pg_catalog.pg_constraint
WHERE conrelid = to_regclass(:qualified)
ORDER BY CASE contype WHEN 'p' THEN 0 WHEN 'u' THEN 1 WHEN 'c' THEN 2 WHEN 'f' THEN 3 ELSE 4 END, conname
SQL);
        $qualifiedLookup = $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
        $constraintStatement->execute([':qualified' => $qualifiedLookup]);
        foreach ($constraintStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $constraint) {
            $definition = trim((string)($constraint['definition'] ?? ''));
            if ($definition !== '') {
                $definitions[] = 'CONSTRAINT ' . $this->quoteIdentifier((string)$constraint['conname']) . ' ' . $definition;
            }
        }

        $statements = [
            'CREATE TABLE ' . $qualifiedLookup . " (\n    " . implode(",\n    ", $definitions) . "\n)",
        ];
        $indexStatement = $connection->prepare(<<<'SQL'
SELECT indexdef
FROM pg_catalog.pg_indexes i
WHERE i.schemaname = :schema
  AND i.tablename = :table
  AND NOT EXISTS (
      SELECT 1
      FROM pg_catalog.pg_constraint c
      WHERE c.conindid = (quote_ident(i.schemaname) || '.' || quote_ident(i.indexname))::regclass
  )
ORDER BY indexname
SQL);
        $indexStatement->execute([':schema' => $schema, ':table' => $table]);
        foreach ($indexStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $index) {
            $definition = trim((string)($index['indexdef'] ?? ''));
            if ($definition !== '') {
                $statements[] = $definition;
            }
        }

        $triggerStatement = $connection->prepare(<<<'SQL'
SELECT pg_get_triggerdef(t.oid, true) AS definition
FROM pg_catalog.pg_trigger t
WHERE t.tgrelid = to_regclass(:qualified) AND NOT t.tgisinternal
ORDER BY t.tgname
SQL);
        $triggerStatement->execute([':qualified' => $qualifiedLookup]);
        foreach ($triggerStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $trigger) {
            $definition = trim((string)($trigger['definition'] ?? ''));
            if ($definition !== '') {
                $statements[] = $definition;
            }
        }

        $commentStatement = $connection->prepare(<<<'SQL'
SELECT obj_description(c.oid, 'pg_class') AS table_comment
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = :schema AND c.relname = :table
SQL);
        $commentStatement->execute([':schema' => $schema, ':table' => $table]);
        $tableComment = $commentStatement->fetchColumn();
        if (is_string($tableComment) && $tableComment !== '') {
            $statements[] = 'COMMENT ON TABLE ' . $qualifiedLookup . ' IS ' . $this->getLink()->quote($tableComment);
        }
        foreach ($columnComments as $columnName => $comment) {
            $statements[] = 'COMMENT ON COLUMN ' . $qualifiedLookup . '.' . $this->quoteIdentifier($columnName)
                . ' IS ' . $this->getLink()->quote($comment);
        }

        return implode("\n-- WELINE_DDL_STATEMENT\n", $statements);
    }

    public function getConfigProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Alter::class)->setConnection($this);
    }

    /** @inheritDoc 方言：PostgreSQL 使用 CASCADE 自动清理依赖 */
    public function dropTableIfExists(string $table): void
    {
        // 使用 formatTableName 来处理表名（会自动添加前缀和 schema）
        $formattedTable = $this->formatTableName($table);
        $this->query("DROP TABLE IF EXISTS {$formattedTable} CASCADE")->fetch();
    }

    public function quotePhysicalTable(PhysicalTableIdentity $identity): string
    {
        return $this->quoteTable($identity->canonical());
    }

    public function resolvePhysicalTableIdentity(string $logicalName): PhysicalTableIdentity
    {
        [$schema, $table] = $this->parseSchemaTable($this->formatTableName($logicalName));
        return new PhysicalTableIdentity($schema, $table);
    }

    public function physicalTableExists(PhysicalTableIdentity $identity): bool
    {
        $statement = $this->getWrappedConnection()->prepare(
            'SELECT EXISTS ('
            . 'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = :schema AND table_name = :table'
            . ')',
        );
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':table' => $identity->table(),
        ]);
        return (bool)$statement->fetchColumn();
    }

    public function dropPhysicalTableIfExists(PhysicalTableIdentity $identity): void
    {
        $this->query('DROP TABLE IF EXISTS ' . $this->quotePhysicalTable($identity) . ' CASCADE')->fetch();
    }

    public function capturePhysicalTableSnapshot(PhysicalTableIdentity $identity): array
    {
        if (!$this->physicalTableExists($identity)) {
            return [
                'format' => 'weline.pg.table_snapshot.v1',
                'existed' => false,
                'ddl' => '',
                'columns' => [],
                'sequences' => [],
            ];
        }
        $statement = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT
    a.attname,
    a.attidentity,
    a.attgenerated,
    pg_catalog.pg_get_serial_sequence(
        pg_catalog.format('%I.%I', n.nspname, c.relname),
        a.attname
    ) AS sequence_name
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
WHERE n.nspname = :schema
  AND c.relname = :table
  AND c.relkind IN ('r', 'p')
  AND a.attnum > 0
  AND NOT a.attisdropped
ORDER BY a.attnum
SQL);
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':table' => $identity->table(),
        ]);
        $columns = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $sequences = [];
        foreach ($columns as $column) {
            $sequenceName = trim((string)($column['sequence_name'] ?? ''));
            if ($sequenceName === '') {
                continue;
            }
            $catalog = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT
    s.seqstart,
    s.seqincrement,
    s.seqmin,
    s.seqmax,
    s.seqcache,
    s.seqcycle
FROM pg_catalog.pg_sequence s
WHERE s.seqrelid = pg_catalog.to_regclass(:sequence)
SQL);
            $catalog->execute([':sequence' => $sequenceName]);
            $options = $catalog->fetch(PDO::FETCH_ASSOC);
            if (!is_array($options)) {
                throw new \RuntimeException('physical identity sequence catalog unavailable');
            }
            $state = $this->getWrappedConnection()->getPdo()->query(
                'SELECT last_value, is_called FROM ' . $this->quoteTable($sequenceName),
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state)) {
                throw new \RuntimeException('physical identity sequence state unavailable');
            }
            $sequences[] = [
                'column' => (string)$column['attname'],
                'name' => $sequenceName,
                'start' => (int)$options['seqstart'],
                'increment' => (int)$options['seqincrement'],
                'min' => (int)$options['seqmin'],
                'max' => (int)$options['seqmax'],
                'cache' => (int)$options['seqcache'],
                'cycle' => $this->pgsqlBoolean($options['seqcycle']),
                'last_value' => (int)$state['last_value'],
                'is_called' => $this->pgsqlBoolean($state['is_called']),
            ];
        }
        return [
            'format' => 'weline.pg.table_snapshot.v1',
            'existed' => true,
            'ddl' => $this->getPhysicalCreateTableSql($identity),
            'columns' => array_map(
                static fn(array $column): array => [
                    'name' => (string)$column['attname'],
                    'identity' => (string)($column['attidentity'] ?? ''),
                    'generated' => (string)($column['attgenerated'] ?? ''),
                ],
                $columns,
            ),
            'sequences' => $sequences,
        ];
    }

    public function restorePhysicalTableSnapshot(
        PhysicalTableIdentity $identity,
        array $snapshot,
    ): void {
        if (($snapshot['format'] ?? null) !== 'weline.pg.table_snapshot.v1'
            || ($snapshot['existed'] ?? null) !== true
            || trim((string)($snapshot['ddl'] ?? '')) === '') {
            throw new \RuntimeException('physical table snapshot payload is invalid');
        }
        if ($this->physicalTableExists($identity)) {
            throw new \RuntimeException('physical table snapshot target already exists');
        }
        foreach ($this->splitPhysicalSnapshotDdl((string)$snapshot['ddl']) as $statement) {
            $this->query($statement)->fetch();
        }
        if (!$this->physicalTableExists($identity)) {
            throw new \RuntimeException('physical table snapshot restore did not create target');
        }
        foreach ((array)($snapshot['sequences'] ?? []) as $sequence) {
            if (!is_array($sequence)) {
                throw new \RuntimeException('physical identity sequence snapshot is invalid');
            }
            $current = $this->physicalIdentitySequenceName(
                $identity,
                (string)($sequence['column'] ?? ''),
            );
            $this->query(
                'ALTER SEQUENCE ' . $this->quoteTable($current)
                . ' RESTART WITH ' . (int)($sequence['start'] ?? 0),
            )->fetch();
            $cycle = !empty($sequence['cycle']) ? ' CYCLE' : ' NO CYCLE';
            $this->query(
                'ALTER SEQUENCE ' . $this->quoteTable($current)
                . ' START WITH ' . (int)($sequence['start'] ?? 0)
                . ' INCREMENT BY ' . (int)($sequence['increment'] ?? 0)
                . ' MINVALUE ' . (int)($sequence['min'] ?? 0)
                . ' MAXVALUE ' . (int)($sequence['max'] ?? 0)
                . ' CACHE ' . (int)($sequence['cache'] ?? 0)
                . $cycle,
            )->fetch();
        }
    }

    public function insertPhysicalTableSnapshotRows(
        PhysicalTableIdentity $identity,
        array $rows,
        array $snapshot,
    ): void {
        if (($snapshot['format'] ?? null) !== 'weline.pg.table_snapshot.v1') {
            throw new \RuntimeException('physical table snapshot payload is invalid');
        }
        $generated = [];
        $alwaysIdentity = [];
        foreach ((array)($snapshot['columns'] ?? []) as $column) {
            if (!is_array($column)) {
                continue;
            }
            $name = (string)($column['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ((string)($column['generated'] ?? '') !== '') {
                $generated[$name] = true;
            }
            if ((string)($column['identity'] ?? '') === 'a') {
                $alwaysIdentity[$name] = true;
            }
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('physical table snapshot row is invalid');
            }
            $row = array_diff_key($row, $generated);
            if ($row === []) {
                $this->query('INSERT INTO ' . $this->quotePhysicalTable($identity) . ' DEFAULT VALUES')->fetch();
                continue;
            }
            $columns = array_keys($row);
            $placeholders = [];
            $parameters = [];
            foreach (array_values($columns) as $index => $column) {
                $placeholder = ':snapshot_value_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $row[$column];
            }
            $override = array_intersect_key($row, $alwaysIdentity) !== []
                ? ' OVERRIDING SYSTEM VALUE'
                : '';
            $sql = 'INSERT INTO ' . $this->quotePhysicalTable($identity)
                . ' (' . implode(', ', array_map($this->quoteIdentifier(...), $columns)) . ')'
                . $override . ' VALUES (' . implode(', ', $placeholders) . ')';
            $statement = $this->getWrappedConnection()->prepare($sql);
            if (!$statement->execute($parameters)) {
                throw new \RuntimeException('physical table snapshot row restore failed');
            }
        }
    }

    public function finalizePhysicalTableSnapshotRestore(
        PhysicalTableIdentity $identity,
        array $snapshot,
    ): void {
        foreach ((array)($snapshot['sequences'] ?? []) as $sequence) {
            if (!is_array($sequence)) {
                throw new \RuntimeException('physical identity sequence snapshot is invalid');
            }
            $current = $this->physicalIdentitySequenceName(
                $identity,
                (string)($sequence['column'] ?? ''),
            );
            $statement = $this->getWrappedConnection()->prepare(
                'SELECT pg_catalog.setval(pg_catalog.to_regclass(:sequence), :value, :called)',
            );
            $statement->bindValue(':sequence', $current, PDO::PARAM_STR);
            $statement->bindValue(':value', (int)($sequence['last_value'] ?? 0), PDO::PARAM_INT);
            $statement->bindValue(':called', !empty($sequence['is_called']), PDO::PARAM_BOOL);
            if (!$statement->execute()) {
                throw new \RuntimeException('physical identity sequence state restore failed');
            }
        }
    }

    public function physicalTableCatalogFingerprint(PhysicalTableIdentity $identity): string
    {
        $snapshot = $this->capturePhysicalTableSnapshot($identity);
        foreach ((array)($snapshot['sequences'] ?? []) as $index => $sequence) {
            if (is_array($sequence)) {
                unset($sequence['last_value'], $sequence['is_called']);
                $snapshot['sequences'][$index] = $sequence;
            }
        }
        return hash('sha256', $this->canonicalSnapshotJson($snapshot));
    }

    public function atomicPhysicalTableChange(
        PhysicalTableIdentity $identity,
        callable $callback,
    ): mixed {
        $entryState = TransactionContext::transactionState($this);
        $transaction = $entryState?->ownerQuery() ?? $this->getQuery();
        $transaction->clearQuery();
        $entryDepth = $entryState?->depth() ?? 0;
        $transaction->beginTransaction();
        try {
            $timeoutStatement = $this->getWrappedConnection()->prepare('SHOW lock_timeout');
            $timeoutStatement->execute();
            $previousLockTimeout = (string)$timeoutStatement->fetchColumn();
            $this->query("SET LOCAL lock_timeout = '5000ms'")->fetch();
            $advisory = $this->getWrappedConnection()->prepare(
                'SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(:identity, 0))',
            );
            $advisory->execute([':identity' => 'weline:table:' . $identity->canonical()]);
            if ($this->physicalTableExists($identity)) {
                $this->query(
                    'LOCK TABLE ' . $this->quotePhysicalTable($identity) . ' IN ACCESS EXCLUSIVE MODE',
                )->fetch();
            }

            $transactions = ObjectManager::getInstance(TransactionCoordinator::class);
            $result = $transactions->withSavepoint(
                ConnectionFactory::getInstance($this->configProvider),
                'atomic_physical_table_change_' . $identity->canonical(),
                fn(): mixed => $callback($this),
            );

            $verification = TransactionContext::transactionState($this);
            if ($verification === null
                || $verification->ownerQuery() !== $transaction
                || $verification->depth() !== $entryDepth + 1
                || $verification->isRollbackOnly()
                || $verification->pdoObjectId() !== spl_object_id($this->getWrappedConnection()->getPdo())) {
                throw new \RuntimeException('atomic physical table callback changed transaction ownership');
            }
            $this->query(
                'SET LOCAL lock_timeout = ' . $this->getWrappedConnection()->getPdo()->quote($previousLockTimeout),
            )->fetch();
            $transaction->commit();
            return $result;
        } catch (\Throwable $failure) {
            try {
                $this->rollbackAtomicTransactionLayers($transaction, $entryDepth);
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'atomic physical table change rollback failed',
                    0,
                    $rollbackFailure,
                );
            }
            throw $failure;
        } finally {
            $transaction->clearQuery();
        }
    }

    public function quotePhysicalView(PhysicalViewIdentity $identity): string
    {
        return $this->quoteTable($identity->canonical());
    }

    public function physicalViewExists(PhysicalViewIdentity $identity): bool
    {
        $statement = $this->getWrappedConnection()->prepare(
            'SELECT EXISTS ('
            . 'SELECT 1 FROM pg_catalog.pg_class c '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :schema AND c.relname = :view AND c.relkind = 'v'"
            . ')',
        );
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        return (bool)$statement->fetchColumn();
    }

    public function getPhysicalViewDefinition(PhysicalViewIdentity $identity): string
    {
        $statement = $this->getWrappedConnection()->prepare(
            'SELECT pg_catalog.pg_get_viewdef(c.oid, true) '
            . 'FROM pg_catalog.pg_class c '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :schema AND c.relname = :view AND c.relkind = 'v'",
        );
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        $definition = trim((string)$statement->fetchColumn());
        if ($definition === '') {
            throw new \RuntimeException('exact physical view definition unavailable');
        }
        return rtrim($definition, "; \t\n\r\0\x0B");
    }

    public function createOrReplacePhysicalView(
        PhysicalViewIdentity $identity,
        string $selectSql,
        bool $replace,
    ): void {
        $selectSql = rtrim(trim($selectSql), "; \t\n\r\0\x0B");
        if (preg_match('/^(?:SELECT|WITH)\b/is', $selectSql) !== 1) {
            throw new \InvalidArgumentException('physical view definition must be SELECT or WITH');
        }
        $sql = 'CREATE ' . ($replace ? 'OR REPLACE ' : '') . 'VIEW '
            . $this->quotePhysicalView($identity) . ' AS ' . $selectSql;
        $result = $this->query($sql)->fetch();
        if ($result === false) {
            throw new \RuntimeException('exact physical view mutation failed');
        }
    }

    public function dropPhysicalViewIfExists(PhysicalViewIdentity $identity): void
    {
        $result = $this->query('DROP VIEW IF EXISTS ' . $this->quotePhysicalView($identity))->fetch();
        if ($result === false) {
            throw new \RuntimeException('exact physical view drop failed');
        }
    }

    public function capturePhysicalViewSnapshot(PhysicalViewIdentity $identity): array
    {
        if (!$this->physicalViewExists($identity)) {
            return [
                'format' => 'weline.pg.view_snapshot.v1',
                'existed' => false,
            ];
        }
        $statement = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT
    pg_catalog.pg_get_viewdef(c.oid, true) AS definition,
    pg_catalog.pg_get_userbyid(c.relowner) AS owner,
    COALESCE(pg_catalog.array_to_json(c.reloptions)::text, '[]') AS options,
    pg_catalog.obj_description(c.oid, 'pg_class') AS view_comment
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = :schema AND c.relname = :view AND c.relkind = 'v'
SQL);
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        $view = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($view)) {
            throw new \RuntimeException('physical view snapshot catalog unavailable');
        }
        $columnsStatement = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT a.attname, pg_catalog.col_description(c.oid, a.attnum) AS comment
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
WHERE n.nspname = :schema
  AND c.relname = :view
  AND c.relkind = 'v'
  AND a.attnum > 0
  AND NOT a.attisdropped
ORDER BY a.attnum
SQL);
        $columnsStatement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        $columns = $columnsStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $aclStatement = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT
    CASE WHEN acl.grantee = 0 THEN 'PUBLIC' ELSE grantee.rolname END AS grantee,
    acl.privilege_type,
    acl.is_grantable
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
CROSS JOIN LATERAL pg_catalog.aclexplode(
    COALESCE(c.relacl, pg_catalog.acldefault('r', c.relowner))
) acl
LEFT JOIN pg_catalog.pg_roles grantee ON grantee.oid = acl.grantee
WHERE n.nspname = :schema AND c.relname = :view AND c.relkind = 'v'
ORDER BY grantee, acl.privilege_type
SQL);
        $aclStatement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        $options = json_decode((string)$view['options'], true, flags: JSON_THROW_ON_ERROR);
        return [
            'format' => 'weline.pg.view_snapshot.v1',
            'existed' => true,
            'definition' => rtrim(trim((string)$view['definition']), "; \t\n\r\0\x0B"),
            'columns' => array_map(
                static fn(array $column): array => [
                    'name' => (string)$column['attname'],
                    'comment' => $column['comment'] === null ? null : (string)$column['comment'],
                ],
                $columns,
            ),
            'options' => is_array($options) ? array_values(array_map('strval', $options)) : [],
            'owner' => (string)$view['owner'],
            'acl' => array_map(
                fn(array $grant): array => [
                    'grantee' => (string)$grant['grantee'],
                    'privilege' => strtoupper((string)$grant['privilege_type']),
                    'grantable' => $this->pgsqlBoolean($grant['is_grantable']),
                ],
                $aclStatement->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ),
            'comment' => $view['view_comment'] === null ? null : (string)$view['view_comment'],
        ];
    }

    public function restorePhysicalViewSnapshot(
        PhysicalViewIdentity $identity,
        array $snapshot,
        bool $currentlyExists,
    ): void {
        if (($snapshot['format'] ?? null) !== 'weline.pg.view_snapshot.v1'
            || !array_key_exists('existed', $snapshot)) {
            throw new \RuntimeException('physical view snapshot payload is invalid');
        }
        if (($snapshot['existed'] ?? null) !== true) {
            if ($currentlyExists) {
                $this->dropPhysicalViewIfExists($identity);
            }
            return;
        }
        $definition = rtrim(trim((string)($snapshot['definition'] ?? '')), "; \t\n\r\0\x0B");
        if (preg_match('/^(?:SELECT|WITH)\b/is', $definition) !== 1) {
            throw new \RuntimeException('physical view snapshot definition is invalid');
        }
        $columns = [];
        foreach ((array)($snapshot['columns'] ?? []) as $column) {
            if (!is_array($column) || trim((string)($column['name'] ?? '')) === '') {
                throw new \RuntimeException('physical view snapshot column is invalid');
            }
            $columns[] = (string)$column['name'];
        }
        if ($columns === []) {
            throw new \RuntimeException('physical view snapshot columns are missing');
        }
        $options = $this->validatedPhysicalViewOptions((array)($snapshot['options'] ?? []));
        $sql = 'CREATE ' . ($currentlyExists ? 'OR REPLACE ' : '') . 'VIEW '
            . $this->quotePhysicalView($identity)
            . ' (' . implode(', ', array_map($this->quoteIdentifier(...), $columns)) . ')'
            . ($options === [] ? '' : ' WITH (' . implode(', ', $options) . ')')
            . ' AS ' . $definition;
        $this->query($sql)->fetch();

        $currentOptions = $this->physicalViewReloptions($identity);
        $snapshotOptionNames = [];
        foreach ($options as $option) {
            $snapshotOptionNames[strtolower((string)strtok($option, '='))] = true;
        }
        $reset = [];
        foreach ($currentOptions as $option) {
            $name = strtolower((string)strtok($option, '='));
            if ($name !== '' && !isset($snapshotOptionNames[$name])) {
                $reset[$name] = $name;
            }
        }
        if ($reset !== []) {
            $this->query(
                'ALTER VIEW ' . $this->quotePhysicalView($identity)
                . ' RESET (' . implode(', ', array_map($this->quoteIdentifier(...), array_values($reset))) . ')',
            )->fetch();
        }

        $owner = trim((string)($snapshot['owner'] ?? ''));
        if ($owner === '') {
            throw new \RuntimeException('physical view snapshot owner is missing');
        }
        $this->query(
            'ALTER VIEW ' . $this->quotePhysicalView($identity)
            . ' OWNER TO ' . $this->quoteIdentifier($owner),
        )->fetch();
        foreach ($this->physicalViewAclGrantees($identity) as $grantee) {
            $this->query(
                'REVOKE ALL PRIVILEGES ON ' . $this->quotePhysicalView($identity)
                . ' FROM ' . ($grantee === 'PUBLIC' ? 'PUBLIC' : $this->quoteIdentifier($grantee)),
            )->fetch();
        }
        foreach ((array)($snapshot['acl'] ?? []) as $grant) {
            if (!is_array($grant)) {
                throw new \RuntimeException('physical view snapshot ACL is invalid');
            }
            $grantee = (string)($grant['grantee'] ?? '');
            $privilege = strtoupper((string)($grant['privilege'] ?? ''));
            if ($grantee === ''
                || !in_array($privilege, ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'], true)) {
                throw new \RuntimeException('physical view snapshot ACL entry is invalid');
            }
            if ($grantee === $owner) {
                continue;
            }
            $this->query(
                'GRANT ' . $privilege . ' ON ' . $this->quotePhysicalView($identity)
                . ' TO ' . ($grantee === 'PUBLIC' ? 'PUBLIC' : $this->quoteIdentifier($grantee))
                . (!empty($grant['grantable']) ? ' WITH GRANT OPTION' : ''),
            )->fetch();
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        $comment = $snapshot['comment'] ?? null;
        $this->query(
            'COMMENT ON VIEW ' . $this->quotePhysicalView($identity)
            . ' IS ' . ($comment === null ? 'NULL' : $pdo->quote((string)$comment)),
        )->fetch();
        foreach ((array)$snapshot['columns'] as $column) {
            $comment = $column['comment'] ?? null;
            $this->query(
                'COMMENT ON COLUMN ' . $this->quotePhysicalView($identity) . '.'
                . $this->quoteIdentifier((string)$column['name'])
                . ' IS ' . ($comment === null ? 'NULL' : $pdo->quote((string)$comment)),
            )->fetch();
        }
    }

    public function atomicPhysicalViewChange(
        PhysicalViewIdentity $identity,
        callable $callback,
    ): mixed {
        $entryState = TransactionContext::transactionState($this);
        $transaction = $entryState?->ownerQuery() ?? $this->getQuery();
        $transaction->clearQuery();
        $entryDepth = $entryState?->depth() ?? 0;
        $transaction->beginTransaction();
        try {
            $timeoutStatement = $this->getWrappedConnection()->prepare('SHOW lock_timeout');
            $timeoutStatement->execute();
            $previousLockTimeout = (string)$timeoutStatement->fetchColumn();
            $this->query("SET LOCAL lock_timeout = '5000ms'")->fetch();

            $advisory = $this->getWrappedConnection()->prepare(
                'SELECT pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(:identity, 0))',
            );
            $advisory->execute([':identity' => 'weline:view:' . $identity->canonical()]);
            $existed = $this->physicalViewExists($identity);
            if ($existed) {
                $this->query(
                    'LOCK TABLE ' . $this->quotePhysicalView($identity) . ' IN ACCESS EXCLUSIVE MODE',
                )->fetch();
            }

            $transactions = ObjectManager::getInstance(TransactionCoordinator::class);
            $result = $transactions->withSavepoint(
                ConnectionFactory::getInstance($this->configProvider),
                'atomic_physical_view_change_' . $identity->canonical(),
                fn(): mixed => $callback($this, $existed),
            );

            $verification = TransactionContext::transactionState($this);
            if ($verification === null
                || $verification->ownerQuery() !== $transaction
                || $verification->depth() !== $entryDepth + 1
                || $verification->isRollbackOnly()
                || $verification->pdoObjectId() !== spl_object_id($this->getWrappedConnection()->getPdo())) {
                throw new \RuntimeException('atomic physical view callback changed transaction ownership');
            }
            $this->query(
                'SET LOCAL lock_timeout = ' . $this->getWrappedConnection()->getPdo()->quote($previousLockTimeout),
            )->fetch();
            $transaction->commit();
            return $result;
        } catch (\Throwable $failure) {
            try {
                $this->rollbackAtomicTransactionLayers($transaction, $entryDepth);
            } catch (\Throwable $rollbackFailure) {
                throw new \RuntimeException(
                    'atomic physical view change rollback failed',
                    0,
                    $rollbackFailure,
                );
            }
            throw $failure;
        } finally {
            $transaction->clearQuery();
        }
    }

    /** @return list<string> */
    private function splitPhysicalSnapshotDdl(string $ddl): array
    {
        $parts = str_contains($ddl, "\n-- WELINE_DDL_STATEMENT\n")
            ? explode("\n-- WELINE_DDL_STATEMENT\n", $ddl)
            : [$ddl];
        return array_values(array_filter(
            array_map('trim', $parts),
            static fn(string $statement): bool => $statement !== '',
        ));
    }

    private function physicalIdentitySequenceName(
        PhysicalTableIdentity $identity,
        string $column,
    ): string {
        if ($column === '') {
            throw new \RuntimeException('physical identity sequence column is missing');
        }
        $statement = $this->getWrappedConnection()->prepare(
            'SELECT pg_catalog.pg_get_serial_sequence(:table, :column)',
        );
        $statement->execute([
            ':table' => $identity->canonical(),
            ':column' => $column,
        ]);
        $sequence = trim((string)$statement->fetchColumn());
        if ($sequence === '') {
            throw new \RuntimeException('physical identity sequence is missing after restore');
        }
        return $sequence;
    }

    private function canonicalSnapshotJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return (string)json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<mixed> $options @return list<string> */
    private function validatedPhysicalViewOptions(array $options): array
    {
        $validated = [];
        foreach ($options as $option) {
            $option = strtolower(trim((string)$option));
            if (preg_match(
                '/\A(?:security_barrier|security_invoker)=(?:true|false)\z|\Acheck_option=(?:local|cascaded)\z/D',
                $option,
            ) !== 1) {
                throw new \RuntimeException('physical view snapshot option is invalid');
            }
            $validated[$option] = $option;
        }
        ksort($validated, SORT_STRING);
        return array_values($validated);
    }

    /** @return list<string> */
    private function physicalViewReloptions(PhysicalViewIdentity $identity): array
    {
        $statement = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT COALESCE(pg_catalog.array_to_json(c.reloptions)::text, '[]')
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = :schema AND c.relname = :view AND c.relkind = 'v'
SQL);
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        $options = json_decode((string)$statement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        return is_array($options) ? array_values(array_map('strval', $options)) : [];
    }

    /** @return list<string> */
    private function physicalViewAclGrantees(PhysicalViewIdentity $identity): array
    {
        $statement = $this->getWrappedConnection()->prepare(<<<'SQL'
SELECT DISTINCT CASE WHEN acl.grantee = 0 THEN 'PUBLIC' ELSE grantee.rolname END AS grantee
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
CROSS JOIN LATERAL pg_catalog.aclexplode(
    COALESCE(c.relacl, pg_catalog.acldefault('r', c.relowner))
) acl
LEFT JOIN pg_catalog.pg_roles grantee ON grantee.oid = acl.grantee
WHERE n.nspname = :schema AND c.relname = :view AND c.relkind = 'v'
SQL);
        $statement->execute([
            ':schema' => $identity->namespace(),
            ':view' => $identity->view(),
        ]);
        return array_values(array_filter(
            array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn(string $grantee): bool => $grantee !== '',
        ));
    }

    private function rollbackAtomicTransactionLayers(QueryInterface $transaction, int $entryDepth): void
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $state = TransactionContext::transactionState($this);
            if ($state === null) {
                if ($entryDepth > 0) {
                    throw new \RuntimeException('atomic physical table transaction ownership lost');
                }
                return;
            }
            if ($state->ownerQuery() !== $transaction) {
                throw new \RuntimeException('atomic physical table transaction owner changed');
            }
            if ($state->depth() <= $entryDepth) {
                return;
            }
            $transaction->rollBack();
        }
        throw new \RuntimeException('atomic physical table transaction depth did not unwind');
    }

    public function readPhysicalTableKeysetChunk(
        PhysicalTableIdentity $identity,
        array $primaryKeyColumns,
        ?array $afterPrimaryKey,
        int $limit,
    ): array {
        if ($primaryKeyColumns === [] || $limit <= 0) {
            throw new \InvalidArgumentException('invalid physical table keyset request');
        }
        $metadata = $this->getPhysicalTableColumns($identity);
        $declaredPrimaryKeys = [];
        foreach ($metadata as $column) {
            if (!empty($column['primary_key'])) {
                $declaredPrimaryKeys[] = (string)($column['name'] ?? '');
            }
        }
        if ($declaredPrimaryKeys !== array_values($primaryKeyColumns)) {
            throw new \RuntimeException('physical table keyset does not match complete primary key');
        }
        if ($afterPrimaryKey !== null && array_keys($afterPrimaryKey) !== $primaryKeyColumns) {
            throw new \InvalidArgumentException('physical table keyset cursor mismatch');
        }
        if ($afterPrimaryKey !== null && in_array(null, $afterPrimaryKey, true)) {
            throw new \RuntimeException('physical table primary key cursor contains null');
        }

        $quotedKeys = array_map(
            fn(string $column): string => $this->quoteIdentifier($column),
            $primaryKeyColumns,
        );
        $sql = 'SELECT * FROM ' . $this->quotePhysicalTable($identity);
        $parameters = [];
        if ($afterPrimaryKey !== null) {
            $placeholders = [];
            foreach ($primaryKeyColumns as $index => $column) {
                $placeholder = ':physical_key_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $afterPrimaryKey[$column];
            }
            $sql .= ' WHERE (' . implode(', ', $quotedKeys) . ') > ('
                . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY ' . implode(', ', $quotedKeys) . ' ASC LIMIT ' . $limit;
        $statement = $this->getWrappedConnection()->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function tableExist(string $table_name): bool
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                // 使用 formatTableName 来处理表名（会自动添加前缀和 schema）
                $formattedTableName = $this->formatTableName($table_name);

                // 从格式化后的表名中提取 schema 和 table
                // 格式: "schema"."table"
                $formattedTableName = str_replace(['"'], '', $formattedTableName);

                $schema = SchemaConfig::getCurrentSchema();
                $table = $table_name;

                if (str_contains($formattedTableName, '.')) {
                    $parts = explode('.', $formattedTableName, 2);
                    $schema = $parts[0];
                    $table = $parts[1] ?? $parts[0];
                } else {
                    $table = $formattedTableName;
                    // 如果没有 schema，使用 current_schema()
                    try {
                        $currentSchema = $this->getLink()->query('SELECT current_schema()')->fetchColumn();
                        $schema = $currentSchema ?: 'public';
                    } catch (\Throwable $e) {
                        $schema = SchemaConfig::getCurrentSchema();
                    }
                }

                // 使用 prepared statement 避免 SQL 注入，并确保不会报错
                $sql = "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table)";
                $stmt = $this->getLink()->prepare($sql);
                if ($stmt === false) {
                    return false;
                }

                // 使用 @ 抑制可能的警告，然后检查执行结果
                $executed = @$stmt->execute([
                    ':schema' => $schema,
                    ':table' => $table
                ]);

                if (!$executed) {
                    return false;
                }

                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                return (bool)($result['exists'] ?? false);
            } catch (\Throwable $throwable) {
                // A process-scoped owner can retain one PDO across many WLS
                // requests. If that physical connection dies while idle, the
                // pool cannot validate it through the idle queue. This probe
                // is read-only and has not executed any DDL, so outside a
                // coordinated transaction it may discard the dead lease and
                // retry once on a fresh PostgreSQL connection.
                if ($attempt === 0
                    && $throwable instanceof PDOException
                    && TransactionContext::transactionState($this) === null
                    && ConnectionPool::isDisconnectException($throwable)
                    && $this->reconnectAfterDisconnect($throwable)
                ) {
                    continue;
                }

                // tableExist remains a compatibility boolean probe. Every
                // non-disconnect error and every transaction-bound disconnect
                // continues to fail closed as "not proven to exist".
                return false;
            }
        }

        return false;
    }

    /** @inheritDoc */
    public function getExistingTables(array $tableNames): array
    {
        if ($tableNames === []) {
            return [];
        }
        $grouped = [];
        foreach ($tableNames as $input) {
            $lookup = trim(str_replace(['`', '"'], '', (string)$input));
            if ($lookup === '') {
                continue;
            }
            if (str_contains($lookup, '.')) {
                $parts = explode('.', $lookup);
                $lookup = trim((string)end($parts));
            }
            [$schema, $physical] = $this->parseSchemaTable($this->formatTableName((string)$input));
            $grouped[$schema][$physical][] = $lookup;
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        $existing = [];
        try {
            foreach ($grouped as $schema => $tables) {
                $names = array_keys($tables);
                $placeholders = implode(',', array_fill(0, count($names), '?'));
                $stmt = $pdo->prepare(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name IN ({$placeholders})"
                );
                $stmt->execute(array_merge([$schema], $names));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $physical) {
                    foreach ($tables[(string)$physical] ?? [] as $lookup) {
                        $existing[] = $lookup;
                    }
                }
            }
            return array_values(array_unique($existing));
        } catch (\Throwable) {
            // 降级为逐表检查
            $existing = [];
            foreach ($tableNames as $input) {
                if (!$this->tableExist((string)$input)) {
                    continue;
                }
                $lookup = trim(str_replace(['`', '"'], '', (string)$input));
                if (str_contains($lookup, '.')) {
                    $parts = explode('.', $lookup);
                    $lookup = trim((string)end($parts));
                }
                $existing[] = $lookup;
            }
            return array_values(array_unique($existing));
        }
    }

    public function getVersion(): string
    {
        // 查询数据库版本号
        $query = 'SELECT version() AS version';
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['version'] ?? '';
    }

    public function hasField(string $table, string $field): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        $field = str_replace(['`', '"'], '', $field);

        // 使用 formatTableName 处理表名（会自动添加前缀和 schema）
        $formattedTableName = $this->formatTableName($table);

        // 从格式化后的表名中提取 schema 和 table
        // 格式: "schema"."table"
        $formattedTableName = str_replace(['"'], '', $formattedTableName);

        $schema = SchemaConfig::getCurrentSchema();
        $actualTable = $table;

        if (str_contains($formattedTableName, '.')) {
            $parts = explode('.', $formattedTableName, 2);
            $schema = $parts[0];
            $actualTable = $parts[1] ?? $parts[0];
        }

        // 转义特殊字符
        $schema = str_replace("'", "''", $schema);
        $actualTable = str_replace("'", "''", $actualTable);
        $field = str_replace("'", "''", $field);

        $sql = "SELECT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE LOWER(table_schema) = LOWER('{$schema}')
            AND LOWER(table_name) = LOWER('{$actualTable}')
            AND LOWER(column_name) = LOWER('{$field}')
        )";

        $stmt = $this->getLink()->query($sql);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (bool)($result['exists'] ?? false);
    }

    public function hasIndex(string $table, string $idx_name): bool
    {
        $formattedTable = $this->formatTableName($table);
        [$schema, $physicalTable] = $this->parseSchemaTable($formattedTable);
        $candidates = PgsqlIndexName::candidates($formattedTable, $idx_name);
        $candidatePlaceholders = [];
        $params = [':schema' => $schema, ':table' => $physicalTable];
        foreach ($candidates as $position => $candidate) {
            $placeholder = ':candidate_' . $position;
            $candidatePlaceholders[] = $placeholder;
            $params[$placeholder] = $candidate;
        }
        $statement = $this->getWrappedConnection()->prepare(
            'SELECT EXISTS ('
            . 'SELECT 1 FROM pg_catalog.pg_index ix '
            . 'JOIN pg_catalog.pg_class t ON t.oid = ix.indrelid '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace '
            . 'JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid '
            . 'WHERE n.nspname = :schema AND t.relname = :table '
            . 'AND i.relname IN (' . implode(', ', $candidatePlaceholders) . ')'
            . ')'
        );
        $statement->execute($params);
        return (bool)$statement->fetchColumn();
    }

    public function query(string $sql): QueryInterface
    {
        if (!$this->link) {
            $this->create();
        }
        return parent::query($sql);
    }

    public function getQuery(): QueryInterface
    {
        return $this;
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

    /**
     * @inheritDoc
     * PostgreSQL: 向非空表添加 NOT NULL 列时，需提供 DEFAULT，否则报 "contains null values"。
     * 若模型未声明 default，按类型生成临时默认值（varchar→''，int→0），以通过 ADD COLUMN。
     */
    public function buildAlterAddColumnSql(string $table, array $col): string
    {
        $dialect = $this->getDialect();
        $t = $dialect->quoteTable($table);
        $c = $dialect->quoteIdentifier($col['name'] ?? '');
        $type = $this->pgsqlTypeFromCol($col);
        $clauses = [$c . ' ' . $type];
        $nullable = !empty($col['nullable']);
        $hasDefault = isset($col['default']) && $col['default'] !== null;
        $isSerial = (!empty($col['autoIncrement']) || !empty($col['primaryKey']))
            && in_array(strtolower($col['type'] ?? ''), ['int', 'integer', 'bigint', 'smallint', 'tinyint'], true);
        if ($hasDefault) {
            $clauses[] = 'DEFAULT ' . $this->pgsqlDeclaredDefaultLiteral($col, $col['default']);
        } elseif (!$nullable && !$isSerial) {
            $baseType = strtolower($col['type'] ?? 'varchar');
            $clauses[] = match (true) {
                in_array($baseType, ['varchar', 'char', 'text', 'longtext', 'mediumtext', 'tinytext'], true) => "DEFAULT ''",
                in_array($baseType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => 'DEFAULT 0',
                in_array($baseType, ['decimal', 'numeric', 'float', 'double'], true) => 'DEFAULT 0',
                $baseType === 'bool' || $baseType === 'boolean' => 'DEFAULT false',
                $baseType === 'date' => "DEFAULT '1970-01-01'",
                // 避免部分 PostgreSQL 环境对文本时间字面量触发时区解析异常（如 p00:p00）。
                in_array($baseType, ['datetime', 'timestamp', 'timestamptz'], true) => 'DEFAULT CURRENT_TIMESTAMP',
                default => "DEFAULT ''",
            };
        }
        if (!$nullable) {
            $clauses[] = 'NOT NULL';
        }
        return "ALTER TABLE {$t} ADD COLUMN " . implode(' ', $clauses);
    }

    /**
     * @inheritDoc
     * PostgreSQL: 设置 NOT NULL 前需先将现有 NULL 填充为默认值，否则报 "contains null values"。
     * 类型变更时，UPDATE 填充值必须与当前列类型兼容，使用 $existingCol 生成兼容值。
     */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $c = $d->quoteIdentifier($col['name'] ?? '');
        $type = $this->pgsqlTypeFromCol($col, true); // ALTER COLUMN 不支持 SERIAL 伪类型，使用 INTEGER/BIGINT
        $usingExpr = $this->pgsqlModifyColumnUsingExpr($c, $type, $col, $existingCol);
        $parts = ["ALTER COLUMN {$c} TYPE {$type} USING {$usingExpr}"];
        $setNotNull = empty($col['nullable']);
        // PostgreSQL refuses TYPE changes while an incompatible column DEFAULT remains.
        // Drop it first, then re-apply the declared default after the type cast.
        $prefix = "ALTER TABLE {$t} ALTER COLUMN {$c} DROP DEFAULT;\n";
        if ($setNotNull) {
            // Prefer declared default for NULL fill; empty-string on existingCol is "no default".
            $fillCol = $col;
            $declaredDefault = $col['default'] ?? null;
            if ($declaredDefault === null || $declaredDefault === '') {
                $fillCol = $existingCol ?? $col;
            }
            $fillVal = $this->pgsqlDefaultForNullFill($fillCol);
            $prefix .= "UPDATE {$t} SET {$c} = {$fillVal} WHERE {$c} IS NULL;\n";
        }
        $parts[] = $setNotNull ? "ALTER COLUMN {$c} SET NOT NULL" : "ALTER COLUMN {$c} DROP NOT NULL";
        if (!empty($col['autoIncrement'])) {
            [$schema, $tableName] = $this->parseSchemaTable($table);
            $colName = (string) ($col['name'] ?? '');
            $seqName = $tableName . '_' . $colName . '_seq';
            // 使用带引号的完整标识符，避免 PostgreSQL 默认查找 public schema
            $seqRef = $d->quoteIdentifier($schema) . '.' . $d->quoteIdentifier($seqName);
            $parts[] = "ALTER COLUMN {$c} SET DEFAULT nextval('" . str_replace("'", "''", $seqRef) . "'::regclass)";
            $createSeq = 'CREATE SEQUENCE IF NOT EXISTS ' . $seqRef;
            return $prefix . $createSeq . ";\nALTER TABLE {$t} " . implode(', ', $parts) . ';'
                . "\n" . $this->pgsqlColumnCommentSql($table, $col);
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $parts[] = "ALTER COLUMN {$c} SET DEFAULT "
                . $this->pgsqlDeclaredDefaultLiteral($col, $col['default']);
        }
        return $prefix . "ALTER TABLE {$t} " . implode(', ', $parts) . ';'
            . "\n" . $this->pgsqlColumnCommentSql($table, $col);
    }

    private function pgsqlColumnCommentSql(string $table, array $col): string
    {
        $dialect = $this->getDialect();
        $target = $dialect->quoteTable($table) . '.'
            . $dialect->quoteIdentifier((string)($col['name'] ?? ''));
        $comment = (string)($col['comment'] ?? '');
        $literal = $comment === '' ? 'NULL' : "'" . str_replace("'", "''", $comment) . "'";
        return "COMMENT ON COLUMN {$target} IS {$literal};";
    }

    /** 用于 MODIFY 时填充 NULL 的默认值（按类型）。UPDATE 只能用字面量，不能用 nextval 等表达式。 */
    private function pgsqlDefaultForNullFill(array $col): string
    {
        $baseType = strtolower($col['type'] ?? 'varchar');
        if ($baseType === 'bool' || $baseType === 'boolean') {
            return $this->pgsqlDeclaredDefaultLiteral($col, $col['default'] ?? false);
        }
        $isSerial = !empty($col['autoIncrement']) || (is_string($col['default'] ?? '') && stripos((string) $col['default'], 'nextval') !== false);
        if ($isSerial || in_array($baseType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            return '0';
        }
        if (array_key_exists('default', $col) && $col['default'] !== null && $col['default'] !== '') {
            $d = $col['default'];
            if (is_string($d) && strtoupper($d) === 'CURRENT_TIMESTAMP') {
                return 'CURRENT_TIMESTAMP';
            }
            if (!is_string($d) || stripos($d, 'nextval') === false) {
                $val = is_string($d) ? $this->normalizePgStringDefaultLiteral($d) : (string) $d;
                $maxLen = isset($col['length']) ? (int) $col['length'] : null;
                if ($maxLen !== null && $maxLen > 0 && strlen($val) > $maxLen) {
                    $val = substr($val, 0, $maxLen);
                }
                return "'" . str_replace("'", "''", $val) . "'";
            }
        }
        $isDateLike = $baseType === 'date'
            || in_array($baseType, ['datetime', 'timestamp', 'timestamptz'], true)
            || str_contains($baseType, 'timestamp');
        return match (true) {
            in_array($baseType, ['varchar', 'char', 'text', 'longtext', 'mediumtext', 'tinytext'], true) => "''",
            in_array($baseType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => '0',
            in_array($baseType, ['decimal', 'numeric', 'float', 'double', 'real'], true) => '0',
            $baseType === 'bool' || $baseType === 'boolean' => 'false',
            $baseType === 'date' => "'1970-01-01'",
            $isDateLike => "'1970-01-01 00:00:00'",
            default => "''",
        };
    }

    private function pgsqlDeclaredDefaultLiteral(array $col, mixed $value): string
    {
        $baseType = strtolower((string)($col['type'] ?? 'varchar'));
        if ($baseType === 'bool' || $baseType === 'boolean') {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_int($value) || is_float($value)) {
                if ((float)$value === 0.0) {
                    return 'false';
                }
                if ((float)$value === 1.0) {
                    return 'true';
                }
                throw new \InvalidArgumentException('PostgreSQL boolean default must be 0 or 1.');
            }

            $normalized = strtolower(trim((string)$value));
            while (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
                $normalized = trim(substr($normalized, 1, -1));
            }
            $normalized = preg_replace('/::(?:bool|boolean)\z/i', '', $normalized) ?? $normalized;
            $normalized = trim($normalized, " \t\n\r\0\x0B'\"");
            return match ($normalized) {
                '1', 'true', 't', 'yes', 'on' => 'true',
                '0', 'false', 'f', 'no', 'off', '' => 'false',
                default => throw new \InvalidArgumentException('PostgreSQL boolean default is invalid.'),
            };
        }

        if (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        if (is_string($value)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        return (string)$value;
    }

    /**
     * PostgreSQL 字符串默认值可能是 `'v'::character varying(20)` 形式，
     * 这里统一归一化为纯字符串内容，避免再次拼接时把类型注解当成值写回。
     */
    private function normalizePgStringDefaultLiteral(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        // 移除外层括号：('abc'::varchar)
        while (str_starts_with($value, '(') && str_ends_with($value, ')') && strlen($value) >= 2) {
            $value = trim(substr($value, 1, -1));
        }
        // 去掉 PostgreSQL 类型 cast 后缀：'abc'::character varying(20)
        if (preg_match('/^\'((?:\'\'|[^\'])*)\'\s*::/i', $value, $m)) {
            return str_replace("''", "'", $m[1]);
        }
        // 纯字符串：'abc'
        if (preg_match('/^\'((?:\'\'|[^\'])*)\'$/', $value, $m)) {
            return str_replace("''", "'", $m[1]);
        }

        return $value;
    }

    /**
     * @inheritDoc
     * PostgreSQL: 使用 CASCADE 自动删除列上的外键、索引等依赖，避免手动 DROP CONSTRAINT 时约束名不匹配
     *（PG 自生成约束名如 tablename_columnname_fkey，与模型声明的列名可能不同）
     */
    public function buildAlterDropColumnSql(string $table, string $colName): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $c = $d->quoteIdentifier($colName);
        return "ALTER TABLE {$t} DROP COLUMN IF EXISTS {$c} CASCADE";
    }

    /** @inheritDoc */
    public function buildAlterTableCommentSql(string $table, string $comment): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $c = $comment !== '' ? "'" . str_replace("'", "''", $comment) . "'" : 'NULL';
        return "COMMENT ON TABLE {$t} IS {$c}";
    }

    /** @inheritDoc */
    public function buildAddIndexSql(string $table, array $idx): string
    {
        [$schema, $physicalTable] = $this->parseSchemaTable($table);
        $exactTable = $schema . '.' . $physicalTable;
        $requestedName = (string)($idx['name'] ?? '');
        // Existing historical raw names are normalized by SchemaDiffStage and
        // therefore never enter ADD.  Every new index is published under the
        // table-owned canonical name so an unrelated raw name cannot hijack
        // the operation between ADD and DROP ordering.
        return $this->pgsqlBuildCreateIndexSql(
            $table,
            $idx,
            PgsqlIndexName::canonicalPhysical($exactTable, $requestedName),
        );
    }

    /**
     * Build rollback DDL for a DROP whose payload came from a proven
     * target-owned physical row.  Do not canonicalize that physical name a
     * second time or rollback would publish a double-prefixed index.
     */
    public function buildRestorePhysicalIndexSql(string $table, array $idx): string
    {
        return $this->pgsqlBuildCreateIndexSql(
            $table,
            $idx,
            PgsqlIndexName::rawPhysical((string)($idx['name'] ?? '')),
        );
    }

    private function pgsqlBuildCreateIndexSql(string $table, array $idx, string $physicalName): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $requestedName = (string)($idx['name'] ?? '');
        $name = $d->quoteIdentifier($physicalName);
        $cols = array_map(fn (string $c) => $d->quoteIdentifier($c), $idx['columns'] ?? []);
        $colList = implode(',', $cols);
        $type = strtoupper($idx['type'] ?? 'INDEX');
        if ($type === 'FULLTEXT') {
            if (count($cols) !== 1) {
                throw new \RuntimeException(__(
                    'PostgreSQL 表 %{1} 的索引 %{2} 使用声明式 Schema 尚不支持的表达式、谓词或 INCLUDE 列',
                    [$t, $requestedName],
                ));
            }
            return "CREATE INDEX {$name} ON {$t} USING GIN (to_tsvector('english', {$cols[0]}))";
        }
        $usingPart = (!empty($idx['method']) && strtoupper($idx['method']) !== 'BTREE') ? ' USING ' . $idx['method'] : '';
        if ($type === 'UNIQUE') {
            return "CREATE UNIQUE INDEX {$name} ON {$t}{$usingPart} ({$colList})";
        }
        return "CREATE INDEX {$name} ON {$t}{$usingPart} ({$colList})";
    }

    /**
     * @inheritDoc
     * PostgreSQL 的索引名是 schema 级命名空间。删除前必须由 pg_index
     * 证明候选索引属于目标表；约束背书的索引只删除目标表上的对应约束。
     */
    public function buildDropIndexSql(string $table, string $indexName): string
    {
        [$schema, $physicalTable] = $this->parseSchemaTable($table);
        $exactTable = $schema . '.' . $physicalTable;
        $candidates = PgsqlIndexName::candidates($exactTable, $indexName);
        $candidatePlaceholders = [];
        $params = [':schema' => $schema, ':table' => $physicalTable];
        foreach ($candidates as $position => $candidate) {
            $placeholder = ':candidate_' . $position;
            $candidatePlaceholders[] = $placeholder;
            $params[$placeholder] = $candidate;
        }
        $statement = $this->getWrappedConnection()->prepare(
            'SELECT i.relname AS index_name, c.conname AS constraint_name '
            . 'FROM pg_catalog.pg_index ix '
            . 'JOIN pg_catalog.pg_class t ON t.oid = ix.indrelid '
            . 'JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace '
            . 'JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid '
            . 'LEFT JOIN pg_catalog.pg_constraint c ON c.conindid = i.oid AND c.conrelid = t.oid '
            . 'WHERE n.nspname = :schema AND t.relname = :table '
            . 'AND i.relname IN (' . implode(', ', $candidatePlaceholders) . ')'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rawPhysical = PgsqlIndexName::rawPhysical($indexName);
        usort($rows, static function (array $left, array $right) use ($rawPhysical): int {
            return ((string)($right['index_name'] ?? '') === $rawPhysical ? 1 : 0)
                <=> ((string)($left['index_name'] ?? '') === $rawPhysical ? 1 : 0);
        });
        $target = $rows[0] ?? null;
        if (!is_array($target)) {
            // ADD INDEX records rollback before the physical index exists.
            // The canonical name includes the target table identity, so this
            // future rollback cannot resolve to another table's raw index.
            $dialect = $this->getDialect();
            $canonical = PgsqlIndexName::canonicalPhysical($exactTable, $indexName);
            return 'DROP INDEX IF EXISTS ' . $dialect->quoteIdentifier($schema)
                . '.' . $dialect->quoteIdentifier($canonical);
        }

        $dialect = $this->getDialect();
        $targetTable = $dialect->quoteTable($schema . '.' . $physicalTable);
        $constraintName = trim((string)($target['constraint_name'] ?? ''));
        if ($constraintName !== '') {
            return 'ALTER TABLE ' . $targetTable
                . ' DROP CONSTRAINT ' . $dialect->quoteIdentifier($constraintName) . ' CASCADE';
        }

        $physicalIndex = trim((string)($target['index_name'] ?? ''));
        return 'DROP INDEX ' . $dialect->quoteIdentifier($schema)
            . '.' . $dialect->quoteIdentifier($physicalIndex);
    }

    /** @inheritDoc */
    public function buildAddForeignKeySql(string $table, array $fk): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $name = $d->quoteIdentifier($fk['name'] ?? '');
        $cols = array_map(fn (string $c) => $d->quoteIdentifier($c), $fk['columns'] ?? []);
        $refCols = array_map(fn (string $c) => $d->quoteIdentifier($c), $fk['referencesColumns'] ?? []);
        $refTable = $d->quoteTable($this->formatTableName((string)($fk['referencesTable'] ?? '')));
        $onDelete = !empty($fk['onDeleteCascade']) ? ' ON DELETE CASCADE' : '';
        $onUpdate = !empty($fk['onUpdateCascade']) ? ' ON UPDATE CASCADE' : '';
        return "ALTER TABLE {$t} ADD CONSTRAINT {$name} FOREIGN KEY (" . implode(',', $cols) . ") REFERENCES {$refTable} (" . implode(',', $refCols) . "){$onDelete}{$onUpdate}";
    }

    /**
     * @inheritDoc
     * PostgreSQL: 使用 IF EXISTS 避免约束名不匹配时报错（PG 自生成名如 tablename_columnname_fkey，
     * 与模型声明的 FK 名可能不同）；CASCADE 删除依赖该约束的对象。
     */
    public function buildDropForeignKeySql(string $table, string $fkName): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $n = $this->getDialect()->quoteIdentifier($fkName);
        return "ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$n} CASCADE";
    }

    /** MODIFY COLUMN 的 USING 表达式。varchar(n)/char(n) 时截断；date/timestamp 时需处理空字符串 ''（PostgreSQL ''::date 报错）。 */
    private function pgsqlModifyColumnUsingExpr(string $quotedCol, string $pgType, array $col, ?array $existingCol): string
    {
        $len = $col['length'] ?? null;
        if ($len !== null && $len > 0 && (str_starts_with($pgType, 'VARCHAR') || str_starts_with($pgType, 'CHAR'))) {
            return "LEFT({$quotedCol}::text, {$len})::{$pgType}";
        }
        $pgUpper = strtoupper($pgType);
        if ($pgUpper === 'DATE' || str_starts_with($pgUpper, 'DATE(')) {
            return "CASE WHEN {$quotedCol} IS NULL OR TRIM({$quotedCol}::text) = '' THEN '1970-01-01'::date ELSE ({$quotedCol}::text)::date END";
        }
        if ($pgUpper === 'TIMESTAMP' || $pgUpper === 'TIMESTAMPTZ') {
            // Cast via text → timestamp. Do NOT use `AT TIME ZONE` here: when the
            // source column is text/unknown, PostgreSQL resolves it as
            // timezone(unknown, text) and raises "function does not exist".
            $castType = $pgUpper === 'TIMESTAMPTZ' ? 'timestamptz' : 'timestamp';
            return "CASE WHEN {$quotedCol} IS NULL OR TRIM({$quotedCol}::text) = '' OR (TRIM({$quotedCol}::text) !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}') THEN '1970-01-01 00:00:00'::timestamp ELSE ({$quotedCol}::text)::{$castType} END";
        }
        return "{$quotedCol}::{$pgType}";
    }

    /**
     * @param array $col 列定义
     * @param bool $forAlterModify 若为 true，不使用 SERIAL（PostgreSQL ALTER COLUMN 不支持 SERIAL 伪类型）
     */
    private function pgsqlTypeFromCol(array $col, bool $forAlterModify = false): string
    {
        $type = strtolower($col['type'] ?? 'varchar');
        $len = $col['length'] ?? null;
        if (!$forAlterModify && !empty($col['autoIncrement']) && in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'], true)) {
            return match ($type) {
                'bigint' => 'BIGSERIAL',
                'smallint', 'tinyint' => 'SMALLSERIAL',
                default => 'SERIAL',
            };
        }
        $lenPart = $len !== null ? "({$len})" : '';
        $pgType = match ($type) {
            'int', 'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            'tinyint' => 'SMALLINT',
            'mediumint' => 'INTEGER',
            'varchar' => 'VARCHAR' . $lenPart,
            'char' => 'CHAR' . $lenPart,
            'text', 'longtext', 'mediumtext', 'tinytext' => 'TEXT',
            'blob', 'longblob', 'mediumblob', 'tinyblob' => 'BYTEA',
            'datetime' => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMP',
            'json' => 'JSONB',
            'decimal', 'numeric' => 'DECIMAL' . $lenPart,
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'bool', 'boolean' => 'BOOLEAN',
            default => strtoupper($type) . $lenPart,
        };
        return $pgType;
    }

    /** @return array{0: string, 1: string} [schema, table] */
    private function parseSchemaTable(string $table): array
    {
        $table = str_replace(['`', '"'], '', $table);
        if (str_contains($table, '.')) {
            $parts = explode('.', $table, 2);
            return [trim($parts[0]) ?: 'public', trim($parts[1])];
        }

        // 使用 current_schema() 而不是硬编码 'public'
        try {
            $currentSchema = $this->getLink()->query('SELECT current_schema()')->fetchColumn();
            $schema = $currentSchema ?: 'public';
        } catch (\Throwable $e) {
            $schema = 'public';
        }

        return [$schema, $table];
    }

    /** @inheritDoc */
    public function getTableComment(string $table): string
    {
        // 先将逻辑表名转换为物理表名（添加前缀和 schema）
        $formattedTable = $this->formatTableName($table);
        [$schema, $tableName] = $this->parseSchemaTable($formattedTable);
        if ($tableName === '') {
            return '';
        }
        try {
            $sql = "SELECT obj_description(c.oid) AS comment FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = :schema AND c.relname = :tbl AND c.relkind = 'r' LIMIT 1";
            $stmt = $this->getWrappedConnection()->prepare($sql);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (string) ($row['comment'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @inheritDoc */
    public function getTableColumns(string $table): array
    {
        // 先将逻辑表名转换为物理表名（添加前缀和 schema）
        $formattedTable = $this->formatTableName($table);
        [$schema, $tableName] = $this->parseSchemaTable($formattedTable);
        return $this->getPhysicalTableColumnsByParts($schema, $tableName);
    }

    public function getPhysicalTableColumns(PhysicalTableIdentity $identity): array
    {
        return $this->getPhysicalTableColumnsByParts($identity->namespace(), $identity->table());
    }

    private function getPhysicalTableColumnsByParts(string $schema, string $tableName): array
    {
        if ($tableName === '') {
            return [];
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        try {
            $sqlWithIdentity = "SELECT column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default, is_identity
                FROM information_schema.columns
                WHERE table_schema = :schema AND table_name = :tbl
                ORDER BY ordinal_position";
            $stmt = $pdo->prepare($sqlWithIdentity);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            try {
                $sqlLegacy = "SELECT column_name, data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default
                    FROM information_schema.columns
                    WHERE table_schema = :schema AND table_name = :tbl
                    ORDER BY ordinal_position";
                $stmt = $pdo->prepare($sqlLegacy);
                $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$legacyRow) {
                    $legacyRow['is_identity'] = 'NO';
                }
                unset($legacyRow);
            } catch (\Throwable) {
                return [];
            }
        }
        if ($rows === []) {
            return [];
        }

        $oidSql = "SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = :schema AND c.relname = :tbl AND c.relkind = 'r' LIMIT 1";
        $oidStmt = $pdo->prepare($oidSql);
        $oidStmt->execute([':schema' => $schema, ':tbl' => $tableName]);
        $tableOid = $oidStmt->fetchColumn();
        $pkCols = [];
        $uniqueCols = [];
        if ($tableOid !== false) {
            $conSql = "SELECT c.contype, a.attname
                FROM pg_constraint c
                CROSS JOIN unnest(c.conkey) AS conkey_attnum
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = conkey_attnum AND a.attnum > 0 AND NOT a.attisdropped
                WHERE c.conrelid = :oid AND c.contype IN ('p','u')";
            $conStmt = $pdo->prepare($conSql);
            $conStmt->execute([':oid' => $tableOid]);
            while (($r = $conStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $col = $r['attname'] ?? '';
                if (($r['contype'] ?? '') === 'p') {
                    $pkCols[$col] = true;
                } else {
                    $uniqueCols[$col] = true;
                }
            }
        }

        $commentSql = "SELECT a.attname, col_description(c.oid, a.attnum) AS col_comment
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE n.nspname = :schema AND c.relname = :tbl AND c.relkind = 'r' AND a.attnum > 0 AND NOT a.attisdropped";
        $commentStmt = $pdo->prepare($commentSql);
        $commentStmt->execute([':schema' => $schema, ':tbl' => $tableName]);
        $comments = [];
        while (($r = $commentStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $comments[$r['attname'] ?? ''] = (string) ($r['col_comment'] ?? '');
        }

        $list = [];
        foreach ($rows as $row) {
            $field = $row['column_name'] ?? '';
            $dataType = $row['data_type'] ?? '';
            $charLen = $row['character_maximum_length'] ?? null;
            $numPrec = $row['numeric_precision'] ?? null;
            $numScale = $row['numeric_scale'] ?? null;
            $pgType = strtolower($dataType);
            // 整型的 numeric_precision（如 32）是内部精度，不是 MySQL 风格 display width；与 #[Col] 声明的 length=null 对齐，避免误判列不等导致 SchemaDiff 异常
            if ($charLen !== null) {
                $length = (int)$charLen;
            } elseif (in_array($pgType, ['numeric', 'decimal'], true) && $numPrec !== null) {
                $length = (int)$numPrec . ',' . (int)($numScale ?? 0);
            } else {
                // numeric_precision on integer/real/double is a storage fact,
                // not a portable display length.  Only NUMERIC/DECIMAL retain
                // declaration precision and scale.
                $length = null;
            }
            $nullable = strtoupper($row['is_nullable'] ?? 'YES') !== 'NO';
            $default = $row['column_default'] ?? null;
            $isIdentity = strtoupper((string) ($row['is_identity'] ?? '')) === 'YES';
            $autoIncrement = $isIdentity || ($default !== null && stripos((string) $default, 'nextval') !== false);
            $comment = $comments[$field] ?? '';
            $primaryKey = isset($pkCols[$field]);
            $unique = isset($uniqueCols[$field]);

            $baseType = $pgType;
            if ($pgType === 'character varying') {
                $baseType = 'varchar';
            } elseif ($pgType === 'character') {
                $baseType = 'char';
            } elseif ($pgType === 'integer') {
                $baseType = 'int';
            } elseif (in_array($pgType, ['bigint', 'smallint'], true)) {
                $baseType = $pgType;
            } elseif ($pgType === 'double precision') {
                $baseType = 'double';
            } elseif ($pgType === 'real') {
                $baseType = 'float';
            } elseif ($pgType === 'numeric') {
                $baseType = 'decimal';
            } elseif (in_array($pgType, ['json', 'jsonb'], true)) {
                $baseType = 'json';
            } elseif ($pgType === 'bytea') {
                $baseType = 'blob';
            } elseif ($pgType === 'timestamp without time zone' || $pgType === 'timestamp with time zone') {
                $baseType = 'datetime';
            } elseif ($pgType === 'text') {
                $baseType = 'text';
            }

            $list[] = [
                'name' => $field,
                'type' => $baseType,
                'length' => $length,
                'nullable' => $nullable,
                'primary_key' => $primaryKey,
                'auto_increment' => $autoIncrement,
                'default' => $default,
                'comment' => $comment,
                'unique' => $unique,
            ];
        }
        return $list;
    }

    /** @inheritDoc */
    public function getTableIndexes(string $table): array
    {
        $formattedTable = $this->formatTableName($table);
        [$schema, $tableName] = $this->parseSchemaTable($formattedTable);
        if ($tableName === '') {
            return [];
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        $sql = "SELECT i.relname AS index_name,
                    a.attname AS column_name,
                    k.attnum,
                    k.ord AS seq,
                    ix.indisunique,
                    ix.indisvalid,
                    ix.indisready,
                    ix.indnatts,
                    ix.indnkeyatts,
                    am.amname AS index_method,
                    pg_get_expr(ix.indexprs, ix.indrelid) AS index_expression,
                    pg_get_expr(ix.indpred, ix.indrelid) AS index_predicate,
                    pg_get_indexdef(i.oid, k.ord::integer, true) AS index_key_definition
                FROM pg_catalog.pg_index ix
                JOIN pg_catalog.pg_class t ON t.oid = ix.indrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_catalog.pg_class i ON i.oid = ix.indexrelid
                JOIN pg_catalog.pg_am am ON am.oid = i.relam
                JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord) ON true
                LEFT JOIN pg_catalog.pg_attribute a
                    ON a.attrelid = t.oid
                    AND a.attnum = k.attnum
                    AND a.attnum > 0
                    AND NOT a.attisdropped
                WHERE n.nspname = :schema
                    AND t.relname = :tbl
                    AND t.relkind IN ('r', 'p')
                    AND NOT ix.indisprimary
                ORDER BY i.relname, k.ord";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byName = [];
        foreach ($rows as $row) {
            $keyName = (string)($row['index_name'] ?? '');
            $column = (string)($row['column_name'] ?? '');
            $seq = (int) ($row['seq'] ?? 0);
            $unique = $this->pgsqlBoolean($row['indisunique'] ?? false);
            $valid = $this->pgsqlBoolean($row['indisvalid'] ?? false);
            $ready = $this->pgsqlBoolean($row['indisready'] ?? false);
            $expression = trim((string)($row['index_expression'] ?? ''));
            $predicate = trim((string)($row['index_predicate'] ?? ''));
            $hasIncludedColumns = (int)($row['indnatts'] ?? 0) !== (int)($row['indnkeyatts'] ?? 0);
            $method = strtoupper((string)($row['index_method'] ?? 'BTREE'));
            $type = $unique ? 'UNIQUE' : 'DEFAULT';
            if ($expression !== '') {
                $column = $this->pgsqlFrameworkFulltextColumn(
                    (string)($row['index_key_definition'] ?? ''),
                ) ?? '';
                if ($method !== 'GIN' || $predicate !== '' || $hasIncludedColumns || $column === '') {
                    throw new \RuntimeException(__(
                        'PostgreSQL 表 %{1} 的索引 %{2} 使用声明式 Schema 尚不支持的表达式、谓词或 INCLUDE 列',
                        [$formattedTable, $keyName],
                    ));
                }
                $type = 'FULLTEXT';
            } elseif ($predicate !== '' || $hasIncludedColumns
                || (int)($row['attnum'] ?? 0) <= 0 || $column === '') {
                throw new \RuntimeException(__(
                    'PostgreSQL 表 %{1} 的索引 %{2} 使用声明式 Schema 尚不支持的表达式、谓词或 INCLUDE 列',
                    [$formattedTable, $keyName],
                ));
            }
            if (!$valid || !$ready) {
                throw new \RuntimeException(__(
                    'PostgreSQL 表 %{1} 的索引 %{2} 尚未处于可验证状态',
                    [$formattedTable, $keyName],
                ));
            }
            if (!isset($byName[$keyName])) {
                $byName[$keyName] = [
                    'columns' => [],
                    'unique' => $unique,
                    'method' => $method,
                    'type' => $type,
                ];
            } elseif ($byName[$keyName]['unique'] !== $unique
                || $byName[$keyName]['method'] !== $method
                || $byName[$keyName]['type'] !== $type) {
                throw new \RuntimeException(__(
                    'PostgreSQL 表 %{1} 的索引 %{2} 使用声明式 Schema 尚不支持的表达式、谓词或 INCLUDE 列',
                    [$formattedTable, $keyName],
                ));
            }
            $byName[$keyName]['columns'][$seq] = $column;
        }
        $list = [];
        foreach ($byName as $name => $data) {
            ksort($data['columns']);
            $list[] = [
                'name' => $name,
                'columns' => array_values($data['columns']),
                'unique' => $data['unique'],
                'method' => $data['method'],
                'type' => $data['type'],
            ];
        }
        return $list;
    }

    private function pgsqlBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }

    private function pgsqlFrameworkFulltextColumn(string $keyDefinition): ?string
    {
        $keyDefinition = trim($keyDefinition);
        if (preg_match(
            '~^to_tsvector\(\'english\'::regconfig,\s*(?:\(("(?:[^"]|"")*"|[a-z_][a-z0-9_$]*)\)::text|("(?:[^"]|"")*"|[a-z_][a-z0-9_$]*)::text|("(?:[^"]|"")*"|[a-z_][a-z0-9_$]*))\)$~D',
            $keyDefinition,
            $matches,
        ) !== 1) {
            return null;
        }

        $identifier = '';
        foreach ([1, 2, 3] as $position) {
            if (($matches[$position] ?? '') !== '') {
                $identifier = $matches[$position];
                break;
            }
        }
        if ($identifier === '') {
            return null;
        }
        if (str_starts_with($identifier, '"') && str_ends_with($identifier, '"')) {
            return str_replace('""', '"', substr($identifier, 1, -1));
        }
        return $identifier;
    }

    /** @inheritDoc */
    public function getTableForeignKeys(string $table): array
    {
        // 先将逻辑表名转换为物理表名（添加前缀和 schema）
        $formattedTable = $this->formatTableName($table);
        [$schema, $tableName] = $this->parseSchemaTable($formattedTable);
        if ($tableName === '') {
            return [];
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        try {
            $sql = "SELECT
                    kcu.constraint_name,
                    kcu.column_name,
                    rcu.table_name AS ref_table,
                    rcu.column_name AS ref_column,
                    rc.delete_rule,
                    rc.update_rule
                FROM information_schema.key_column_usage kcu
                JOIN information_schema.referential_constraints rc
                    ON rc.constraint_name = kcu.constraint_name AND rc.constraint_schema = kcu.table_schema
                JOIN information_schema.key_column_usage rcu
                    ON rcu.constraint_name = rc.unique_constraint_name AND rcu.table_schema = rc.unique_constraint_schema AND rcu.ordinal_position = kcu.ordinal_position
                WHERE kcu.table_schema = :schema AND kcu.table_name = :tbl
                ORDER BY kcu.constraint_name, kcu.ordinal_position";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $byName = [];
        foreach ($rows as $row) {
            $name = $row['constraint_name'] ?? '';
            $col = $row['column_name'] ?? '';
            $refTable = $row['ref_table'] ?? '';
            $refCol = $row['ref_column'] ?? '';
            $onDelete = strtoupper($row['delete_rule'] ?? '');
            $onUpdate = strtoupper($row['update_rule'] ?? '');
            if (!isset($byName[$name])) {
                $byName[$name] = [
                    'columns' => [],
                    'ref_table' => $refTable,
                    'ref_columns' => [],
                    'on_delete_cascade' => $onDelete === 'CASCADE',
                    'on_update_cascade' => $onUpdate === 'CASCADE',
                ];
            }
            $byName[$name]['columns'][] = $col;
            $byName[$name]['ref_columns'][] = $refCol;
        }
        $list = [];
        foreach ($byName as $name => $data) {
            $list[] = [
                'name' => $name,
                'columns' => $data['columns'],
                'ref_table' => $data['ref_table'],
                'ref_columns' => $data['ref_columns'],
                'on_delete_cascade' => $data['on_delete_cascade'],
                'on_update_cascade' => $data['on_update_cascade'],
            ];
        }
        return $list;
    }

    /** @inheritDoc */
    public function getDefaultTableAdditional(): string
    {
        return '';
    }
}
