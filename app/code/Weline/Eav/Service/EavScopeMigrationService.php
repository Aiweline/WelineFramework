<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Scope\EavScopeColumns;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavEntity;
use Weline\Framework\Manager\ObjectManager;

/**
 * P1B-005 骨架 + TASK-MIG-P1A 行级 cutover。
 *
 * - help / preflight / ensureColumns：P1B-005
 * - apply：遗留行（scope_kind IS NULL）确定性映成 typed explicit；空值不猜 cleared；产品表跳过
 */
final class EavScopeMigrationService
{
    public function __construct(
        private readonly EavEntity $entityModel,
        private readonly Type $typeModel,
    ) {
    }

    /**
     * @return array{mode:string,notes:list<string>,columns:list<string>}
     */
    public function help(): array
    {
        return [
            'mode' => 'help',
            'columns' => EavScopeColumns::ALL,
            'notes' => [
                'P1B-005 交付 additive 列、typed 读写、legacy 只读适配与本工具骨架。',
                'scope_kind IS NULL = 遗留行；空值不得猜成 cleared。',
                'ensureColumns 仅 ADD COLUMN IF NOT EXISTS，不回填数据。',
                'apply/cutover 由 scope:migrate-p1a 在隔离 clone 编排（TEST-MIG-P1A-07）。',
                'CLI：php bin/w eav:scope:migrate help|preflight|ensure-columns',
            ],
        ];
    }

    /**
     * @return array{
     *   value_tables:int,
     *   with_scope_columns:int,
     *   missing_scope_columns:int,
     *   legacy_rows_sample_tables:int,
     *   tables:list<array{table:string,has_scope:bool,legacy_estimate:int|null}>
     * }
     */
    public function preflight(): array
    {
        $tables = $this->listValueTables();
        $report = [];
        $with = 0;
        $missing = 0;
        $legacySample = 0;
        foreach ($tables as $table) {
            $has = $this->tableHasColumn($table, EavScopeColumns::SCOPE_KIND);
            if ($has) {
                ++$with;
                $legacy = $this->countLegacyRows($table);
                if ($legacy > 0) {
                    ++$legacySample;
                }
            } else {
                ++$missing;
                $legacy = null;
            }
            $report[] = [
                'table' => $table,
                'has_scope' => $has,
                'legacy_estimate' => $legacy,
            ];
        }

        return [
            'value_tables' => \count($tables),
            'with_scope_columns' => $with,
            'missing_scope_columns' => $missing,
            'legacy_rows_sample_tables' => $legacySample,
            'tables' => $report,
        ];
    }

