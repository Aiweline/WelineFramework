<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Pinned workflow guidance merged into resolve_task_context and get_edit_bundle.
 */
final class GuidanceWorkflowCatalog
{
    public const SCHEMA = 'workflow-contract.v1';

    /** @return list<string> Repository-relative pinned doc paths. */
    public static function pinnedDocumentPaths(): array
    {
        return [
            'app/code/Weline/Ai/doc/AI工程交付流程.md',
            'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
            'app/code/Weline/Ai/doc/文档索引.md',
            'app/code/Weline/Theme/doc/部件开发指南.md',
        ];
    }

    /** @return array<string, mixed> */
    public static function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'mandatory_before_code' => [
                'prepare_project_ready',
                'requirements_confirmed_or_scoped',
                'extension_point_selected',
                'task_contract_or_plan',
            ],
            'phases' => [
                ['id' => 'bootstrap', 'label' => '引导与 ready', 'tools' => ['ensure-project-guidance', 'prepare_project']],
                ['id' => 'locate', 'label' => '定位与需求确认', 'tools' => ['resolve_task_context', 'search_project_knowledge']],
                ['id' => 'extension_point', 'label' => '扩展点选型', 'docs' => [
                    'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                    'app/code/Weline/Framework/doc/event/README.md',
                ]],
                ['id' => 'plan', 'label' => '计划拆解', 'artifacts' => ['doc/开发/plan.md', 'doc/开发/task.md', 'task_contract']],
                ['id' => 'implement', 'label' => '实现', 'tools' => ['get_edit_bundle', 'apply_compact_edit']],
                ['id' => 'review', 'label' => '架构/缺陷/安全复审', 'tools' => []],
                ['id' => 'verify', 'label' => '分层测试与 WebUI 验收', 'tools' => []],
                ['id' => 'closeout', 'label' => '开发日志收口', 'artifacts' => ['doc/开发日志.md']],
            ],
            'extension_point_matrix' => [
                ['intent' => 'notification_side_effect', 'prefer' => 'event_observer', 'index' => 'app/code/Weline/Framework/doc/event/README.md'],
                ['intent' => 'read_data', 'prefer' => 'interface_query_provider', 'index' => 'app/code/Weline/Framework/doc/BinQuery/README.md'],
                ['intent' => 'write_command', 'prefer' => 'interface_hook_queue', 'index' => 'module doc/'],
                ['intent' => 'ui_control', 'prefer' => 'taglib_hook', 'index' => 'module Taglib doc'],
                ['intent' => 'cross_module_concrete_service', 'prefer' => 'forbidden', 'index' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md'],
            ],
            'acceptance_tiers' => [
                ['surface' => 'pure_logic', 'minimum' => 'focused_unit_test'],
                ['surface' => 'api_runtime', 'minimum' => 'real_command_or_api_plus_tests'],
                ['surface' => 'page_interaction', 'minimum' => 'wls_browser_operator_path'],
            ],
            'hard_rules' => [
                'Do not invent undocumented event names.',
                'Do not skip extension-point selection before code changes.',
                'Incomplete verification must be reported explicitly.',
                'Repository doc/ is authoritative; root docs/ is legacy.',
                'Never put <?php or <?= in Weline Taglib / w:* tag attribute values; use @lang, Hook, or body-level HTML attributes with htmlspecialchars.',
                'Theme/widget .phtml must not use inline <script> blocks with <?= or server-side PHP; use external @static JS plus data-* / data-js-ns / data-uid on the widget root.',
                'If inline <style>/<script> must remain in layout or partial templates, mark data-no-extract="true"; prefer external assets for widgets injected into data-wslot slots.',
            ],
            'template_surface_rules' => [
                'forbidden' => [
                    'PHP tags inside HTML attribute values (e.g. attr="<?= ... ?>") on w:* / Taglib tags',
                    'Inline <script> containing <?= in Theme widgets/partials that render through slot injection',
                    'Business UI or demo copy inside layout slot fallbacks (use widgets + default_injections)',
                ],
                'required' => [
                    'Widget JS scoped by data-js-ns + data-uid; load via @static(...js) with defer and data-no-extract when kept inline-adjacent',
                    'Dynamic values in attributes: set on HTML elements in body, not on Taglib tag attributes',
                ],
                'authoritative_doc' => 'app/code/Weline/Theme/doc/部件开发指南.md',
            ],
            'authoritative_workflow_doc' => 'app/code/Weline/Ai/doc/AI工程交付流程.md',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pinnedFragments(ProjectRetriever $retriever, int $tokenBudget): array
    {
        $paths = self::pinnedDocumentPaths();
        if ($paths === [] || $tokenBudget < 128) {
            return [];
        }

        $perPath = max(128, (int) floor($tokenBudget / count($paths)));
        $fragments = [];
        $seenPaths = [];

        foreach ($paths as $path) {
            if (isset($seenPaths[$path])) {
                continue;
            }
            $seenPaths[$path] = true;
            try {
                $result = $retriever->getDocument([
                    'path' => $path,
                    'limit' => 2,
                    'token_budget' => min($perPath, 900),
                ]);
            } catch (\Throwable) {
                continue;
            }
            foreach (is_array($result['documents'] ?? null) ? $result['documents'] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $fragments[] = [
                    'kind' => 'workflow',
                    'pinned' => true,
                    'path' => $path,
                    'module' => (string) ($item['module'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'start_line' => (int) ($item['start_line'] ?? 1),
                    'end_line' => (int) ($item['end_line'] ?? $item['start_line'] ?? 1),
                    'content' => (string) ($item['snippet'] ?? ''),
                    'file_hash' => (string) ($item['file_hash'] ?? ''),
                    'content_hash' => (string) ($item['content_hash'] ?? ''),
                    'token_estimate' => (int) ($item['token_estimate'] ?? 0),
                ];
            }
        }

        return $fragments;
    }

    /**
     * @param list<array<string, mixed>> $fragments
     * @param list<array<string, mixed>> $pinned
     * @return list<array<string, mixed>>
     */
    public static function mergeFragments(array $fragments, array $pinned): array
    {
        if ($pinned === []) {
            return $fragments;
        }

        $merged = [];
        $seen = [];
        foreach (array_merge($pinned, $fragments) as $fragment) {
            if (!is_array($fragment)) {
                continue;
            }
            $path = (string) ($fragment['path'] ?? '');
            $start = (int) ($fragment['start_line'] ?? 0);
            $key = $path . ':' . $start . ':' . (string) ($fragment['content_hash'] ?? '');
            if ($key === '::' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $fragment;
        }

        return $merged;
    }
}
