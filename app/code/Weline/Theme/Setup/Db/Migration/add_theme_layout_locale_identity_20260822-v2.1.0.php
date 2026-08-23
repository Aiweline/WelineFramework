<?php

declare(strict_types=1);

namespace Weline\Theme\Setup\Db\Migration;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\Layout\LayoutIdentityHasher;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;

final class AddThemeLayoutLocaleIdentity20260822V210 extends AbstractMigration
{
    private const MAX_PREFLIGHT_ROWS_PER_TABLE = 100000;

    public function getDescription(): string
    {
        return '为 Theme 布局与版本增加独立 locale_code 身份，旧数据保留为空语言中立行。';
    }

    public function getVersion(): string
    {
        return '2.1.0';
    }

    public function getDate(): string
    {
        return '2026-08-22';
    }

    /** @return list<string> */
    public function getAffectedTables(): array
    {
        return [
            ThemeLayout::schema_table,
            ThemeLayoutVersion::schema_table,
            ThemeVirtualLayout::schema_table,
            ThemeWidgetDefaultInjection::schema_table,
        ];
    }

    public function requiresBackup(): bool
    {
        return true;
    }

    public function getBackupStrategy(): array
    {
        return ['strategy' => 'table', 'tables' => $this->getAffectedTables(), 'columns' => []];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $layoutTable = ObjectManager::getInstance(ThemeLayout::class)->getTable();
        $versionTable = ObjectManager::getInstance(ThemeLayoutVersion::class)->getTable();
        $virtualTable = ObjectManager::getInstance(ThemeVirtualLayout::class)->getTable();
        $injectionTable = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class)->getTable();

        // MySQL may auto-commit ALTER TABLE. Resolve all deterministic legacy
        // identity failures and collisions before the first DDL so an invalid
        // dataset never leaves the live Theme schema half-upgraded.
        $this->preflightExistingTables(
            $connection,
            $layoutTable,
            $versionTable,
            $virtualTable,
            $injectionTable,
        );