    /**
     * @return array{altered:list<string>,skipped:list<string>,errors:list<string>}
     */
    public function ensureColumns(): array
    {
        $altered = [];
        $skipped = [];
        $errors = [];
        foreach ($this->listValueTables() as $table) {
            if ($this->tableHasColumn($table, EavScopeColumns::SCOPE_KIND)) {
                $skipped[] = $table;
                continue;
            }
            try {
                $this->alterAddScopeColumns($table);
                $altered[] = $table;
            } catch (\Throwable $e) {
                $errors[] = $table . ': ' . $e->getMessage();
            }
        }

        return [
            'altered' => $altered,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * TASK-MIG-P1A：遗留行 → typed explicit。
     *
     * 规则：
     * - 产品实体值表跳过（全新安装口径）；
     * - 无 website/store/channel 证据 → kind=global；
     * - website_id+website_code（或可解析 code）且无 store/channel → kind=website；
     * - 有 store_code 无 channel → kind=store（缺 website 则 ambiguous）；
     * - 有 channel_code → kind=channel（缺上级则 ambiguous）；
     * - 空 value 绝不写 is_cleared=1；
     * - ambiguous 保留 scope_kind NULL，计入报告。
     *
     * @return array{
     *   ensure:array<string,mixed>,
     *   tables_touched:int,
     *   legacy_before:int,
     *   stamped_global:int,
     *   stamped_website:int,
     *   stamped_store:int,
     *   stamped_channel:int,
     *   product_skipped_rows:int,
     *   ambiguous:int,
     *   conservation_ok:bool
     * }
     */
    public function apply(): array
    {
        $this->assertCurrentDatabaseIsolated();
        $ensure = $this->ensureColumns();
        $websiteCodes = $this->loadWebsiteCodeMap();

        $legacyBefore = 0;
        $stamped = [
            'global' => 0,
            'website' => 0,
            'store' => 0,
            'channel' => 0,
        ];
        $productSkipped = 0;
        $ambiguous = 0;
        $tablesTouched = 0;

        foreach ($this->listValueTables() as $table) {
            if (!$this->tableHasColumn($table, EavScopeColumns::SCOPE_KIND)) {
                continue;
            }
            if ($this->isProductValueTable($table)) {
                $productSkipped += $this->countLegacyRows($table);
                continue;
            }

            $rows = $this->fetchLegacyRows($table);
            if ($rows === []) {
                continue;
            }
            ++$tablesTouched;
            foreach ($rows as $row) {
                ++$legacyBefore;
                $decision = $this->classifyLegacyEavRow($row, $websiteCodes);
                if ($decision['decision'] === 'ambiguous') {
                    ++$ambiguous;
                    continue;
                }
                if (!$this->stampLegacyRow($table, $row, $decision)) {
                    ++$ambiguous;
                    continue;
                }
                $kind = (string)$decision['scope_kind'];
                if (isset($stamped[$kind])) {
                    ++$stamped[$kind];
                }
            }
        }

        $stampedTotal = $stamped['global'] + $stamped['website'] + $stamped['store'] + $stamped['channel'];

        return [
            'ensure' => $ensure,
            'tables_touched' => $tablesTouched,
            'legacy_before' => $legacyBefore,
            'stamped_global' => $stamped['global'],
            'stamped_website' => $stamped['website'],
            'stamped_store' => $stamped['store'],
            'stamped_channel' => $stamped['channel'],
            'product_skipped_rows' => $productSkipped,
            'ambiguous' => $ambiguous,
            'conservation_ok' => ($stampedTotal + $ambiguous) === $legacyBefore,
        ];
    }

    /**
     * @return array{legacy_rows:int,ambiguous:int,ok:bool}
     */
    public function verify(): array
    {
        $legacy = 0;
        $ambiguous = 0;
        $websiteCodes = $this->loadWebsiteCodeMap();
        foreach ($this->listValueTables() as $table) {
            if ($this->isProductValueTable($table) || !$this->tableHasColumn($table, EavScopeColumns::SCOPE_KIND)) {
                continue;
            }
            foreach ($this->fetchLegacyRows($table) as $row) {
                ++$legacy;
                $decision = $this->classifyLegacyEavRow($row, $websiteCodes);
                if ($decision['decision'] === 'ambiguous') {
                    ++$ambiguous;
                }
            }
        }

        // verify：不应再有可确定性 stamp 的遗留行；ambiguous 可保留但需报告
        $mappableLeft = $legacy - $ambiguous;

        return [
            'legacy_rows' => $legacy,
            'ambiguous' => $ambiguous,
            'mappable_left' => $mappableLeft,
            'ok' => $mappableLeft === 0,
        ];
    }

    /**
     * @return array<int, string> website_id => code
     */
    private function loadWebsiteCodeMap(): array
    {
        $pdo = $this->pdo();
        $map = [];
        foreach (['w_weline_websites_website', 'weline_websites_website', 'website'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $quoted = '"' . \str_replace('"', '""', $this->bareTable($table)) . '"';
            try {
                $stmt = $pdo->query("SELECT website_id, code FROM {$quoted}");
                if (!$stmt) {
                    continue;
                }
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $id = (int)($row['website_id'] ?? -1);
                    $code = \trim((string)($row['code'] ?? ''));
                    if ($id >= 0 && $code !== '') {
                        $map[$id] = $code;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $map;
    }

    private function isProductValueTable(string $table): bool
    {
        $bare = \strtolower($this->bareTable($table));
        $bare = \preg_replace('/^w_/', '', $bare) ?? $bare;

        return (bool)\preg_match('/^eav_product(_|$)/', $bare)
            || (bool)\preg_match('/^eav_catalog(_|$)/', $bare);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLegacyRows(string $table): array
    {
        $pdo = $this->pdo();
        $quoted = '"' . \str_replace('"', '""', $this->bareTable($table)) . '"';
        try {
            $stmt = $pdo->query(
                "SELECT value_id, attribute_id, entity_id, value, scope_kind, website_id, website_code,"
                . ' store_code, channel_code, is_cleared, locale'
                . " FROM {$quoted} WHERE scope_kind IS NULL"
            );
            if (!$stmt) {
                return [];
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return \is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $websiteCodes
     * @return array{
     *   decision:string,
     *   scope_kind:?string,
     *   website_id:?int,
     *   website_code:?string,
     *   store_code:?string,
     *   channel_code:?string,
     *   locale:string
     * }
     */
    private function classifyLegacyEavRow(array $row, array $websiteCodes): array
    {
        $locale = \trim((string)($row['locale'] ?? ''));
        $websiteIdRaw = $row['website_id'] ?? null;
        $websiteId = $websiteIdRaw === null || $websiteIdRaw === '' ? null : (int)$websiteIdRaw;
        $websiteCode = \trim((string)($row['website_code'] ?? ''));
        $storeCode = \trim((string)($row['store_code'] ?? ''));
        $channelCode = \trim((string)($row['channel_code'] ?? ''));

        if ($websiteCode === '' && $websiteId !== null && isset($websiteCodes[$websiteId])) {
            $websiteCode = $websiteCodes[$websiteId];
        }
        if ($websiteId === null && $websiteCode !== '') {
            foreach ($websiteCodes as $id => $code) {
                if ($code === $websiteCode) {
                    $websiteId = $id;
                    break;
                }
            }
        }

        $hasWebsite = $websiteId !== null && $websiteCode !== '';
        $hasStore = $storeCode !== '';
        $hasChannel = $channelCode !== '';

        // 无任何 Scope 证据 → 确定性 global（含零证据遗留行）
        if (!$hasWebsite && !$hasStore && !$hasChannel && $websiteId === null && $websiteCode === '') {
            return [
                'decision' => 'stamp',
                'scope_kind' => \Weline\Framework\Runtime\ScopeIdentity::KIND_GLOBAL,
                'website_id' => null,
                'website_code' => null,
                'store_code' => null,
                'channel_code' => null,
                'locale' => $locale,
            ];
        }

        if ($hasChannel) {
            if (!$hasWebsite || !$hasStore) {
                return $this->ambiguousDecision($locale);
            }

            return [
                'decision' => 'stamp',
                'scope_kind' => \Weline\Framework\Runtime\ScopeIdentity::KIND_CHANNEL,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
                'locale' => $locale,
            ];
        }

        if ($hasStore) {
            if (!$hasWebsite) {
                return $this->ambiguousDecision($locale);
            }

            return [
                'decision' => 'stamp',
                'scope_kind' => \Weline\Framework\Runtime\ScopeIdentity::KIND_STORE,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => null,
                'locale' => $locale,
            ];
        }

        if ($hasWebsite) {
            return [
                'decision' => 'stamp',
                'scope_kind' => \Weline\Framework\Runtime\ScopeIdentity::KIND_WEBSITE,
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'store_code' => null,
                'channel_code' => null,
                'locale' => $locale,
            ];
        }

        return $this->ambiguousDecision($locale);
    }

    /**
     * @return array{
     *   decision:string,
     *   scope_kind:?string,
     *   website_id:?int,
     *   website_code:?string,
     *   store_code:?string,
     *   channel_code:?string,
     *   locale:string
     * }
     */
    private function ambiguousDecision(string $locale): array
    {
        return [
            'decision' => 'ambiguous',
            'scope_kind' => null,
            'website_id' => null,
            'website_code' => null,
            'store_code' => null,
            'channel_code' => null,
            'locale' => $locale,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{
     *   decision:string,
     *   scope_kind:?string,
     *   website_id:?int,
     *   website_code:?string,
     *   store_code:?string,
     *   channel_code:?string,
     *   locale:string
     * } $decision
     */
    private function stampLegacyRow(string $table, array $row, array $decision): bool
    {
        $valueId = (int)($row['value_id'] ?? 0);
        if ($valueId <= 0 || ($decision['scope_kind'] ?? null) === null) {
            return false;
        }
        $pdo = $this->pdo();
        $quoted = '"' . \str_replace('"', '""', $this->bareTable($table)) . '"';
        $sql = "UPDATE {$quoted} SET"
            . ' scope_kind = :scope_kind,'
            . ' website_id = :website_id,'
            . ' website_code = :website_code,'
            . ' store_code = :store_code,'
            . ' channel_code = :channel_code,'
            . ' is_cleared = 0,'
            . ' locale = :locale'
            . ' WHERE value_id = :value_id AND scope_kind IS NULL';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':scope_kind', $decision['scope_kind']);
            if ($decision['website_id'] === null) {
                $stmt->bindValue(':website_id', null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':website_id', $decision['website_id'], \PDO::PARAM_INT);
            }
            foreach (['website_code', 'store_code', 'channel_code'] as $col) {
                $val = $decision[$col] ?? null;
                if ($val === null) {
                    $stmt->bindValue(':' . $col, null, \PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(':' . $col, $val);
                }
            }
            $stmt->bindValue(':locale', $decision['locale'] ?? '');
            $stmt->bindValue(':value_id', $valueId, \PDO::PARAM_INT);

            return $stmt->execute() && $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertCurrentDatabaseIsolated(): void
    {
        /** @var EntityAttributeValueTable $probe */
        $probe = ObjectManager::getInstance(EntityAttributeValueTable::class);
        $probe->useLogicalTable('eav_attribute');
        $config = $probe->getConnection()->getConnector()->getConfigProvider();
        $type = \strtolower((string)$config->getDbType());
        $database = \strtolower(\trim($config->getDatabase()));
        $path = \method_exists($config, 'getData')
            ? (string)$config->getData('path')
            : '';
        if ($database === '' && $type === 'sqlite' && $path !== '') {
            $database = \strtolower((string)\pathinfo($path, \PATHINFO_FILENAME));
        }
        (new \Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard())->assertIsolatedDatabase([
            'type' => $type,
            'hostname' => (string)$config->getHostName(),
            'hostport' => \method_exists($config, 'getData')
                ? (string)$config->getData('hostport')
                : '',
            'database' => $database,
            'username' => (string)$config->getUsername(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function listValueTables(): array
    {
        $fromRegistry = [];
        $entities = (clone $this->entityModel)->clear()->select()->fetch()->getItems();
        $types = (clone $this->typeModel)->clear()->select()->fetch()->getItems();
        foreach ($entities as $entity) {
            if (!$entity instanceof EavEntity) {
                continue;
            }
            $entityCode = \strtolower(\trim((string)$entity->getCode()));
            if ($entityCode === '') {
                continue;
            }
            foreach ($types as $type) {
                if (!$type instanceof Type) {
                    continue;
                }
                $typeCode = \strtolower(\trim((string)$type->getCode()));
                if ($typeCode === '') {
                    continue;
                }
                $logical = 'eav_' . $entityCode . '_' . $typeCode;
                $physical = $this->physicalTableName($logical);
                if ($this->tableExists($physical)) {
                    $fromRegistry[] = $physical;
                }
            }
        }

        $pdo = $this->pdo();
        $stmt = $pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = current_schema()
               AND (
                 table_name LIKE 'eav\\_%\\_%' ESCAPE '\\'
                 OR table_name LIKE 'w\\_eav\\_%\\_%' ESCAPE '\\'
               )
               AND table_name NOT LIKE '%\\_local\\_%' ESCAPE '\\'
               AND table_name NOT IN (
                 'eav_entity','eav_attribute','eav_attribute_type','eav_attribute_set','eav_attribute_group','eav_attribute_option',
                 'w_eav_entity','w_eav_attribute','w_eav_attribute_type','w_eav_attribute_set','w_eav_attribute_group','w_eav_attribute_option'
               )"
        );
        $scanned = [];
        if ($stmt) {
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $name) {
                $name = (string)$name;
                if ($name === '' || \str_contains($name, '_local_')) {
                    continue;
                }
                if (\preg_match('/^(?:w_)?eav_[a-z0-9]+_.+$/', $name) !== 1) {
                    continue;
                }
                $meta = [
                    'eav_entity', 'eav_attribute', 'eav_attribute_type', 'eav_attribute_set',
                    'eav_attribute_group', 'eav_attribute_option',
                ];
                $bare = \preg_replace('/^w_/', '', $name) ?? $name;
                if (\in_array($bare, $meta, true)) {
                    continue;
                }
                $scanned[] = $name;
            }
        }

        return \array_values(\array_unique(\array_merge($fromRegistry, $scanned)));
    }

    private function physicalTableName(string $logical): string
    {
        /** @var EntityAttributeValueTable $probe */
        $probe = ObjectManager::getInstance(EntityAttributeValueTable::class);
        $probe->useLogicalTable($logical);

        return \str_replace('"', '', $probe->getTable());
    }

    private function tableExists(string $table): bool
    {
        $bare = $this->bareTable($table);
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1'
        );
        $stmt->execute([$bare]);

        return (bool)$stmt->fetchColumn();
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $stmt->execute([$this->bareTable($table), $column]);

        return (bool)$stmt->fetchColumn();
    }

    private function countLegacyRows(string $table): int
    {
        $pdo = $this->pdo();
        $quoted = '"' . \str_replace('"', '""', $this->bareTable($table)) . '"';
        try {
            $count = $pdo->query(
                "SELECT COUNT(*) FROM {$quoted} WHERE scope_kind IS NULL"
            )->fetchColumn();

            return (int)$count;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function alterAddScopeColumns(string $table): void
    {
        $quoted = '"' . \str_replace('"', '""', $this->bareTable($table)) . '"';
        $sql = <<<SQL
ALTER TABLE {$quoted}
  ADD COLUMN IF NOT EXISTS scope_kind varchar(16) NULL,
  ADD COLUMN IF NOT EXISTS website_id integer NULL,
  ADD COLUMN IF NOT EXISTS website_code varchar(64) NULL,
  ADD COLUMN IF NOT EXISTS store_code varchar(64) NULL,
  ADD COLUMN IF NOT EXISTS channel_code varchar(64) NULL,
  ADD COLUMN IF NOT EXISTS is_cleared smallint NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS locale varchar(16) NOT NULL DEFAULT ''
SQL;
        $this->pdo()->exec($sql);
    }

    private function bareTable(string $table): string
    {
        $table = \str_replace('"', '', $table);
        if (\str_contains($table, '.')) {
            $parts = \explode('.', $table);

            return (string)\end($parts);
        }

        return $table;
    }

    private function pdo(): \PDO
    {
        /** @var EntityAttributeValueTable $probe */
        $probe = ObjectManager::getInstance(EntityAttributeValueTable::class);
        $probe->useLogicalTable('eav_attribute');
        $connector = $probe->getConnection()->getConnector();
        if (\method_exists($connector, 'getWrappedConnection')) {
            $wrapped = $connector->getWrappedConnection();
            if ($wrapped instanceof \PDO) {
                return $wrapped;
            }
            if (\is_object($wrapped) && \method_exists($wrapped, 'getPdo')) {
                return $wrapped->getPdo();
            }
        }
        throw new \RuntimeException('eav_scope_migrate_pdo_unavailable');
    }
}
