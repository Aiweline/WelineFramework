<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\ThemeScopeWorkspace;

/**
 * 在 binding_identity_key 唯一索引 DDL 之前，把存量行的空值回填为规范作用域键。
 *
 * binding_identity_key 是随版本隔离一起新增的列（NOT NULL DEFAULT ''），存量行全是空串，
 * 直接建唯一索引会以“重复空值”失败。回填必须发生在 ADD_COLUMN 之后、ADD_INDEX 之前，
 * 因此挂在 Weline_Framework_Schema::table_ddl_before 逐 op 触发（见观察者），
 * 而不是一次性的 before_schema_diff_commit（那时列还不存在）。
 *
 * 幂等：无待回填行即返回 0，可反复执行。
 */
final class ThemeScopeWorkspaceBindingKeyHealer
{
    /** 本 healer 读写所需列；缺任一列说明新增列尚未建好，整轮跳过。 */
    private const REQUIRED_COLUMNS = [
        ThemeScopeWorkspace::schema_fields_ID,
        ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE,
        ThemeScopeWorkspace::schema_fields_THEME_VERSION_ID,
        ThemeScopeWorkspace::schema_fields_IDENTITY_HASH,
        ThemeScopeWorkspace::schema_fields_BINDING_IDENTITY_KEY,
    ];

    public function heal(?Printing $printing = null): int
    {
        $printing ??= ObjectManager::getInstance(Printing::class);
        /** @var ThemeScopeWorkspace $model */
        $model = ObjectManager::getInstance(ThemeScopeWorkspace::class);
        $connector = $model->getConnection()->getConnector();
        $physicalTable = $this->physicalTable($model);

        $columnLengths = $this->columnLengths($connector, ThemeScopeWorkspace::schema_table, self::REQUIRED_COLUMNS);
        if ($columnLengths === null) {
            $printing->note(__('ThemeScopeWorkspaceBindingKeyHealer: 跳过（新增列尚未建好）'));

            return 0;
        }

        $rows = $this->fetchRows($connector, $physicalTable);
        if ($rows === []) {
            $printing->note(__('ThemeScopeWorkspaceBindingKeyHealer: updated=0'));

            return 0;
        }

        $keyColumn = ThemeScopeWorkspace::schema_fields_BINDING_IDENTITY_KEY;
        $planned = [];
        $used = [];
        $maxLength = 0;
        foreach ($rows as $row) {
            $workspaceId = (int)($row[ThemeScopeWorkspace::schema_fields_ID] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }
            $candidate = ThemeScopeWorkspace::resolveBindingIdentityKey(
                (string)($row[ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE] ?? ''),
                (int)($row[ThemeScopeWorkspace::schema_fields_THEME_VERSION_ID] ?? 0),
                (string)($row[ThemeScopeWorkspace::schema_fields_IDENTITY_HASH] ?? ''),
            );
            if (isset($used[$candidate])) {
                // 存量数据本身违反“规范身份唯一”，用主键后缀兜底，保证唯一索引能建起来。
                $suffix = '#' . $workspaceId;
                $candidate = substr($candidate, 0, max(0, $columnLengths[$keyColumn] - strlen($suffix))) . $suffix;
            }
            $used[$candidate] = true;
            $maxLength = max($maxLength, strlen($candidate));
            if ((string)($row[$keyColumn] ?? '') === $candidate) {
                continue;
            }
            $planned[$workspaceId] = $candidate;
        }

        if ($planned === []) {
            $printing->note(__('ThemeScopeWorkspaceBindingKeyHealer: updated=0'));

            return 0;
        }
        if ($maxLength > $columnLengths[$keyColumn]) {
            // 列还是旧宽度（MODIFY_COLUMN 尚未执行），写入超长值会报错并污染 PG 事务；
            // 延后到后续 op（ADD_INDEX 之前会再次触发本 healer）。
            $printing->note(__(
                'ThemeScopeWorkspaceBindingKeyHealer: 延后，列宽 %{1} 小于目标值长度 %{2}',
                [$columnLengths[$keyColumn], $maxLength]
            ));

            return 0;
        }

        foreach ($planned as $workspaceId => $candidate) {
            try {
                $this->updateKey($connector, $physicalTable, $workspaceId, $candidate);
            } catch (\Throwable $e) {
                // 回填失败必须中断升级：否则唯一索引会在后续 DDL 以更难定位的方式失败。
                throw new \RuntimeException(__(
                    'ThemeScopeWorkspaceBindingKeyHealer: workspace_id=%{1} 回填失败：%{2}',
                    [$workspaceId, $e->getMessage()]
                ), 0, $e);
            }
        }

        $updated = count($planned);
        $printing->note(__('ThemeScopeWorkspaceBindingKeyHealer: updated=%{1}', [$updated]));

        return $updated;
    }

    /**
     * 读取表物理名：前缀 + 逻辑表名，与 SchemaDiff 落库的表名一致。
     */
    private function physicalTable(ThemeScopeWorkspace $model): string
    {
        $prefix = (string)$model->getConnection()->getConfigProvider()->getPrefix();
        $logical = ThemeScopeWorkspace::schema_table;

        return $prefix !== '' ? $prefix . $logical : $logical;
    }

    /**
     * 用元数据探测列是否存在并取字符宽度；缺列返回 null。
     *
     * 禁止用会报错的 SELECT 探测列：PostgreSQL 下失败语句会污染当前事务，
     * 导致紧随其后的 DDL 全部失败。
     *
     * @param list<string> $columns
     * @return array<string, int>|null
     */
    private function columnLengths(ConnectorInterface $connector, string $logicalTable, array $columns): ?array
    {
        $found = [];
        foreach ($connector->getTableColumns($logicalTable) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $found[strtolower((string)($row['name'] ?? ''))] = $row['length'] ?? null;
        }

        $lengths = [];
        foreach ($columns as $column) {
            $name = strtolower($column);
            if (!array_key_exists($name, $found)) {
                return null;
            }
            $length = $found[$name];
            // 长度未知（非字符列）时按 0 处理，调用方会走“延后”分支而不是写入。
            $lengths[$column] = is_numeric($length) ? (int)$length : 0;
        }

        return $lengths;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(ConnectorInterface $connector, string $physicalTable): array
    {
        $columns = implode(', ', array_map(
            static fn(string $column): string => $connector->quoteIdentifier($column),
            self::REQUIRED_COLUMNS,
        ));
        $rows = $connector->query(sprintf(
            'SELECT %s FROM %s',
            $columns,
            $connector->quoteTable($physicalTable),
        ))->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function updateKey(
        ConnectorInterface $connector,
        string $physicalTable,
        int $workspaceId,
        string $candidate,
    ): void {
        $link = $connector->getLink();
        $link->exec(sprintf(
            'UPDATE %s SET %s = %s WHERE %s = %d',
            $connector->quoteTable($physicalTable),
            $connector->quoteIdentifier(ThemeScopeWorkspace::schema_fields_BINDING_IDENTITY_KEY),
            $link->quote($candidate),
            $connector->quoteIdentifier(ThemeScopeWorkspace::schema_fields_ID),
            $workspaceId,
        ));
    }
}