        if ($connection->tableExist($layoutTable)) {
            $this->widenScope(
                $connection,
                $layoutTable,
                ThemeLayout::schema_fields_LAYOUT_OPTION,
                'default',
                ThemeLayout::schema_fields_ID,
            );
            $this->addNodeUid($connection, $layoutTable, ThemeLayout::schema_fields_ID);
            $this->addLocale($connection, $layoutTable, ThemeLayout::schema_fields_SCOPE, ThemeLayout::schema_fields_ID);
            $this->addIdentityHash(
                $connection,
                $layoutTable,
                ThemeLayout::schema_fields_TARGET_ID,
                ThemeLayout::schema_fields_IDENTITY_HASH,
                ThemeLayout::schema_fields_ID,
            );
            $this->backfillHashes(
                ObjectManager::getInstance(ThemeLayout::class),
                ThemeLayout::schema_fields_ID,
                fn(ThemeLayout $row): array => $this->layoutUpdates($row),
            );
            $this->replaceIndexes($connection, $layoutTable, [
                'idx_theme_layout_identity' => [TableInterface::index_type_KEY, [
                    ThemeLayout::schema_fields_THEME_ID,
                    ThemeLayout::schema_fields_PAGE_TYPE,
                    ThemeLayout::schema_fields_LAYOUT_OPTION,
                    ThemeLayout::schema_fields_SCOPE,
                    ThemeLayout::schema_fields_LOCALE_CODE,
                    ThemeLayout::schema_fields_TARGET_TYPE,
                    ThemeLayout::schema_fields_TARGET_ID,
                    ThemeLayout::schema_fields_STATUS,
                ]],
                'uk_theme_layout_identity_node' => [TableInterface::index_type_UNIQUE, [
                    ThemeLayout::schema_fields_IDENTITY_HASH,
                ]],
            ]);
        }
        if ($connection->tableExist($versionTable)) {
            $this->widenScope(
                $connection,
                $versionTable,
                ThemeLayoutVersion::schema_fields_LAYOUT_OPTION,
                'default',
                ThemeLayoutVersion::schema_fields_ID,
            );
            $this->addLocale($connection, $versionTable, ThemeLayoutVersion::schema_fields_SCOPE, ThemeLayoutVersion::schema_fields_ID);
            $this->addIdentityHash(
                $connection,
                $versionTable,
                ThemeLayoutVersion::schema_fields_TARGET_ID,
                ThemeLayoutVersion::schema_fields_IDENTITY_HASH,
                ThemeLayoutVersion::schema_fields_ID,
            );
            $this->backfillHashes(
                ObjectManager::getInstance(ThemeLayoutVersion::class),
                ThemeLayoutVersion::schema_fields_ID,
                fn(ThemeLayoutVersion $row): array => $this->versionUpdates($row),
            );
            $base = [ThemeLayoutVersion::schema_fields_IDENTITY_HASH];
            $this->replaceIndexes($connection, $versionTable, [
                'idx_theme_page_identity' => [TableInterface::index_type_KEY, $base],
                'idx_version_identity_number' => [TableInterface::index_type_KEY, [...$base, ThemeLayoutVersion::schema_fields_VERSION_NUMBER]],
                'idx_current_identity' => [TableInterface::index_type_KEY, [...$base, ThemeLayoutVersion::schema_fields_IS_CURRENT]],
                'idx_published_identity' => [TableInterface::index_type_KEY, [...$base, ThemeLayoutVersion::schema_fields_IS_PUBLISHED]],
            ]);
        }
        if ($connection->tableExist($virtualTable)) {
            $this->widenScope(
                $connection,
                $virtualTable,
                ThemeVirtualLayout::schema_fields_LAYOUT_OPTION,
                'default.default.default',
                ThemeVirtualLayout::schema_fields_ID,
            );
            $this->addLocale(
                $connection,
                $virtualTable,
                ThemeVirtualLayout::schema_fields_SCOPE,
                ThemeVirtualLayout::schema_fields_ID,
            );
            $this->addIdentityHash(
                $connection,
                $virtualTable,
                ThemeVirtualLayout::schema_fields_TARGET_ID,
                ThemeVirtualLayout::schema_fields_IDENTITY_HASH,
                ThemeVirtualLayout::schema_fields_ID,
            );
            $this->backfillHashes(
                ObjectManager::getInstance(ThemeVirtualLayout::class),
                ThemeVirtualLayout::schema_fields_ID,
                fn(ThemeVirtualLayout $row): array => $this->virtualUpdates($row),
            );
            $this->replaceIndexes($connection, $virtualTable, [
                'idx_theme_virtual_layout_unique' => [TableInterface::index_type_UNIQUE, [
                    ThemeVirtualLayout::schema_fields_IDENTITY_HASH,
                ]],
                'idx_theme_virtual_layout_lookup' => [TableInterface::index_type_KEY, [
                    ThemeVirtualLayout::schema_fields_THEME_ID,
                    ThemeVirtualLayout::schema_fields_AREA,
                    ThemeVirtualLayout::schema_fields_LAYOUT_TYPE,
                    ThemeVirtualLayout::schema_fields_LAYOUT_OPTION,
                    ThemeVirtualLayout::schema_fields_LOCALE_CODE,
                    ThemeVirtualLayout::schema_fields_IS_ACTIVE,
                ]],
            ]);
        }
        if ($connection->tableExist($injectionTable)) {
            $this->widenScope(
                $connection,
                $injectionTable,
                ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION,
                'default',
                ThemeWidgetDefaultInjection::schema_fields_ID,
            );
            $this->addLocale(
                $connection,
                $injectionTable,
                ThemeWidgetDefaultInjection::schema_fields_SCOPE,
                ThemeWidgetDefaultInjection::schema_fields_ID,
            );
            $this->addIdentityHash(
                $connection,
                $injectionTable,
                ThemeWidgetDefaultInjection::schema_fields_TARGET_ID,
                ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
                ThemeWidgetDefaultInjection::schema_fields_ID,
            );
            $this->backfillHashes(
                ObjectManager::getInstance(ThemeWidgetDefaultInjection::class),
                ThemeWidgetDefaultInjection::schema_fields_ID,
                fn(ThemeWidgetDefaultInjection $row): array => $this->injectionUpdates($row),
            );
            $this->replaceIndexes($connection, $injectionTable, [
                'uk_theme_widget_default_injection' => [TableInterface::index_type_UNIQUE, [
                    ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
                ]],
            ]);
        }
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function preflightExistingTables(
        object $connection,
        string $layoutTable,
        string $versionTable,
        string $virtualTable,
        string $injectionTable,
    ): void {
        if ($connection->tableExist($layoutTable)) {
            $this->preflightRows(
                ObjectManager::getInstance(ThemeLayout::class),
                ThemeLayout::schema_fields_ID,
                fn(ThemeLayout $row): array => $this->layoutUpdates($row),
                ThemeLayout::schema_fields_IDENTITY_HASH,
                true,
            );
        }
        if ($connection->tableExist($versionTable)) {
            $this->preflightRows(
                ObjectManager::getInstance(ThemeLayoutVersion::class),
                ThemeLayoutVersion::schema_fields_ID,
                fn(ThemeLayoutVersion $row): array => $this->versionUpdates($row),
                ThemeLayoutVersion::schema_fields_IDENTITY_HASH,
                false,
            );
        }
        if ($connection->tableExist($virtualTable)) {
            $this->preflightRows(
                ObjectManager::getInstance(ThemeVirtualLayout::class),
                ThemeVirtualLayout::schema_fields_ID,
                fn(ThemeVirtualLayout $row): array => $this->virtualUpdates($row),
                ThemeVirtualLayout::schema_fields_IDENTITY_HASH,
                true,
            );
        }
        if ($connection->tableExist($injectionTable)) {
            $this->preflightRows(
                ObjectManager::getInstance(ThemeWidgetDefaultInjection::class),
                ThemeWidgetDefaultInjection::schema_fields_ID,
                fn(ThemeWidgetDefaultInjection $row): array => $this->injectionUpdates($row),
                ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH,
                true,
            );
        }
    }

