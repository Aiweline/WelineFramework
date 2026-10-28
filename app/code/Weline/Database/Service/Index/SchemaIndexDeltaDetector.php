<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;

/**
 * 索引增量探测：一条查询取全库表级指纹（行数 + 最后更新时间），与上次快照比较得出「有增量」的表。
 *
 * 状态文件 VAR_DIR/state/schema_index_delta.json 同时保存两份数据：
 * - tables：上一轮全库快照，作为下一轮的比较基线；
 * - flagged：看门狗本轮交给 auto_apply 的表（增量表 ∪ 当前存在 pending job 的表），
 *   auto_apply 入队成功后调 markProcessed() 移除。
 *
 * 看门狗只在存在增量表时派生子进程；auto_apply 只消费 flagged 里的表，于是没有数据变化的
 * 那几分钟不再付框架冷启动。
 *
 * 任何查询/文件异常都退化为「无增量」并记 w_log_warning，绝不抛到 cron 外层。
 */
final class SchemaIndexDeltaDetector
{
    private const STATE_DIR = 'state';
    private const STATE_FILE = 'schema_index_delta.json';
    private const STATE_VERSION = 1;
    private const PG_TYPES = ['pgsql', 'postgres', 'postgresql'];
    private const MYSQL_TYPES = ['mysql', 'mariadb'];

    /** @var null|\Closure(): array<int,array<string,mixed>> 仅单测注入：直接给快照行集，不碰数据库 */
    private readonly ?\Closure $snapshotReader;

    /**
     * @param callable|null $snapshotReader 仅单测注入；不给则按 db 类型走 information_schema / pg_stat_user_tables
     */
    public function __construct(
        private readonly ?ConnectionFactory $connectionFactory = null,
        private readonly ?string $stateFileOverride = null,
        ?callable $snapshotReader = null,
    ) {
        $this->snapshotReader = $snapshotReader !== null ? \Closure::fromCallable($snapshotReader) : null;
    }

    /**
     * 全库表级指纹 SQL（一条查询，不逐表查）。
     *
     * @param bool $fallback PostgreSQL 统计视图不可用时退化为 pg_class 的 reltuples + relpages
     */
    public static function snapshotSql(string $dbType, bool $fallback = false): string
    {
        $dbType = strtolower(trim($dbType));
        if (in_array($dbType, self::PG_TYPES, true)) {
            if ($fallback) {
                return 'SELECT c.relname, c.reltuples::bigint AS n_live_tup, c.relpages::text AS update_time'
                    . ' FROM pg_class c'
                    . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
                    . " WHERE c.relkind = 'r' AND n.nspname = current_schema()";
            }

            return 'SELECT relname, n_live_tup,'
                . ' GREATEST(last_autovacuum, last_vacuum, last_analyze, last_autoanalyze)::text AS update_time'
                . ' FROM pg_stat_user_tables';
        }
        if (in_array($dbType, self::MYSQL_TYPES, true)) {
            return 'SELECT TABLE_NAME, TABLE_ROWS, UPDATE_TIME'
                . ' FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE()';
        }

        throw new \RuntimeException('Schema index delta snapshot does not support db type: ' . $dbType);
    }

