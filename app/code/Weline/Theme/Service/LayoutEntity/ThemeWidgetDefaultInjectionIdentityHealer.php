<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutIdentityHasher;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;

/**
 * 在 layout_identity_hash 唯一索引 DDL 之前，把空 hash 回填为规范注入身份。
 *
 * 列常以 NOT NULL DEFAULT ''（PG char(64) 空串）落库；存量多行空值会令 UNIQUE 建索引失败。
 * 回填须在 ADD_COLUMN 之后、ADD_INDEX 之前，因此挂 table_ddl_before（见观察者），
 * 而不是一次性 before_schema_diff_commit（列可能尚未存在）。
 *
 * 幂等：无待回填行返回 0。身份无效或回填冲突 fail-closed，中断升级。
 */
final class ThemeWidgetDefaultInjectionIdentityHealer
{
    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        ThemeWidgetDefaultInjection::schema_fields_ID,
        ThemeWidgetDefaultInjection::schema_fields_THEME_ID,
        ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA,
        ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE,
        ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION,
        ThemeWidgetDefaultInjection::schema_fields_SCOPE,
        ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE,
        ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE,
        ThemeWidgetDefaultInjection::schema_fields_TARGET_ID,
        ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY,
        ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
    ];

    public function heal(?Printing $printing = null): int
    {
        $printing ??= ObjectManager::getInstance(Printing::class);
        /** @var ThemeWidgetDefaultInjection $model */
        $model = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
        $connector = $model->getConnection()->getConnector();
        $physicalTable = $this->physicalTable($model);

        if ($this->columnLengths($connector, ThemeWidgetDefaultInjection::schema_table, self::REQUIRED_COLUMNS) === null) {
            $printing->note(__('ThemeWidgetDefaultInjectionIdentityHealer: 跳过（身份列尚未建好）'));

            return 0;
        }

        $rows = $this->fetchRows($connector, $physicalTable);
        if ($rows === []) {
            $printing->note(__('ThemeWidgetDefaultInjectionIdentityHealer: updated=0'));

            return 0;
        }

        $hashColumn = ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH;
        $planned = [];
        $used = [];
        foreach ($rows as $row) {
            $recordId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0);
            if ($recordId < 1) {
                continue;
            }
            $themeId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_THEME_ID] ?? 0);
            $componentArea = trim((string)($row[ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA] ?? ''));
            $pageType = trim((string)($row[ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE] ?? ''));
            $layoutOption = trim((string)($row[ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION] ?? ''));
            $targetType = trim((string)($row[ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE] ?? ''));
            $targetId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_TARGET_ID] ?? 0);
            $injectionKey = trim((string)($row[ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY] ?? ''));
            $currentHash = trim((string)($row[$hashColumn] ?? ''));
            if (
                $themeId < 1 || $targetId < 0
                || $componentArea === '' || strlen($componentArea) > 32 || preg_match('/[\x00-\x1F\x7F]/', $componentArea) === 1
                || $pageType === '' || strlen($pageType) > 50 || preg_match('/[\x00-\x1F\x7F]/', $pageType) === 1
                || $layoutOption === '' || strlen($layoutOption) > 100
                || strlen($targetType) > 50
                || $injectionKey === '' || strlen($injectionKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $injectionKey) === 1
            ) {
                throw new \RuntimeException((string)__(
                    'ThemeWidgetDefaultInjectionIdentityHealer: record_id=%{1} 旧数据身份无效，升级已停止。',
                    [$recordId]
                ));
            }
            $identity = new LayoutIdentity(
                $layoutOption,
                (string)($row[ThemeWidgetDefaultInjection::schema_fields_SCOPE] ?? ''),
                $targetType,
                $targetId,
                (string)($row[ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE] ?? ''),
            );
            $candidate = LayoutIdentityHasher::injection(
                $themeId,
                $componentArea,
                $pageType,
                $identity,
                $injectionKey,
            );
            if (isset($used[$candidate])) {
                throw new \RuntimeException((string)__(
                    'ThemeWidgetDefaultInjectionIdentityHealer: layout_identity_hash 冲突 record_id=%{1} 与 %{2}',
                    [$used[$candidate], $recordId]
                ));
            }
            $used[$candidate] = $recordId;
            if ($currentHash === $candidate) {
                continue;
            }
            $planned[$recordId] = $candidate;
        }

        if ($planned === []) {
            $printing->note(__('ThemeWidgetDefaultInjectionIdentityHealer: updated=0'));

            return 0;
        }

        foreach ($planned as $recordId => $candidate) {
            try {
                $this->updateHash($connector, $physicalTable, $recordId, $candidate);
            } catch (\Throwable $e) {
                throw new \RuntimeException((string)__(
                    'ThemeWidgetDefaultInjectionIdentityHealer: record_id=%{1} 回填失败：%{2}',
                    [$recordId, $e->getMessage()]
                ), 0, $e);
            }
        }

        $updated = count($planned);
        $printing->note(__('ThemeWidgetDefaultInjectionIdentityHealer: updated=%{1}', [$updated]));

        return $updated;
    }

    private function physicalTable(ThemeWidgetDefaultInjection $model): string
    {
        $prefix = (string)$model->getConnection()->getConfigProvider()->getPrefix();
        $logical = ThemeWidgetDefaultInjection::schema_table;

        return $prefix !== '' ? $prefix . $logical : $logical;
    }

    /**
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

    private function updateHash(
        ConnectorInterface $connector,
        string $physicalTable,
        int $recordId,
        string $hash,
    ): void {
        $link = $connector->getLink();
        $link->exec(sprintf(
            'UPDATE %s SET %s = %s WHERE %s = %d',
            $connector->quoteTable($physicalTable),
            $connector->quoteIdentifier(ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH),
            $link->quote($hash),
            $connector->quoteIdentifier(ThemeWidgetDefaultInjection::schema_fields_ID),
            $recordId,
        ));
    }
}