    /**
     * @param object $prototype Theme model prototype
     * @param callable(object):array<string,scalar|null> $resolveUpdates
     */
    private function preflightRows(
        object $prototype,
        string $primaryKey,
        callable $resolveUpdates,
        string $identityHashField,
        bool $identityMustBeUnique,
    ): void {
        $lastId = 0;
        $processed = 0;
        $seenHashes = [];
        while (true) {
            $rows = (clone $prototype)->clearData()->reset()
                ->where($primaryKey, $lastId, '>')
                ->order($primaryKey, 'ASC')
                ->limit(500)
                ->select()
                ->fetch()
                ->getItems();
            if ($rows === []) {
                return;
            }

            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!is_object($row) || !method_exists($row, 'getData')) {
                    throw new \RuntimeException((string)__('Theme 旧布局数据无效，升级已停止。'));
                }
                $rowId = (int)$row->getData($primaryKey);
                if ($rowId <= $lastId) {
                    continue;
                }
                $updates = $resolveUpdates($row);
                $hash = (string)($updates[$identityHashField] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new \RuntimeException((string)__('Theme 旧布局身份哈希无效，升级已停止。'));
                }
                if ($identityMustBeUnique && isset($seenHashes[$hash])) {
                    throw new \RuntimeException((string)__('Theme 旧布局数据映射后发生身份冲突，升级已停止。'));
                }
                if ($identityMustBeUnique) {
                    $seenHashes[$hash] = true;
                }
                $processed++;
                if ($processed > self::MAX_PREFLIGHT_ROWS_PER_TABLE) {
                    throw new \RuntimeException((string)__('Theme 单表旧布局数据超过安全升级上限。'));
                }
                $nextId = max($nextId, $rowId);
            }
            if ($nextId <= $lastId) {
                throw new \RuntimeException((string)__('Theme 旧布局升级预检无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < 500) {
                return;
            }
        }
    }