    /**
     * 当前全库快照。
     *
     * @return array<string, array{rows:int, updated:string}>
     */
    public function snapshot(): array
    {
        try {
            return $this->normalize($this->fetchRows());
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index delta snapshot failed: ' . $throwable->getMessage(),
                [],
                'database',
            );

            return [];
        }
    }

    /**
     * 有增量的表；首次运行（无状态文件）按「全部视为有增量」处理。
     *
     * @return list<string>
     */
    public function changedTables(): array
    {
        try {
            $current = $this->snapshot();
            if ($current === []) {
                return [];
            }
            $previous = $this->tablesFromState($this->readState());

            return $previous === [] ? array_keys($current) : $this->diff($previous, $current);
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index delta compare failed: ' . $throwable->getMessage(),
                [],
                'database',
            );

            return [];
        }
    }

    /**
     * 记录本轮快照，并把相对上一轮多出的表追加进 flagged（看门狗 → auto_apply 的交付）。
     */
    public function remember(): void
    {
        try {
            $current = $this->snapshot();
            if ($current === []) {
                // 快照失败（已记 warning）：保留旧状态，避免把比较基线清空。
                return;
            }
            $state = $this->readState();
            $previous = $this->tablesFromState($state);
            $changed = $previous === [] ? array_keys($current) : $this->diff($previous, $current);
            $flagged = $this->flaggedFromState($state);
            foreach ($changed as $table) {
                if (!in_array($table, $flagged, true)) {
                    $flagged[] = $table;
                }
            }
            $this->writeState([
                'version' => self::STATE_VERSION,
                'snapshot_at' => date('Y-m-d H:i:s'),
                'tables' => $current,
                'flagged' => array_values($flagged),
            ]);
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index delta remember failed: ' . $throwable->getMessage(),
                [],
                'database',
            );
        }
    }

    /**
     * 标记这些表已消费：从 flagged 中移除（auto_apply 成功入队后调用）。
     *
     * @param list<string> $tables
     */
    public function markProcessed(array $tables): void
    {
        try {
            $drop = [];
            foreach ($tables as $table) {
                $name = trim((string)$table);
                if ($name !== '') {
                    $drop[$name] = true;
                }
            }
            if ($drop === []) {
                return;
            }
            $state = $this->readState();
            if ($state === []) {
                return;
            }
            $state['flagged'] = array_values(array_filter(
                $this->flaggedFromState($state),
                static fn(string $table): bool => !isset($drop[$table]),
            ));
            $this->writeState($state);
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index delta markProcessed failed: ' . $throwable->getMessage(),
                [],
                'database',
            );
        }
    }

    /**
     * 把表并入 flagged（幂等并集：不覆盖已有 flagged、不重复）。
     *
     * 用于「表没有写入但仍有 pending 索引 job」：setup:upgrade 产生的 job 若目标表
     * 此后没有增量，就不会被 auto_apply 捡到，这里按 pending job 补标一次。
     *
     * @param list<string> $tables
     */
    public function flagTables(array $tables): void
    {
        try {
            $add = [];
            foreach ($tables as $table) {
                $name = trim((string)$table);
                if ($name !== '') {
                    $add[$name] = true;
                }
            }
            if ($add === []) {
                return;
            }
            $state = $this->readState();
            $flagged = $this->flaggedFromState($state);
            foreach (array_keys($add) as $table) {
                if (!in_array($table, $flagged, true)) {
                    $flagged[] = $table;
                }
            }
            $state['flagged'] = $flagged;
            $this->writeState($state);
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index delta flagTables failed: ' . $throwable->getMessage(),
                [],
                'database',
            );
        }
    }

    /**
     * 看门狗交给 auto_apply 的表。
     *
     * @return list<string>
     */
    public function flaggedTables(): array
    {
        try {
            return $this->flaggedFromState($this->readState());
        } catch (\Throwable $throwable) {
            w_log_warning(
                'Schema index delta flagged read failed: ' . $throwable->getMessage(),
                [],
                'database',
            );

            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchRows(): array
    {
        if ($this->snapshotReader !== null) {
            $rows = ($this->snapshotReader)();

            return is_array($rows) ? $rows : [];
        }
        $connector = $this->connector();
        $dbType = strtolower((string)$connector->getConfigProvider()->getDbType());
        try {
            return $this->fetchRowsBySql($connector, self::snapshotSql($dbType));
        } catch (\Throwable $throwable) {
            if (!in_array($dbType, self::PG_TYPES, true)) {
                throw $throwable;
            }
            // pg_stat_user_tables 拿不到时退化为 pg_class 的 reltuples + relpages。
            w_log_warning(
                'Schema index delta falls back to pg_class: ' . $throwable->getMessage(),
                [],
                'database',
            );

            return $this->fetchRowsBySql($connector, self::snapshotSql($dbType, true));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchRowsBySql(ConnectorInterface $connector, string $sql): array
    {
        $rows = $connector->query($sql)->fetch();

        return is_array($rows) ? $rows : [];
    }

    private function connector(): ConnectorInterface
    {
        $factory = $this->connectionFactory ?? ConnectionFactory::getInstance();

        return $factory->getConnector();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string, array{rows:int, updated:string}>
     */
    private function normalize(array $rows): array
    {
        $tables = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = $this->firstValue($row, ['TABLE_NAME', 'table_name', 'relname']);
            if ($name === null) {
                continue;
            }
            $count = $this->firstValue($row, ['TABLE_ROWS', 'table_rows', 'n_live_tup', 'reltuples']);
            $updated = $this->firstValue($row, ['UPDATE_TIME', 'update_time', 'relpages', 'last_activity']);
            $tables[$name] = [
                'rows' => (int)($count ?? 0),
                'updated' => (string)($updated ?? ''),
            ];
        }

        return $tables;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if ($row[$key] === null) {
                return null;
            }
            $text = trim((string)$row[$key]);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{rows:int, updated:string}> $previous
     * @param array<string, array{rows:int, updated:string}> $current
     * @return list<string>
     */
    private function diff(array $previous, array $current): array
    {
        $changed = [];
        foreach ($current as $table => $now) {
            $before = $previous[$table] ?? null;
            if (!is_array($before)) {
                $changed[] = $table;
                continue;
            }
            if ((int)($before['rows'] ?? 0) !== $now['rows']) {
                $changed[] = $table;
                continue;
            }
            $beforeUpdated = (string)($before['updated'] ?? '');
            // UPDATE_TIME 为 NULL（MySQL 常见、PG 从未 VACUUM/ANALYZE）时只比行数；
            // 一侧有值一侧为空视为变化（统计刚建立或被重置）。
            if ($beforeUpdated !== $now['updated'] && ($beforeUpdated !== '' || $now['updated'] !== '')) {
                $changed[] = $table;
            }
        }

        return $changed;
    }

    private function stateFile(): string
    {
        $override = $this->stateFileOverride !== null ? trim($this->stateFileOverride) : '';
        if ($override !== '') {
            return $override;
        }
        $varDir = \defined('VAR_DIR') ? (string)\constant('VAR_DIR') : '';
        if ($varDir === '') {
            throw new \RuntimeException('VAR_DIR is not defined; schema index delta state needs a var root.');
        }

        return rtrim($varDir, '/\\') . DIRECTORY_SEPARATOR . self::STATE_DIR
            . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    /** @return array<string,mixed> */
    private function readState(): array
    {
        $file = $this->stateFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            w_log_warning('Schema index delta state is not valid JSON: ' . $file, [], 'database');

            return [];
        }

        return $data;
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void
    {
        $file = $this->stateFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create schema index delta state dir: ' . $dir);
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode schema index delta state.');
        }
        if (@file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write schema index delta state: ' . $file);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string, array{rows:int, updated:string}>
     */
    private function tablesFromState(array $state): array
    {
        $tables = $state['tables'] ?? null;
        if (!is_array($tables)) {
            return [];
        }
        $normalized = [];
        foreach ($tables as $name => $entry) {
            if (is_string($name) && $name !== '' && is_array($entry)) {
                $normalized[$name] = [
                    'rows' => (int)($entry['rows'] ?? 0),
                    'updated' => (string)($entry['updated'] ?? ''),
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $state
     * @return list<string>
     */
    private function flaggedFromState(array $state): array
    {
        $flagged = $state['flagged'] ?? null;
        if (!is_array($flagged)) {
            return [];
        }
        $names = [];
        foreach ($flagged as $table) {
            $name = trim((string)$table);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
