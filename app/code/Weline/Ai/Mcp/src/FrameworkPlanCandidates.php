<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Index/heuristic framework candidates for resolve_task_context.
 * Agents should choose extension points / architecture mechanisms from this pack.
 */
final class FrameworkPlanCandidates
{
    public const SCHEMA = 'framework-plan-candidates.v1';

    public const EXT_DOC = 'app/code/Weline/Framework/doc/3-开发/扩展点选型.md';

    /** @var list<string> */
    public const CHECKLIST = [
        'clarify_use_case_spec_ready_or_skipped',
        'host_plan_mode_enabled_or_unavailable_noted',
        'fe_be_scope_analyzed',
        'acceptance_always_planned',
        'existing_taglib_checked',
        'no_cross_module_concrete_new',
        'query_over_model_read',
        'extension_point_selected',
        'module_boundary_clear',
        'no_invented_event_name',
        'systemconfig_or_na',
        'acl_or_na',
        'cross_layer_surfaces_inventoried',
        'admin_storefront_parity_checked',
        'i18n_schema_tests_checked',
    ];

    /**
     * @param list<array<string, mixed>> $fragments
     * @return array<string, mixed>
     */
    public static function build(string $task, array $fragments = []): array
    {
        $hay = mb_strtolower($task, 'UTF-8');
        $mechanisms = [];

        if (preg_match('/语言|货币|网站|币种|语种|taglib|选择器|下拉|切换器|switcher/u', $hay) === 1) {
            $mechanisms[] = 'Taglib';
        }
        if (preg_match('/事件|observer|通知|副作用|event\b/u', $hay) === 1) {
            $mechanisms[] = 'Event';
        }
        if (preg_match('/hook|钩子|拦截|before_|after_/u', $hay) === 1) {
            $mechanisms[] = 'Hook';
        }
        if (preg_match('/query|查询|读取|列表数据|w_query|queryprovider/u', $hay) === 1) {
            $mechanisms[] = 'Query';
        }
        if (preg_match('/interface|接口|spi|provider|契约/u', $hay) === 1) {
            $mechanisms[] = 'Interface';
        }
        if (preg_match('/mcp|门禁|gate|硬约束|索引|文档对齐/u', $hay) === 1) {
            $mechanisms[] = 'none:mcp-or-infra';
        }
        if ($mechanisms === []) {
            $mechanisms = ['Interface', 'Query', 'Hook', 'Event', 'Taglib'];
        }
        $mechanisms = array_values(array_unique($mechanisms));

        $reuse = [];
        $seenPaths = [];
        foreach ($fragments as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }
            $path = trim((string) ($fragment['path'] ?? ''));
            if ($path === '' || isset($seenPaths[$path])) {
                continue;
            }
            $kindHint = mb_strtolower(
                $path . ' ' . (string) ($fragment['title'] ?? '') . ' ' . (string) ($fragment['content'] ?? ''),
                'UTF-8',
            );
            $reason = '';
            if (str_contains($kindHint, 'taglib') || str_contains($path, '/Taglib/')) {
                $reason = 'Taglib candidate';
            } elseif (str_contains($kindHint, 'observer') || str_contains($path, '/Observer/')) {
                $reason = 'Event/Observer candidate';
            } elseif (str_contains($kindHint, 'query') || str_contains($path, '/Query/')) {
                $reason = 'Query candidate';
            } elseif (str_contains($kindHint, 'hook') || str_contains($path, '/Hook/')) {
                $reason = 'Hook candidate';
            } elseif (str_contains($path, '/doc/') && str_contains($kindHint, '扩展点')) {
                $reason = 'extension-point doc';
            }
            if ($reason === '') {
                continue;
            }
            $seenPaths[$path] = true;
            $reuse[] = [
                'path' => $path,
                'module' => (string) ($fragment['module'] ?? ''),
                'title' => Text::truncate((string) ($fragment['title'] ?? ''), 120),
                'reason' => $reason,
            ];
            if (count($reuse) >= 12) {
                break;
            }
        }

        $antiPatterns = [
            '手写 select / 原生下拉做语言·货币·网站切换（须 Taglib）',
            '跨模块 new 对方 Service/Model（须 Interface/Query/Event/Hook）',
            '静默发明未注册事件名',
            '从 requirements 直接跳到补丁、跳过 architecture_design',
            '实体/字段变更只改一层（漏 Model/API/前后台/i18n/测试）—须 impact_surfaces',
        ];

        return [
            'schema_version' => self::SCHEMA,
            'recommended_mechanisms' => $mechanisms,
            'extension_point_hints' => array_map(
                static fn (string $m): string => str_starts_with($m, 'none:')
                    ? $m
                    : $m . ' (select concrete name or none:reason)',
                $mechanisms,
            ),
            'reuse_candidates' => $reuse,
            'impact_candidates' => ImpactSurfacesCatalog::buildImpactCandidates($task, $fragments),
            'anti_patterns' => $antiPatterns,
            'doc_paths' => [
                self::EXT_DOC,
                HardConstraintsCatalog::AUTHORITATIVE_WORKFLOW_DOC,
                'app/code/Weline/Ai/doc/AI硬规则索引.md',
            ],
            'scrutiny_checklist' => self::CHECKLIST,
            'architecture_design_keys' => [
                'mechanism',
                'owning_module',
                'reuse',
                'invent',
                'not_to_do',
                'req_map',
            ],
            'note' => 'Prefer mechanism/reuse/anti_patterns from this pack when designing changes; '
                . 'use scrutiny_checklist (≥2) and doc_paths when reviewing against the framework. '
                . 'Implement with host-native editing tools.',
        ];
    }
}