    /** @return array<string,scalar|null> */
    private function layoutUpdates(ThemeLayout $row): array
    {
        $rowId = $row->getLayoutId();
        $themeId = $row->getThemeId();
        $pageType = trim($row->getPageType());
        $layoutOption = trim($row->getLayoutOption());
        $targetType = trim($row->getTargetType());
        $targetId = (int)$row->getData(ThemeLayout::schema_fields_TARGET_ID);
        $status = trim($row->getStatus());
        $area = trim($row->getArea());
        if (
            $rowId < 1 || $themeId < 1 || $targetId < 0
            || $pageType === '' || strlen($pageType) > 50 || preg_match('/[\x00-\x1F\x7F]/', $pageType) === 1
            || strlen($layoutOption) > 100
            || strlen($targetType) > 50
            || !in_array($status, [ThemeLayout::STATUS_DRAFT, ThemeLayout::STATUS_PUBLISHED], true)
            || $area === '' || strlen($area) > 50 || preg_match('/[\x00-\x1F\x7F]/', $area) === 1
        ) {
            throw new \RuntimeException((string)__('Theme 布局旧数据身份无效，升级已停止。'));
        }
        $identity = new LayoutIdentity(
            $layoutOption,
            $row->getScope(),
            $targetType,
            $targetId,
            $row->getLocaleCode(),
        );
        $nodeUid = strtolower(trim((string)$row->getData(ThemeLayout::schema_fields_NODE_UID)));
        if ($nodeUid === '') {
            $nodeUid = substr(hash('sha256', 'weline-theme-layout-node-v1:' . $rowId), 0, 32);
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
            throw new \RuntimeException((string)__('Theme 布局旧数据的 node_uid 无效，升级已停止。'));
        }

        return [
            ThemeLayout::schema_fields_PAGE_TYPE => $pageType,
            ThemeLayout::schema_fields_LAYOUT_OPTION => $identity->layoutOption,
            ThemeLayout::schema_fields_SCOPE => $identity->scope,
            ThemeLayout::schema_fields_LOCALE_CODE => $identity->localeCode,
            ThemeLayout::schema_fields_TARGET_TYPE => $identity->targetType,
            ThemeLayout::schema_fields_TARGET_ID => $identity->targetId,
            ThemeLayout::schema_fields_STATUS => $status,
            ThemeLayout::schema_fields_AREA => $area,
            ThemeLayout::schema_fields_NODE_UID => $nodeUid,
            ThemeLayout::schema_fields_IDENTITY_HASH => LayoutIdentityHasher::node(
                $themeId,
                $pageType,
                $identity,
                $status,
                $nodeUid,
            ),
        ];
    }

    /** @return array<string,scalar|null> */
    private function versionUpdates(ThemeLayoutVersion $row): array
    {
        $themeId = $row->getThemeId();
        $pageType = trim($row->getPageType());
        $layoutOption = trim($row->getLayoutOption());
        $targetType = trim($row->getTargetType());
        $targetId = (int)$row->getData(ThemeLayoutVersion::schema_fields_TARGET_ID);
        $versionType = trim($row->getVersionType());
        if (
            $row->getVersionId() < 1 || $themeId < 1 || $targetId < 0
            || $pageType === '' || strlen($pageType) > 50 || preg_match('/[\x00-\x1F\x7F]/', $pageType) === 1
            || strlen($layoutOption) > 100
            || strlen($targetType) > 50
            || $row->getVersionNumber() < 1
            || !in_array($versionType, [
                ThemeLayoutVersion::TYPE_MANUAL,
                ThemeLayoutVersion::TYPE_AUTO_BACKUP,
                ThemeLayoutVersion::TYPE_RESTORE,
                ThemeLayoutVersion::TYPE_PUBLISH,
            ], true)
        ) {
            throw new \RuntimeException((string)__('Theme 布局版本旧数据身份无效，升级已停止。'));
        }
        $identity = new LayoutIdentity(
            $layoutOption,
            $row->getScope(),
            $targetType,
            $targetId,
            $row->getLocaleCode(),
        );

        return [
            ThemeLayoutVersion::schema_fields_PAGE_TYPE => $pageType,
            ThemeLayoutVersion::schema_fields_LAYOUT_OPTION => $identity->layoutOption,
            ThemeLayoutVersion::schema_fields_SCOPE => $identity->scope,
            ThemeLayoutVersion::schema_fields_LOCALE_CODE => $identity->localeCode,
            ThemeLayoutVersion::schema_fields_TARGET_TYPE => $identity->targetType,
            ThemeLayoutVersion::schema_fields_TARGET_ID => $identity->targetId,
            ThemeLayoutVersion::schema_fields_VERSION_TYPE => $versionType,
            ThemeLayoutVersion::schema_fields_IDENTITY_HASH => LayoutIdentityHasher::base($themeId, $pageType, $identity),
        ];
    }

