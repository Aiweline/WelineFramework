<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;

/**
 * cart-coupon / mini-cart-coupon → checkout-coupon（三槽合一后的遗留 code 清洗）。
 *
 * Theme 布局已迁到 scope version / injection 表；旧 Upgrade 只打 theme_layout 会软失败漏洗。
 */
final class LegacyCouponWidgetCodeRemapper
{
    public const LEGACY_CODES = ['cart-coupon', 'mini-cart-coupon'];
    public const CANONICAL_CODE = 'checkout-coupon';
    public const WIDGET_MODULE = 'Weline_Marketing';

    /**
     * Remap a chrome/release nodes map keyed by node_uid.
     * Legacy nodes are renamed, or dropped when the same slot already has checkout-coupon.
     *
     * @param array<string, mixed> $nodes
     * @return array{nodes: array<string, mixed>, changed: bool, removed: int, renamed: int}
     */
    public static function remapNodesMap(array $nodes): array
    {
        $canonicalSlots = [];
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['widget_code'] ?? '') !== self::CANONICAL_CODE) {
                continue;
            }
            if ((string)($node['widget_module'] ?? '') !== '' && (string)$node['widget_module'] !== self::WIDGET_MODULE) {
                continue;
            }
            $slot = trim((string)($node['slot_id'] ?? ''));
            if ($slot !== '') {
                $canonicalSlots[$slot] = true;
            }
        }

        $changed = false;
        $removed = 0;
        $renamed = 0;
        $out = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                $out[$uid] = $node;
                continue;
            }
            $code = (string)($node['widget_code'] ?? '');
            $module = (string)($node['widget_module'] ?? '');
            if ($module !== '' && $module !== self::WIDGET_MODULE) {
                $out[$uid] = $node;
                continue;
            }
            if (!\in_array($code, self::LEGACY_CODES, true)) {
                $out[$uid] = $node;
                continue;
            }
            $slot = trim((string)($node['slot_id'] ?? ''));
            if ($slot !== '' && isset($canonicalSlots[$slot])) {
                $changed = true;
                $removed++;
                continue;
            }
            $node['widget_code'] = self::CANONICAL_CODE;
            if ($module === '') {
                $node['widget_module'] = self::WIDGET_MODULE;
            }
            if ($slot !== '') {
                $canonicalSlots[$slot] = true;
            }
            $out[$uid] = $node;
            $changed = true;
            $renamed++;
        }

        return [
            'nodes' => $out,
            'changed' => $changed,
            'removed' => $removed,
            'renamed' => $renamed,
        ];
    }

    /**
     * Remap a JSON document that is either a nodes map, `{nodes:...}`, or a single node object.
     *
     * @return array{json: string|null, changed: bool, removed: int, renamed: int}
     */
    public static function remapJsonDocument(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
        }
        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
        }
        if (!\is_array($decoded)) {
            return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
        }

        if (isset($decoded['nodes']) && \is_array($decoded['nodes'])) {
            $result = self::remapNodesMap($decoded['nodes']);
            if (!$result['changed']) {
                return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
            }
            $decoded['nodes'] = $result['nodes'];

            return [
                'json' => \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                'changed' => true,
                'removed' => $result['removed'],
                'renamed' => $result['renamed'],
            ];
        }

        if (isset($decoded['widget_code']) && \is_string($decoded['widget_code'])) {
            $code = $decoded['widget_code'];
            $module = (string)($decoded['widget_module'] ?? '');
            if (
                \in_array($code, self::LEGACY_CODES, true)
                && ($module === '' || $module === self::WIDGET_MODULE)
            ) {
                $decoded['widget_code'] = self::CANONICAL_CODE;
                if ($module === '') {
                    $decoded['widget_module'] = self::WIDGET_MODULE;
                }

                return [
                    'json' => \json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                    'changed' => true,
                    'removed' => 0,
                    'renamed' => 1,
                ];
            }

            return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
        }

        // Chrome payload is a flat nodes map keyed by node_uid.
        $looksLikeNodesMap = false;
        foreach ($decoded as $node) {
            if (\is_array($node) && isset($node['widget_code'])) {
                $looksLikeNodesMap = true;
                break;
            }
        }
        if (!$looksLikeNodesMap) {
            return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
        }

        $result = self::remapNodesMap($decoded);
        if (!$result['changed']) {
            return ['json' => null, 'changed' => false, 'removed' => 0, 'renamed' => 0];
        }

        return [
            'json' => \json_encode($result['nodes'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'changed' => true,
            'removed' => $result['removed'],
            'renamed' => $result['renamed'],
        ];
    }

    /**
     * Soft orchestration used by Marketing Setup\Upgrade.
     *
     * @return array<string, int>
     */
    public function run(): array
    {
        $stats = [
            'layout_rows' => 0,
            'injection_updated' => 0,
            'injection_deleted' => 0,
            'chrome_versions' => 0,
            'patches' => 0,
            'releases' => 0,
        ];

        $stats['layout_rows'] = $this->remapThemeLayoutRows();
        $injection = $this->remapDefaultInjections();
        $stats['injection_updated'] = $injection['updated'];
        $stats['injection_deleted'] = $injection['deleted'];
        $stats['chrome_versions'] = $this->remapJsonColumn(
            ThemeScopeVersion::class,
            ThemeScopeVersion::schema_fields_CHROME_PAYLOAD_JSON,
        );
        $stats['patches'] = $this->remapJsonColumn(
            ThemeScopePatch::class,
            ThemeScopePatch::schema_fields_VALUE_JSON,
        );
        $stats['releases'] = $this->remapJsonColumn(
            ThemeScopeRelease::class,
            ThemeScopeRelease::schema_fields_EFFECTIVE_PAYLOAD_JSON,
        );

        return $stats;
    }

    private function remapThemeLayoutRows(): int
    {
        try {
            /** @var ThemeLayout $layoutModel */
            $layoutModel = ObjectManager::getInstance(ThemeLayout::class);
            $layoutModel->reset()
                ->where(ThemeLayout::schema_fields_WIDGET_MODULE, self::WIDGET_MODULE)
                ->where(ThemeLayout::schema_fields_WIDGET_CODE, self::LEGACY_CODES, 'IN')
                ->update([ThemeLayout::schema_fields_WIDGET_CODE => self::CANONICAL_CODE])
                ->fetch();

            return 1;
        } catch (\Throwable $e) {
            w_log_warning('Marketing LegacyCouponWidgetCodeRemapper theme_layout: ' . $e->getMessage(), [], 'marketing');

            return 0;
        }
    }

    /**
     * @return array{updated: int, deleted: int}
     */
    private function remapDefaultInjections(): array
    {
        $updated = 0;
        $deleted = 0;
        try {
            /** @var ThemeWidgetDefaultInjection $model */
            $model = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
            $rows = $model->reset()
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, self::WIDGET_MODULE)
                ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, self::LEGACY_CODES, 'IN')
                ->select()
                ->fetchArray();
            if (!\is_array($rows) || $rows === []) {
                return ['updated' => 0, 'deleted' => 0];
            }

            foreach ($rows as $row) {
                $recordId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_ID] ?? 0);
                if ($recordId < 1) {
                    continue;
                }
                $themeId = (int)($row[ThemeWidgetDefaultInjection::schema_fields_THEME_ID] ?? 0);
                $pageType = (string)($row[ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE] ?? '');
                $layoutOption = (string)($row[ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION] ?? 'default');
                $slotId = (string)($row[ThemeWidgetDefaultInjection::schema_fields_SLOT_ID] ?? '');

                $hasCanonical = false;
                if ($themeId > 0 && $pageType !== '' && $slotId !== '') {
                    /** @var ThemeWidgetDefaultInjection $probe */
                    $probe = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
                    $existing = $probe->reset()
                        ->where(ThemeWidgetDefaultInjection::schema_fields_THEME_ID, $themeId)
                        ->where(ThemeWidgetDefaultInjection::schema_fields_PAGE_TYPE, $pageType)
                        ->where(ThemeWidgetDefaultInjection::schema_fields_LAYOUT_OPTION, $layoutOption)
                        ->where(ThemeWidgetDefaultInjection::schema_fields_SLOT_ID, $slotId)
                        ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_MODULE, self::WIDGET_MODULE)
                        ->where(ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE, self::CANONICAL_CODE)
                        ->select()
                        ->fetchArray();
                    $hasCanonical = \is_array($existing) && $existing !== [];
                }

                /** @var ThemeWidgetDefaultInjection $writer */
                $writer = ObjectManager::getInstance(ThemeWidgetDefaultInjection::class);
                if ($hasCanonical) {
                    $writer->reset()
                        ->where(ThemeWidgetDefaultInjection::schema_fields_ID, $recordId)
                        ->delete()
                        ->fetch();
                    $deleted++;
                } else {
                    $writer->reset()
                        ->where(ThemeWidgetDefaultInjection::schema_fields_ID, $recordId)
                        ->update([ThemeWidgetDefaultInjection::schema_fields_WIDGET_CODE => self::CANONICAL_CODE])
                        ->fetch();
                    $updated++;
                }
            }
        } catch (\Throwable $e) {
            w_log_warning('Marketing LegacyCouponWidgetCodeRemapper injections: ' . $e->getMessage(), [], 'marketing');
        }

        return ['updated' => $updated, 'deleted' => $deleted];
    }

    /**
     * @param class-string $modelClass
     */
    private function remapJsonColumn(string $modelClass, string $column): int
    {
        $changedRows = 0;
        try {
            /** @var \Weline\Framework\Database\Model $model */
            $model = ObjectManager::getInstance($modelClass);
            $pk = $model::schema_primary_key;
            // Prefer LIKE prefilter so Upgrade does not load every chrome/patch/release row.
            try {
                $rows = $model->reset()
                    ->where($column, '%mini-cart-coupon%', 'LIKE')
                    ->select()
                    ->fetchArray();
            } catch (\Throwable) {
                $rows = null;
            }
            if (!\is_array($rows)) {
                $rows = $model->reset()->select()->fetchArray();
            } else {
                try {
                    $more = $model->reset()
                        ->where($column, '%"cart-coupon"%', 'LIKE')
                        ->select()
                        ->fetchArray();
                    if (\is_array($more) && $more !== []) {
                        $byId = [];
                        foreach ($rows as $row) {
                            $id = (int)($row[$pk] ?? 0);
                            if ($id > 0) {
                                $byId[$id] = $row;
                            }
                        }
                        foreach ($more as $row) {
                            $id = (int)($row[$pk] ?? 0);
                            if ($id > 0) {
                                $byId[$id] = $row;
                            }
                        }
                        $rows = \array_values($byId);
                    }
                } catch (\Throwable) {
                    // keep first LIKE result
                }
            }
            if (!\is_array($rows)) {
                return 0;
            }
            foreach ($rows as $row) {
                $raw = (string)($row[$column] ?? '');
                if (
                    $raw === ''
                    || (
                        !\str_contains($raw, 'mini-cart-coupon')
                        && !\str_contains($raw, 'cart-coupon')
                    )
                ) {
                    continue;
                }
                $result = self::remapJsonDocument($raw);
                if (!$result['changed'] || $result['json'] === null) {
                    continue;
                }
                $id = (int)($row[$pk] ?? 0);
                if ($id < 1) {
                    continue;
                }
                /** @var \Weline\Framework\Database\Model $writer */
                $writer = ObjectManager::getInstance($modelClass);
                $writer->reset()
                    ->where($pk, $id)
                    ->update([$column => $result['json']])
                    ->fetch();
                $changedRows++;
            }
        } catch (\Throwable $e) {
            w_log_warning(
                'Marketing LegacyCouponWidgetCodeRemapper ' . $modelClass . '.' . $column . ': ' . $e->getMessage(),
                [],
                'marketing',
            );
        }

        return $changedRows;
    }
}
