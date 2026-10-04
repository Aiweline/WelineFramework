<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;

/**
 * 一次升级：把旧前台 theme_binding / 激活标记迁入 websites_theme_application。
 * 后台应用属于系统配置，不迁入网站表。
 * 正式请求不得调用；备份必须先于任何写入。
 */
final class LegacyThemeApplicationMigration
{
    public function __construct(
        private readonly ThemeApplicationReferenceReaderInterface $reader,
        private readonly ScopeHierarchyInterface $hierarchy,
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly ThemeApplicationInterface $applications,
    ) {
    }

    /**
     * @return array{
     *   saved:int,
     *   unresolved:list<array<string,mixed>>,
     *   backup_path:string
     * }
     */
    public function migrate(string $backupDirectory): array
    {
        $backupDirectory = rtrim($backupDirectory, "/\\");
        if ($backupDirectory === '') {
            throw new \InvalidArgumentException('website_theme_application_migration_backup_directory_invalid');
        }
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('website_theme_application_migration_backup_directory_unwritable');
        }

        $export = $this->reader->exportLegacyApplicationSnapshot();
        if (($export['schema'] ?? '') !== 'theme-application-legacy-export.v1') {
            throw new \RuntimeException('website_theme_application_legacy_export_schema_invalid');
        }

        $planned = [];
        $unresolved = array_values(array_filter(
            (array)($export['unresolved'] ?? []),
            static fn(mixed $row): bool => is_array($row),
        ));

        foreach ((array)($export['bindings'] ?? []) as $binding) {
            if (!is_array($binding)) {
                continue;
            }
            if (($binding['area'] ?? '') !== 'frontend' || !(bool)($binding['own_intent'] ?? false)) {
                continue;
            }
            try {
                $planned[] = $this->planApplication($binding);
            } catch (\Throwable $error) {
                $unresolved[] = $binding + [
                    'reason' => 'website_theme_application_migration_plan_failed',
                    'error' => $error->getMessage(),
                ];
            }
        }

        foreach ((array)($export['active_defaults'] ?? []) as $area => $default) {
            if (!is_string($area) || !is_array($default)) {
                continue;
            }
            if ($area !== 'frontend') {
                continue;
            }
            $themeId = (int)($default['theme_id'] ?? 0);
            if ($themeId < 1) {
                continue;
            }
            $globalKey = $this->catalog->authoritativeIdentity(
                $this->hierarchy->fromStorageScope('default.default.default', true)
                    ?? throw new \RuntimeException('website_theme_application_migration_global_scope_missing'),
            )->canonicalKey();
            $alreadyPlanned = false;
            foreach ($planned as $item) {
                if ($item['scope_key'] === $globalKey
                    && $item['store_mode'] === 'normal'
                    && $item['area'] === $area) {
                    $alreadyPlanned = true;
                    break;
                }
            }
            if ($alreadyPlanned) {
                continue;
            }
            $current = $this->applications->getOwn($globalKey, 'normal', $area);
            if ($current['reference'] !== null) {
                continue;
            }
            try {
                $planned[] = $this->planApplication([
                    'scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => $area,
                    'theme_id' => $themeId,
                    'theme_version_id' => 0,
                    'content_revision' => 0,
                    'owner_scope' => 'default.default.default',
                    'owner_store_mode' => 'normal',
                    'own_intent' => true,
                    'source_kind' => (string)($default['source_kind'] ?? 'legacy_active_marker'),
                ]);
            } catch (\Throwable $error) {
                $unresolved[] = [
                    'area' => $area,
                    'theme_id' => $themeId,
                    'reason' => 'website_theme_application_migration_active_default_failed',
                    'error' => $error->getMessage(),
                ];
            }
        }