    /** @return array<string,scalar|null> */
    private function virtualUpdates(ThemeVirtualLayout $row): array
    {
        $themeId = $row->getThemeId();
        $area = trim($row->getArea());
        $layoutType = trim($row->getLayoutType());
        $layoutOption = trim($row->getLayoutOption());
        $targetType = trim($row->getTargetType());
        $targetId = (int)$row->getData(ThemeVirtualLayout::schema_fields_TARGET_ID);
        $sourceType = trim($row->getSourceType());
        if (
            $row->getId() < 1 || $themeId < 1 || $targetId < 0
            || $area === '' || strlen($area) > 32 || preg_match('/[\x00-\x1F\x7F]/', $area) === 1
            || $layoutType === '' || strlen($layoutType) > 64 || preg_match('/[\x00-\x1F\x7F]/', $layoutType) === 1
            || $layoutOption === '' || strlen($layoutOption) > 128
            || strlen($targetType) > 64
            || !in_array($sourceType, [
                ThemeVirtualLayout::SOURCE_TYPE_VIRTUAL,
                ThemeVirtualLayout::SOURCE_TYPE_AI,
                ThemeVirtualLayout::SOURCE_TYPE_IMPORTED,
            ], true)
        ) {
            throw new \RuntimeException((string)__('Theme 虚拟布局旧数据身份无效，升级已停止。'));
        }
        $identity = new LayoutIdentity(
            $layoutOption,
            $row->getScope(),
            $targetType,
            $targetId,
            $row->getLocaleCode(),
        );

        return [
            ThemeVirtualLayout::schema_fields_AREA => $area,
            ThemeVirtualLayout::schema_fields_LAYOUT_TYPE => $layoutType,
            ThemeVirtualLayout::schema_fields_LAYOUT_OPTION => $identity->layoutOption,
            ThemeVirtualLayout::schema_fields_SCOPE => $identity->scope,
            ThemeVirtualLayout::schema_fields_LOCALE_CODE => $identity->localeCode,
            ThemeVirtualLayout::schema_fields_TARGET_TYPE => $identity->targetType,
            ThemeVirtualLayout::schema_fields_TARGET_ID => $identity->targetId,
            ThemeVirtualLayout::schema_fields_SOURCE_TYPE => $sourceType,
            ThemeVirtualLayout::schema_fields_IDENTITY_HASH => LayoutIdentityHasher::virtual(
                $themeId,
                $area,
                $layoutType,
                $identity,
            ),
        ];
    }

    /** @return array<string,scalar|null> */
    private function injectionUpdates(ThemeWidgetDefaultInjection $row): array
    {
        $themeId = (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_THEME_ID);
        $componentArea = trim((string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA));
        $pageType = trim((string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE));
        $layoutOption = trim((string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION));
        $targetType = trim((string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE));
        $targetId = (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_TARGET_ID);
        $injectionKey = trim((string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY));
        if (
            (int)$row->getData(ThemeWidgetDefaultInjection::schema_fields_ID) < 1
            || $themeId < 1 || $targetId < 0
            || $componentArea === '' || strlen($componentArea) > 32 || preg_match('/[\x00-\x1F\x7F]/', $componentArea) === 1
            || $pageType === '' || strlen($pageType) > 50 || preg_match('/[\x00-\x1F\x7F]/', $pageType) === 1
            || $layoutOption === '' || strlen($layoutOption) > 100
            || strlen($targetType) > 50
            || $injectionKey === '' || strlen($injectionKey) > 64 || preg_match('/[\x00-\x1F\x7F]/', $injectionKey) === 1
        ) {
            throw new \RuntimeException((string)__('Theme 默认组件注入旧数据身份无效，升级已停止。'));
        }
        $identity = new LayoutIdentity(
            $layoutOption,
            (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_SCOPE),
            $targetType,
            $targetId,
            (string)$row->getData(ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE),
        );

        return [
            ThemeWidgetDefaultInjection::schema_fields_COMPONENT_AREA => $componentArea,
            ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE => $pageType,
            ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION => $identity->layoutOption,
            ThemeWidgetDefaultInjection::schema_fields_SCOPE => $identity->scope,
            ThemeWidgetDefaultInjection::schema_fields_LOCALE_CODE => $identity->localeCode,
            ThemeWidgetDefaultInjection::schema_fields_TARGET_TYPE => $identity->targetType,
            ThemeWidgetDefaultInjection::schema_fields_TARGET_ID => $identity->targetId,
            ThemeWidgetDefaultInjection::schema_fields_INJECTION_KEY => $injectionKey,
            ThemeWidgetDefaultInjection::schema_fields_IDENTITY_HASH => LayoutIdentityHasher::injection(
                $themeId,
                $componentArea,
                $pageType,
                $identity,
                $injectionKey,
            ),
        ];
    }

