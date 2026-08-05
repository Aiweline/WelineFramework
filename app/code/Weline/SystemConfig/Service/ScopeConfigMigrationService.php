<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Database\Migration\MigrationManifest;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Database\Migration\Service\MigrationTargetBinder;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;

/**
 * MIG-P1B：SystemConfig / Meta / Theme 短 Scope → 规范三段映射。
 *
 * - 裸 `default` / 空串 → conflict（不写零号站、不擅自升 Global）
 * - 已三段 → already
 * - 1/2 段确定映射；目标撞车 → conflict
 * - apply 必须隔离 clone；rollback 不恢复短 Scope write
 */
final class ScopeConfigMigrationService
{
    public const STATUS_ALREADY = 'already';
    public const STATUS_MAPPED = 'mapped';
    public const STATUS_CONFLICT = 'conflict';

    public const REASON_AMBIGUOUS_BARE_DEFAULT = 'ambiguous_bare_default';
    public const REASON_TARGET_COLLISION = 'target_collision';
    public const REASON_INVALID_SHAPE = 'invalid_scope_shape';

    public function __construct(
        private readonly SystemConfigScopeResolver $scopeResolver,
    ) {
    }

    /**
     * 纯函数映射（TEST-MIG-P1B-01/02）。
     *
     * @return array{
     *   status:string,
     *   source:string,
     *   target:?string,
     *   reason:?string
     * }
     */
    public function mapLegacyScope(string $rawScope): array
    {
        $raw = \strtolower(\trim($rawScope));
        $modeSuffix = '';
        if (\str_contains($raw, ThemeLayoutScopeNormalizer::MODE_SEPARATOR)) {
            [$scopePart, $modePart] = \explode(ThemeLayoutScopeNormalizer::MODE_SEPARATOR, $raw, 2);
            $raw = \strtolower(\trim($scopePart));
            $modePart = \strtolower(\trim($modePart));
            if ($modePart !== '' && $modePart !== 'normal') {
                $modeSuffix = ThemeLayoutScopeNormalizer::MODE_SEPARATOR . $modePart;
            }
        }

        if ($raw === '' || $raw === 'default') {
            return [
                'status' => self::STATUS_CONFLICT,
                'source' => $rawScope,
                'target' => null,
                'reason' => self::REASON_AMBIGUOUS_BARE_DEFAULT,
            ];
        }

        $parts = \array_values(\array_filter(
            \array_map('trim', \explode('.', $raw)),
            static fn(string $p): bool => $p !== '',
        ));

        if ($parts === []) {
            return [
                'status' => self::STATUS_CONFLICT,
                'source' => $rawScope,
                'target' => null,
                'reason' => self::REASON_AMBIGUOUS_BARE_DEFAULT,
            ];
        }

        if (\count($parts) > 3) {
            return [
                'status' => self::STATUS_CONFLICT,
                'source' => $rawScope,
                'target' => null,
                'reason' => self::REASON_INVALID_SHAPE,
            ];
        }

        if (\count($parts) === 3) {
            $canonical = \implode('.', $parts);
            // 已是规范三段（含哨兵）→ 无需迁移
            return [
                'status' => self::STATUS_ALREADY,
                'source' => $rawScope,
                'target' => $canonical . $modeSuffix,
                'reason' => null,
            ];
        }

        if (\count($parts) === 1) {
            // website_code → website 层存储（default 网站用哨兵，其它用 code.default.default）
            $website = $parts[0];
            if ($website === 'default') {
                return [
                    'status' => self::STATUS_CONFLICT,
                    'source' => $rawScope,
                    'target' => null,
                    'reason' => self::REASON_AMBIGUOUS_BARE_DEFAULT,
                ];
            }
            $target = $website . '.default.default';

            return [
                'status' => self::STATUS_MAPPED,
                'source' => $rawScope,
                'target' => $target . $modeSuffix,
                'reason' => null,
            ];
        }

        // 2 段：website.store → store 层
        $target = $parts[0] . '.' . $parts[1] . '.default';

        return [
            'status' => self::STATUS_MAPPED,
            'source' => $rawScope,
            'target' => $target . $modeSuffix,
            'reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preflight(): array
    {
        $scan = $this->scanTables();

        return [
            'ok' => ($scan['conflicts'] ?? 0) === 0 || true, // preflight 允许有 conflict，仅报告
            'short_scope_rows' => $scan['short_scope_rows'],
            'mappable' => $scan['mappable'],
            'already_canonical' => $scan['already_canonical'],
            'conflicts' => $scan['conflicts'],
            'conflict_samples' => $scan['conflict_samples'],
            'tables' => $scan['tables'],
            'short_scope_write_forbidden' => true,
        ];
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array<string, mixed>
     */
    public function apply(?array $targetDb = null): array
    {
        $db = $this->requireIsolatedTarget($targetDb);
        ObjectManager::clearInstances();
        $binder = ObjectManager::getInstance(MigrationTargetBinder::class);
        $bind = $binder->bindIsolated($db);

        /** @var MigrationCloneService $cloneService */
        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
        $checkpoint = new MigrationCheckpointService(
            $cloneService->guardedFingerprint(),
            new MigrationCheckpointJournalStore(),
        );
        /** @var self $self */
        $self = ObjectManager::getInstance(self::class);

        $fp = (string)$bind['fingerprint'];
        $checkpointId = 'p1b-' . \gmdate('YmdHis') . '-' . \substr(\bin2hex(\random_bytes(3)), 0, 6);
        $manifest = MigrationManifest::fromArray([
            'checkpoint_id' => $checkpointId,
            'phase' => 'p1b-apply',
            'repo' => 'framework',
            'branch' => 'local',
            'commit' => 'mig-p1b',
            'connector_fingerprint' => $fp,
            'schema_fingerprints' => [],
            'row_counts' => [],
            'row_hashes' => [],
            'watermarks' => ['system_config' => 0],
            'backup_ref' => 'clone:' . $db['database'],
            'created_at' => \gmdate('c'),
        ]);
        $checkpoint->checkpoint($manifest);
        $checkpoint->applyGuard([
            'type' => (string)($db['type'] ?? 'pgsql'),
            'hostname' => (string)($db['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($db['hostport'] ?? '5432'),
            'database' => (string)$db['database'],
            'username' => (string)($db['username'] ?? ''),
        ], $checkpointId, $manifest);

        $result = $self->applyBound();
        $checkpoint->appendJournal($checkpointId, 'p1b_apply_done', [
            'mapped' => $result['mapped'] ?? 0,
            'conflicts' => $result['conflicts'] ?? 0,
        ]);

        return \array_merge($result, [
            'checkpoint_id' => $checkpointId,
            'manifest_hash' => $manifest->hash(),
            'database' => (string)$db['database'],
            'fingerprint' => $fp,
            'short_scope_write_forbidden' => true,
            'canonical_write_relaxed' => false,
        ]);
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array<string, mixed>
     */
    public function verify(?array $targetDb = null): array
    {
        if ($targetDb !== null && \trim((string)($targetDb['database'] ?? '')) !== '') {
            $db = $this->requireIsolatedTarget($targetDb);
            ObjectManager::clearInstances();
            ObjectManager::getInstance(MigrationTargetBinder::class)->bindIsolated($db);
            /** @var self $self */
            $self = ObjectManager::getInstance(self::class);
            $scan = $self->scanTables();
        } else {
            $scan = $this->scanTables();
        }

        $unfinished = (int)$scan['mappable'];
        $conflicts = (int)$scan['conflicts'];

        return [
            'ok' => $unfinished === 0,
            'unfinished_mappable' => $unfinished,
            'conflicts' => $conflicts,
            'already_canonical' => $scan['already_canonical'],
            'short_scope_write_forbidden' => true,
            'tables' => $scan['tables'],
        ];
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array<string, mixed>
     */
    public function rollback(?array $targetDb = null): array
    {
        if ($targetDb !== null && \trim((string)($targetDb['database'] ?? '')) !== '') {
            $this->requireIsolatedTarget($targetDb);
        }

        // 探测短 scope write 仍被拒绝
        $shortWriteBlocked = false;
        try {
            $this->scopeResolver->assertWritableRawScope('default');
        } catch (\InvalidArgumentException) {
            $shortWriteBlocked = true;
        }

        return [
            'ok' => true,
            'message' => (string)__(
                'MIG-P1B rollback：保留已映射三段 Scope 与 additive 结构；'
                . '兼容 reader 可读历史短 Scope 行；canonical writer 继续；短 Scope write 永不恢复。'
            ),
            'additive_columns_retained' => true,
            'canonical_write_relaxed' => false,
            'short_scope_write_forbidden' => $shortWriteBlocked,
            'short_scope_write_restored' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyBound(): array
    {
        $scan = $this->scanTables(true);
        $mapped = 0;
        $conflicts = 0;
        $already = 0;
        $details = [];

        foreach ($scan['plans'] as $plan) {
            if ($plan['status'] === self::STATUS_ALREADY) {
                $already++;
                continue;
            }
            if ($plan['status'] === self::STATUS_CONFLICT) {
                $conflicts++;
                $details[] = [
                    'table' => $plan['table'],
                    'id' => $plan['id'],
                    'source' => $plan['source'],
                    'reason' => $plan['reason'],
                ];
                continue;
            }
            if ($this->rowExistsAtTarget($plan['table'], $plan['identity'], (string)$plan['target'])) {
                $conflicts++;
                $details[] = [
                    'table' => $plan['table'],
                    'id' => $plan['id'],
                    'source' => $plan['source'],
                    'target' => $plan['target'],
                    'reason' => self::REASON_TARGET_COLLISION,
                ];
                continue;
            }
            $this->updateScope($plan);
            $mapped++;
        }

        return [
            'ok' => true,
            'mapped' => $mapped,
            'already' => $already,
            'conflicts' => $conflicts,
            'conflict_details' => \array_slice($details, 0, 50),
            'tables' => $scan['tables'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scanTables(bool $withPlans = false): array
    {
        $tables = [];
        $short = 0;
        $mappable = 0;
        $already = 0;
        $conflicts = 0;
        $conflictSamples = [];
        $plans = [];

        foreach ($this->tableSpecs() as $spec) {
            try {
                $rows = $this->fetchScopeRows($spec);
            } catch (\Throwable $e) {
                $tables[$spec['label']] = ['error' => $e->getMessage()];
                continue;
            }
            $tableShort = 0;
            $tableMapped = 0;
            $tableConflict = 0;
            $tableAlready = 0;
            foreach ($rows as $row) {
                $source = (string)($row['scope'] ?? '');
                $map = $this->mapLegacyScope($source);
                $isShort = $this->isShortScope($source);
                if ($map['status'] === self::STATUS_ALREADY && !$isShort) {
                    $tableAlready++;
                    $already++;
                    if ($withPlans) {
                        $plans[] = $this->planFrom($spec, $row, $map);
                    }
                    continue;
                }
                if ($isShort || $map['status'] !== self::STATUS_ALREADY) {
                    $short++;
                    $tableShort++;
                }
                if ($map['status'] === self::STATUS_MAPPED) {
                    $target = (string)($map['target'] ?? '');
                    if ($target !== '' && $this->rowExistsAtTarget($spec['label'], $row['identity'], $target)) {
                        $map = [
                            'status' => self::STATUS_CONFLICT,
                            'source' => $source,
                            'target' => $target,
                            'reason' => self::REASON_TARGET_COLLISION,
                        ];
                        $conflicts++;
                        $tableConflict++;
                        if (\count($conflictSamples) < 20) {
                            $conflictSamples[] = [
                                'table' => $spec['label'],
                                'id' => $row['id'] ?? null,
                                'scope' => $source,
                                'target' => $target,
                                'reason' => self::REASON_TARGET_COLLISION,
                            ];
                        }
                    } else {
                        $mappable++;
                        $tableMapped++;
                    }
                } elseif ($map['status'] === self::STATUS_CONFLICT) {
                    $conflicts++;
                    $tableConflict++;
                    if (\count($conflictSamples) < 20) {
                        $conflictSamples[] = [
                            'table' => $spec['label'],
                            'id' => $row['id'] ?? null,
                            'scope' => $source,
                            'reason' => $map['reason'],
                        ];
                    }
                } elseif ($map['status'] === self::STATUS_ALREADY) {
                    $tableAlready++;
                    $already++;
                }
                if ($withPlans && ($isShort || $map['status'] !== self::STATUS_ALREADY)) {
                    $plans[] = $this->planFrom($spec, $row, $map);
                }
            }
            $tables[$spec['label']] = [
                'rows' => \count($rows),
                'short' => $tableShort,
                'mappable' => $tableMapped,
                'conflicts' => $tableConflict,
                'already' => $tableAlready,
            ];
        }

        $out = [
            'short_scope_rows' => $short,
            'mappable' => $mappable,
            'already_canonical' => $already,
            'conflicts' => $conflicts,
            'conflict_samples' => $conflictSamples,
            'tables' => $tables,
        ];
        if ($withPlans) {
            $out['plans'] = $plans;
        }

        return $out;
    }

    /**
     * @return list<array{label:string,model:class-string,pk:string,scope_field:string,identity_fields:list<string>}>
     */
    private function tableSpecs(): array
    {
        $specs = [
            [
                'label' => 'system_config',
                'model' => SystemConfig::class,
                'pk' => null,
                'scope_field' => SystemConfig::schema_fields_SCOPE,
                'identity_fields' => [
                    SystemConfig::schema_fields_MODULE,
                    SystemConfig::schema_fields_AREA,
                    SystemConfig::schema_fields_KEY,
                    SystemConfig::schema_fields_LOCALE,
                ],
            ],
        ];
        if (\class_exists(\Weline\Meta\Model\MetaConfig::class)) {
            $specs[] = [
                'label' => 'meta_config',
                'model' => \Weline\Meta\Model\MetaConfig::class,
                'pk' => 'config_id',
                'scope_field' => 'scope',
                'identity_fields' => ['namespace', 'config_key', 'locale', 'identify_id', 'meta_id', 'meta_identify'],
            ];
        }
        if (\class_exists(\Weline\Theme\Model\ThemeLayout::class)) {
            $specs[] = [
                'label' => 'theme_layout',
                'model' => \Weline\Theme\Model\ThemeLayout::class,
                'pk' => 'layout_id',
                'scope_field' => 'scope',
                'identity_fields' => [
                    'theme_id', 'page_type', 'layout_option', 'target_type', 'target_id', 'status', 'area', 'slot_id',
                ],
            ];
        }

        return $specs;
    }

    /**
     * @param array{
     *   label:string,
     *   model:class-string,
     *   pk:?string,
     *   scope_field:string,
     *   identity_fields:list<string>
     * } $spec
     * @return list<array<string,mixed>>
     */
    private function fetchScopeRows(array $spec): array
    {
        /** @var \Weline\Framework\Database\Model $model */
        $model = ObjectManager::getInstance($spec['model'], [], false);
        $connector = $model->getConnection()->getConnector();
        $table = $connector->getTable($model->getOriginTableName());
        $scopeField = $spec['scope_field'];
        $cols = \array_values(\array_unique(\array_merge(
            $spec['pk'] !== null ? [$spec['pk']] : [],
            [$scopeField],
            $spec['identity_fields'],
        )));
        $colSql = \implode(', ', $cols);
        $sql = "SELECT {$colSql} FROM {$table}";
        $stmt = $connector->getLink()->query($sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $identity = [];
            foreach ($spec['identity_fields'] as $f) {
                $identity[$f] = $row[$f] ?? null;
            }
            $out[] = [
                'id' => $spec['pk'] !== null ? ($row[$spec['pk']] ?? null) : null,
                'scope' => (string)($row[$scopeField] ?? ''),
                'identity' => $identity,
            ];
        }

        return $out;
    }

    /**
     * @param array{label:string,model:class-string,pk:?string,scope_field:string,identity_fields:list<string>} $spec
     * @param array{id:mixed,scope:string,identity:array<string,mixed>} $row
     * @param array{status:string,source:string,target:?string,reason:?string} $map
     * @return array<string,mixed>
     */
    private function planFrom(array $spec, array $row, array $map): array
    {
        return [
            'table' => $spec['label'],
            'model' => $spec['model'],
            'pk' => $spec['pk'],
            'id' => $row['id'],
            'source' => (string)$row['scope'],
            'target' => $map['target'],
            'status' => $map['status'],
            'reason' => $map['reason'],
            'identity' => $row['identity'],
            'scope_field' => $spec['scope_field'],
        ];
    }

    /**
     * @param array<string,mixed> $identity
     */
    private function rowExistsAtTarget(string $label, array $identity, string $targetScope): bool
    {
        foreach ($this->tableSpecs() as $spec) {
            if ($spec['label'] !== $label) {
                continue;
            }
            /** @var \Weline\Framework\Database\Model $model */
            $model = ObjectManager::getInstance($spec['model'], [], false);
            $connector = $model->getConnection()->getConnector();
            $table = $connector->getTable($model->getOriginTableName());
            $where = [$spec['scope_field'] . ' = :scope'];
            $params = [':scope' => $targetScope];
            $i = 0;
            foreach ($spec['identity_fields'] as $f) {
                $key = ':p' . $i++;
                if (($identity[$f] ?? null) === null) {
                    $where[] = $f . ' IS NULL';
                } else {
                    $where[] = $f . ' = ' . $key;
                    $params[$key] = $identity[$f];
                }
            }
            $sql = 'SELECT 1 FROM ' . $table . ' WHERE ' . \implode(' AND ', $where) . ' LIMIT 1';
            $stmt = $connector->getLink()->prepare($sql);
            $stmt->execute($params);

            return (bool)$stmt->fetchColumn();
        }

        return false;
    }

    /**
     * @param array{
     *   table:string,
     *   model:class-string,
     *   pk:?string,
     *   id:mixed,
     *   source:string,
     *   target:string,
     *   identity:array<string,mixed>,
     *   scope_field:string
     * } $plan
     */
    private function updateScope(array $plan): void
    {
        /** @var \Weline\Framework\Database\Model $model */
        $model = ObjectManager::getInstance($plan['model'], [], false);
        $connector = $model->getConnection()->getConnector();
        $table = $connector->getTable($model->getOriginTableName());
        $scopeField = $plan['scope_field'];

        if ($plan['pk'] !== null && $plan['id'] !== null && $plan['id'] !== '') {
            $sql = 'UPDATE ' . $table . ' SET ' . $scopeField . ' = :target WHERE ' . $plan['pk'] . ' = :id';
            $stmt = $connector->getLink()->prepare($sql);
            $stmt->execute([':target' => $plan['target'], ':id' => $plan['id']]);

            return;
        }

        // SystemConfig：复合主键，用 identity + 旧 scope 定位
        $where = [$scopeField . ' = :source'];
        $params = [':target' => $plan['target'], ':source' => $plan['source']];
        $i = 0;
        foreach ($plan['identity'] as $f => $value) {
            $key = ':p' . $i++;
            if ($value === null) {
                $where[] = $f . ' IS NULL';
            } else {
                $where[] = $f . ' = ' . $key;
                $params[$key] = $value;
            }
        }
        $sql = 'UPDATE ' . $table . ' SET ' . $scopeField . ' = :target WHERE ' . \implode(' AND ', $where);
        $stmt = $connector->getLink()->prepare($sql);
        $stmt->execute($params);
    }

    private function isShortScope(string $scope): bool
    {
        $raw = \strtolower(\trim($scope));
        if (\str_contains($raw, ThemeLayoutScopeNormalizer::MODE_SEPARATOR)) {
            $raw = \explode(ThemeLayoutScopeNormalizer::MODE_SEPARATOR, $raw, 2)[0];
        }
        $parts = \array_values(\array_filter(
            \array_map('trim', \explode('.', $raw)),
            static fn(string $p): bool => $p !== '',
        ));

        return $parts === [] || \count($parts) < 3;
    }

    /**
     * @param array{hostname?:string,hostport?:int|string,database?:string,username?:string,password?:string,type?:string}|null $targetDb
     * @return array{hostname:string,hostport:string,database:string,username:string,password:string,type:string}
     */
    private function requireIsolatedTarget(?array $targetDb): array
    {
        if ($targetDb === null || \trim((string)($targetDb['database'] ?? '')) === '') {
            throw new \RuntimeException(
                'mig_p1b_requires_isolated_database: pass --database=mig_clone_* '
                . '(create via php bin/w mig:foundation clone-create --purpose=p1b)'
            );
        }
        $database = \strtolower(\trim((string)$targetDb['database']));
        /** @var MigrationCloneService $cloneService */
        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
        $guard = $cloneService->list() === []
            ? new \Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard()
            : $cloneService->guardedFingerprint();
        $config = [
            'type' => (string)($targetDb['type'] ?? 'pgsql'),
            'hostname' => (string)($targetDb['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($targetDb['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($targetDb['username'] ?? ''),
        ];
        $guard->assertIsolatedDatabase($config);

        return [
            'type' => $config['type'],
            'hostname' => $config['hostname'],
            'hostport' => $config['hostport'],
            'database' => $database,
            'username' => $config['username'],
            'password' => (string)($targetDb['password'] ?? ''),
        ];
    }
}