        // 固定每个受影响目标的保存前引用与修订，完整备份后才能开始首次迁入。
        foreach ($planned as &$item) {
            $current = $this->applications->getOwn($item['scope_key'], $item['store_mode'], $item['area']);
            $item['current_reference'] = $current['reference']?->toArray();
            $item['current_revision'] = $current['revision'];
        }
        unset($item);
        $runId = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
        $runDirectory = $backupDirectory . DIRECTORY_SEPARATOR . $runId;
        if (!mkdir($runDirectory, 0775, true) && !is_dir($runDirectory)) {
            throw new \RuntimeException('website_theme_application_migration_backup_run_unwritable');
        }
        $backupPath = $runDirectory . DIRECTORY_SEPARATOR . 'backup.json';
        if (is_file($backupPath)) {
            throw new \RuntimeException('website_theme_application_migration_backup_exists');
        }
        $backupPayload = [
            'schema' => 'website-theme-application-migration-backup.v1',
            'created_at' => gmdate('c'),
            'legacy_export' => $export,
            'manifest' => [
                'applications' => array_map(
                    static fn(array $item): array => [
                        'scope_key' => $item['scope_key'],
                        'store_mode' => $item['store_mode'],
                        'area' => $item['area'],
                        'reference' => $item['reference']->toArray(),
                        'source_scope' => $item['source_scope'],
                        'source' => $item['source'],
                        'current_reference' => $item['current_reference'],
                        'current_revision' => $item['current_revision'],
                    ],
                    $planned,
                ),
                'unresolved' => $unresolved,
            ],
        ];
        $backupBytes = json_encode($backupPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents($backupPath, $backupBytes, LOCK_EX) !== strlen($backupBytes)) {
            throw new \RuntimeException('website_theme_application_migration_backup_write_failed');
        }

        $saved = 0;
        $preserved = 0;
        foreach ($planned as $item) {
            $current = $this->applications->getOwn($item['scope_key'], $item['store_mode'], $item['area']);
            // 包括恢复跟随后的 null+修订游标；旧来源不可覆盖升级后用户的选择。
            if ($item['current_revision'] !== 0 || $item['current_reference'] !== null
                || $current['revision'] !== 0 || $current['reference'] !== null) {
                $preserved++;
                continue;
            }
            $this->applications->save(
                $item['scope_key'],
                $item['store_mode'],
                $item['area'],
                $item['reference'],
                0,
            );
            $saved++;
        }

        return [
            'saved' => $saved,
            'preserved' => $preserved,
            'unresolved' => $unresolved,
            'backup_path' => $backupPath,
        ];
    }

    /**
     * @param array<string,mixed> $binding
     * @return array{
     *   scope_key:string,
     *   store_mode:string,
     *   area:string,
     *   reference:ThemeApplicationReference,
     *   source_scope:string
     * }
     */
    private function planApplication(array $binding): array
    {
        $storageScope = strtolower(trim((string)($binding['scope'] ?? '')));
        $storeMode = (string)($binding['store_mode'] ?? '');
        $area = (string)($binding['area'] ?? '');
        if ($storageScope === '' || !in_array($storeMode, ['normal', 'dev', 'test'], true)
            || !in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException('website_theme_application_migration_binding_identity_invalid');
        }
        $identity = $this->hierarchy->fromStorageScope($storageScope, true);
        if ($identity === null) {
            throw new \RuntimeException('website_theme_application_migration_scope_unresolved');
        }
        // 三段旧 scope 不包含模式；先恢复旧声明，再由目录核实真实 Store/Channel。
        if ($identity->storeMode !== null && $identity->storeMode !== $storeMode) {
            $data = $identity->toArray();
            $data['store_mode'] = $storeMode;
            $identity = ScopeIdentity::fromArray($data);
        }
        $identity = $this->catalog->authoritativeIdentity($identity);
        $validated = $this->reader->validateReference([
            'theme_id' => (int)($binding['theme_id'] ?? 0),
            'theme_version_id' => (int)($binding['theme_version_id'] ?? 0),
            'content_revision' => (int)($binding['content_revision'] ?? 0),
            'owner_scope' => (string)($binding['owner_scope'] ?? ''),
            'store_mode' => (string)($binding['owner_store_mode'] ?? $storeMode),
            'area' => $area,
        ]);
        $reference = new ThemeApplicationReference(
            themeId: (int)$validated['theme_id'],
            themeVersionId: (int)$validated['theme_version_id'],
            contentRevision: (int)$validated['content_revision'],
            versionOwnerScope: (string)$validated['owner_scope'],
            versionOwnerStoreMode: (string)$validated['store_mode'],
            area: (string)$validated['area'],
        );

        return [
            'scope_key' => $identity->canonicalKey(),
            'store_mode' => $storeMode,
            'area' => $area,
            'reference' => $reference,
            'source_scope' => $storageScope,
            'source' => $binding,
        ];
    }
}