    private function addNodeUid(object $connection, string $table, string $primaryKey): void
    {
        if ($this->columnExists($connection, $table, ThemeLayout::schema_fields_NODE_UID)) {
            return;
        }
        $alter = $connection->alterTable()->forTable($table, $primaryKey, '');
        $alter->addColumn(
            ThemeLayout::schema_fields_NODE_UID,
            '',
            TableInterface::column_type_VARCHAR,
            32,
            'NULL DEFAULT NULL',
            '稳定 128-bit 布局节点 UID',
        );
        $alter->alter();
    }

    private function addLocale(object $connection, string $table, string $after, string $primaryKey): void
    {
        if ($this->columnExists($connection, $table, 'locale_code')) {
            return;
        }
        $alter = $connection->alterTable()->forTable($table, $primaryKey, '');
        $alter->addColumn(
            'locale_code',
            '',
            TableInterface::column_type_VARCHAR,
            16,
            "NOT NULL DEFAULT ''",
            '独立布局语言；空值为历史语言中立行',
        );
        $alter->alter();
    }

    private function addIdentityHash(
        object $connection,
        string $table,
        string $after,
        string $field,
        string $primaryKey,
    ): void {
        if ($this->columnExists($connection, $table, $field)) {
            return;
        }
        $alter = $connection->alterTable()->forTable($table, $primaryKey, '');
        $alter->addColumn(
            $field,
            '',
            'char',
            64,
            "NOT NULL DEFAULT ''",
            'Canonical layout identity SHA-256',
        );
        $alter->alter();
    }

    /**
     * @param object $prototype Theme model prototype
     * @param callable(object):array<string,scalar|null> $resolveUpdates
     */
    private function backfillHashes(
        object $prototype,
        string $primaryKey,
        callable $resolveUpdates,
    ): void {
        $lastId = 0;
        while (true) {
            $rows = (clone $prototype)->clearData()->reset()
                ->where($primaryKey, $lastId, '>')
                ->order($primaryKey, 'ASC')
                ->limit(500)
                ->select()
                ->fetch()
                ->getItems();
            if ($rows === []) {
                return;
            }

            $nextId = $lastId;
            foreach ($rows as $row) {
                if (!is_object($row) || !method_exists($row, 'getData')) {
                    continue;
                }
                $rowId = (int)$row->getData($primaryKey);
                if ($rowId <= $lastId) {
                    continue;
                }
                $updates = $resolveUpdates($row);
                if ($updates === []) {
                    throw new \RuntimeException((string)__('Theme 布局身份哈希回填结果无效。'));
                }
                (clone $prototype)->clearData()->reset()
                    ->where($primaryKey, $rowId)
                    ->update($updates)
                    ->fetch();
                $nextId = max($nextId, $rowId);
            }
            if ($nextId <= $lastId) {
                throw new \RuntimeException((string)__('Theme 布局身份哈希回填无数据进展。'));
            }
            $lastId = $nextId;
            SchedulerSystem::yield();
            if (count($rows) < 500) {
                return;
            }
        }
    }

    private function widenScope(
        object $connection,
        string $table,
        string $after,
        string $default,
        string $primaryKey,
    ): void
    {
        if (!$this->columnExists($connection, $table, 'scope') || $this->isSqlite($connection)) {
            return;
        }
        $alter = $connection->alterTable()->forTable($table, $primaryKey, '');
        $alter->alterColumn(
            'scope',
            'scope',
            '',
            TableInterface::column_type_VARCHAR,
            400,
            "NOT NULL DEFAULT '" . str_replace("'", "''", $default) . "'",
            'Canonical Theme Scope path',
        );
        $alter->alter();
    }

    private function isSqlite(object $connection): bool
    {
        return str_contains(strtolower($connection::class), 'sqlite');
    }

    /** @param array<string,array{0:string,1:list<string>}> $indexes */
    private function replaceIndexes(object $connection, string $table, array $indexes): void
    {
        foreach ($indexes as $name => [$type, $columns]) {
            if ($connection->hasIndex($table, $name)) {
                $connection->query($connection->buildDropIndexSql(
                    $connection->formatTableName($table),
                    $name,
                ))->fetch();
            }
            $connection->query($connection->buildAddIndexSql($connection->formatTableName($table), [
                'name' => $name,
                'type' => $type,
                'columns' => $columns,
            ]))->fetch();
        }
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return (bool)$connection->hasField($table, $field);
        }
        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? '';
            if (strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }
        return false;
    }
}
