<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Cross-layer impact inventory for entity/field change requirements.
 * Machine gate companion to requirement_cross_layer_impact_gate.
 */
final class ImpactSurfacesCatalog
{
    public const SCHEMA = 'impact-surfaces.v1';

    public const HARD_CONSTRAINT = 'requirement_cross_layer_impact_gate';

    /** @var list<string> */
    public const LAYERS = [
        'schema_model',
        'service_api',
        'admin_ui',
        'storefront_ui',
        'i18n',
        'event_hook',
        'checkout_flow',
        'tests_e2e',
    ];

    /** Layers that must be inventoried (status set) when entity-field signal fires. */
    /** @var list<string> */
    public const REQUIRED_LAYERS = [
        'schema_model',
        'service_api',
        'admin_ui',
        'storefront_ui',
        'i18n',
        'tests_e2e',
    ];

    /** @var list<string> */
    public const STATUSES = ['in_scope', 'na', 'out'];

    /** @var list<string> */
    public const CHECKLIST_IDS = [
        'cross_layer_surfaces_inventoried',
        'admin_storefront_parity_checked',
        'i18n_schema_tests_checked',
    ];

    /**
     * Detect entity/field change asks like「给订单增加个类型」.
     *
     * @param list<string> $requirements
     */
    public static function detectsEntityFieldChange(string $goal, array $requirements): bool
    {
        $hay = mb_strtolower(trim($goal) . "\n" . implode("\n", $requirements), 'UTF-8');
        if ($hay === '') {
            return false;
        }

        $hasEntity = preg_match(
            '/订单|商品|客户|支付|运费|购物车|用户|产品|会员|sku|\border\b|\bproduct\b|\bcustomer\b|\bpayment\b|\bcart\b|\buser\b/u',
            $hay,
        ) === 1;
        $hasField = preg_match(
            '/类型|字段|状态|枚举|属性|列|\bcolumn\b|\bfield\b|\benum\b|\bstatus\b|\btype\b|\battribute\b/u',
            $hay,
        ) === 1;
        $hasChange = preg_match(
            '/增加|新增|添加|加个|加上|加一|扩展|引入|加\s|add(?:ing|ed)?\b|introduce\b|extend(?:ing|ed)?\b/u',
            $hay,
        ) === 1;

        return $hasEntity && $hasField && $hasChange;
    }

    /**
     * @param list<string> $implicitRequirements
     */
    public static function implicitLooksEmptyOnly(array $implicitRequirements): bool
    {
        if ($implicitRequirements === []) {
            return true;
        }
        foreach ($implicitRequirements as $row) {
            $text = mb_strtolower(trim((string) $row), 'UTF-8');
            if ($text === '') {
                continue;
            }
            if (!preg_match('/^(无|无隐形需求|无隐藏需求|none|n\/?a|nil|null|无\s*$)/u', $text)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build path candidates from knowledge fragments for resolve_task_context.
     *
     * @param list<array<string, mixed>> $fragments
     * @return list<array{layer: string, path: string, module: string, reason: string}>
     */
    public static function buildImpactCandidates(string $task, array $fragments = []): array
    {
        $out = [];
        $seen = [];
        foreach ($fragments as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }
            $path = trim((string) ($fragment['path'] ?? ''));
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $hint = mb_strtolower(
                $path . ' ' . (string) ($fragment['title'] ?? '') . ' ' . (string) ($fragment['content'] ?? ''),
                'UTF-8',
            );
            $layer = self::guessLayerFromPathHint($path, $hint);
            if ($layer === null) {
                continue;
            }
            $seen[$path] = true;
            $out[] = [
                'layer' => $layer,
                'path' => $path,
                'module' => (string) ($fragment['module'] ?? ''),
                'reason' => 'impact candidate for ' . $layer,
            ];
            if (count($out) >= 16) {
                break;
            }
        }

        if ($out === [] && self::detectsEntityFieldChange($task, [])) {
            foreach (self::REQUIRED_LAYERS as $layer) {
                $out[] = [
                    'layer' => $layer,
                    'path' => '',
                    'module' => '',
                    'reason' => 'entity-field signal: inventory ' . $layer . ' via search_project_knowledge',
                ];
            }
        }

        return $out;
    }

    private static function guessLayerFromPathHint(string $path, string $hint): ?string
    {
        if (str_contains($path, '/i18n/') || str_contains($hint, 'i18n')) {
            return 'i18n';
        }
        if (
            str_contains($path, '/Test/')
            || str_contains($path, '/test/')
            || str_contains($path, '.spec.js')
            || str_contains($hint, 'e2e')
        ) {
            return 'tests_e2e';
        }
        if (
            str_contains($path, '/Model/')
            || str_contains($path, '/Setup/')
            || str_contains($path, '/db/')
            || str_contains($hint, 'schema')
        ) {
            return 'schema_model';
        }
        if (
            str_contains($path, '/Service/')
            || str_contains($path, '/Api/')
            || str_contains($path, '/Controller/')
            || str_contains($hint, 'api')
        ) {
            return 'service_api';
        }
        if (
            str_contains($path, '/Observer/')
            || str_contains($path, '/Hook/')
            || str_contains($path, 'event.xml')
            || str_contains($hint, 'observer')
        ) {
            return 'event_hook';
        }
        if (
            str_contains($path, '/Checkout/')
            || str_contains($path, 'checkout')
            || str_contains($hint, 'checkout')
        ) {
            return 'checkout_flow';
        }
        if (
            str_contains($path, '/backend/')
            || str_contains($path, '/Backend/')
            || str_contains($path, 'admin')
            || str_contains($hint, '后台')
        ) {
            return 'admin_ui';
        }
        if (
            str_contains($path, '/frontend/')
            || str_contains($path, '/storefront/')
            || str_contains($path, '.phtml')
            || str_contains($hint, '前台')
        ) {
            return 'storefront_ui';
        }

        return null;
    }
}
