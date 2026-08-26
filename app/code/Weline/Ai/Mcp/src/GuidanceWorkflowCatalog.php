<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Pinned workflow guidance merged into resolve_task_context and get_edit_bundle.
 */
final class GuidanceWorkflowCatalog
{
    public const SCHEMA = 'workflow-contract.v1';

    /** Frontend Theme/UI development surface id (not a single attribute name). */
    public const SURFACE_FRONTEND_DEVELOPMENT = 'frontend_development';

    /** @return list<string> Repository-relative pinned doc paths. */
    public static function pinnedDocumentPaths(): array
    {
        return [
            'app/code/Weline/Ai/doc/AI工程交付流程.md',
            'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
            'app/code/Weline/Ai/doc/文档索引.md',
            'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'app/code/Weline/Theme/doc/部件开发指南.md',
        ];
    }

    /** @return list<string> Notices agents must read at prepare / resolve_task_context time. */
    public static function sessionStartupNotices(): array
    {
        return [
            '每个功能做完后，必须打开归属模块 doc/（README、需求、开发日志及专题文档）对照实现：行为与文档不一致则先改文档或代码使二者对齐；禁止功能已交付但文档未跟上。',
            'Web / 前台与可视化 UI：设计阶段就要纳入平板与 PC 响应式兼容（至少覆盖约 768 平板与 ≥1024 PC，并兼顾 375 手机）；验收须收集多断点证据，禁止只按单一桌面宽度实现后再补丁。',
            '凡任务涉及 Web/UI/后台页面：开工前须在 doc/需求.md 或 TaskContract 中定稿 WEBUI 操作员用例（URL、步骤、期望、断点）；禁止先编码后补验收。',
            '分章交付计划：每章 Done 须 UT→RT→WB→DL 四段全 pass 才开下一章；含 Web 的章 WB 须 WB-OP（操作员路径）+ WB-VIS（断点截图 + 对照模块 doc/原型设计.md 视觉清单）；禁止无截图宣称 UI 完成。',
            'After each feature, reconcile the owning module doc/ (README, 需求, 开发日志, topic docs) with the shipped behavior; update docs or code until they match—do not ship code without documentation.',
            'For Web/UI work, design for tablet and PC responsiveness from the start (≈768 tablet and ≥1024 desktop, plus 375 mobile); collect multi-breakpoint acceptance evidence—do not desktop-only then retrofit.',
            'Multi-chapter Web delivery: each chapter closeout requires operator-path Browser checks plus visual acceptance screenshots recorded under module doc/evidence/; curl and unit tests do not substitute either gate.',
        ];
    }

    /** @return array<string, mixed> */
    public static function chapterDelivery(): array
    {
        return [
            'schema' => 'chapter-delivery.v1',
            'session_startup_notices_addon' => [
                '分章计划：上一章 doc/开发日志.md 四段门禁全 pass 后才允许下一章编码。',
                '含 Web 的分章 Done：WB 须 WB-OP + WB-VIS；截图存归属模块 doc/evidence/ch{N}/，对照 doc/原型设计.md。',
            ],
            'mandatory_before_code' => [
                'webui_acceptance_cases_agreed_for_web_surface',
                'chapter_acceptance_defined_if_multi_chapter_plan',
            ],
            'mandatory_before_chapter_closeout' => [
                'unit_tests_pass_for_current_chapter',
                'runtime_checks_pass_for_current_chapter',
                'webui_cases_pass_for_current_chapter_or_na',
                'visual_acceptance_pass_for_webui_cases_or_na',
                'dev_log_chapter_gate_recorded',
            ],
            'mandatory_before_next_chapter' => [
                'previous_chapter_gate_passed_in_dev_log',
            ],
            'acceptance_segments' => ['unit_test', 'runtime', 'webui', 'dev_log'],
            'webui_subsegments' => ['operator_path', 'visual_acceptance'],
            'webui_defaults' => [
                'environment' => 'real_wls',
                'browser' => 'cursor_embedded',
                'breakpoints' => [375, 768, 1024],
                'visual_acceptance_required' => true,
                'visual_evidence' => [
                    'screenshot_per_breakpoint_per_webui_case',
                    'checklist_against_module_prototype_doc',
                    'paths_recorded_in_dev_log',
                ],
                'prototype_doc' => 'app/code/Weline/Catalog/doc/原型设计.md',
                'evidence_dir_pattern' => 'app/code/Weline/Catalog/doc/evidence/ch{N}/',
                'forbidden_substitutes' => [
                    'curl_only',
                    'mock_dom',
                    'skipped_with_code_done_claim',
                    'text_only_visual_claim_without_screenshot',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function contract(): array
    {
        $frontendDevelopment = self::frontendDevelopmentSurface();

        return [
            'schema_version' => self::SCHEMA,
            'session_startup_notices' => self::sessionStartupNotices(),
            'mandatory_before_code' => [
                'prepare_project_ready',
                'requirements_confirmed_or_scoped',
                'extension_point_selected',
                'task_contract_or_plan',
                'webui_acceptance_cases_agreed_for_web_surface',
                'chapter_acceptance_defined_if_multi_chapter_plan',
            ],
            'mandatory_before_closeout' => [
                'module_docs_reconciled_with_behavior',
                'responsive_breakpoints_considered_for_web_ui',
            ],
            'phases' => [
                ['id' => 'bootstrap', 'label' => '引导与 ready', 'tools' => ['ensure-project-guidance', 'prepare_project'], 'read' => ['session_startup_notices']],
                ['id' => 'locate', 'label' => '定位与需求确认', 'tools' => ['resolve_task_context', 'search_project_knowledge']],
                ['id' => 'extension_point', 'label' => '扩展点选型', 'docs' => [
                    'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
                    'app/code/Weline/Framework/doc/event/README.md',
                ]],
                ['id' => 'plan', 'label' => '计划拆解', 'docs' => ['doc/开发/plan.md', 'doc/开发/task.md', 'task_contract'], 'notes' => [
                    'Web/UI tasks must list tablet and PC responsive acceptance in the plan.',
                ]],
                ['id' => 'implement', 'label' => '实现', 'tools' => ['get_edit_bundle', 'apply_compact_edit']],
                ['id' => 'review', 'label' => '架构/缺陷/安全复审', 'tools' => []],
                ['id' => 'verify', 'label' => '分层测试与 WebUI 验收', 'tools' => [], 'notes' => [
                    'Page/UI surfaces: collect 375 / ≈768 / ≥1024 (and 1440 when relevant) evidence.',
                    'Multi-chapter Web: WB-OP operator path plus WB-VIS screenshots under module doc/evidence/.',
                ]],
                ['id' => 'closeout', 'label' => '文档对齐与开发日志收口', 'docs' => ['doc/README.md', 'doc/需求.md', 'doc/开发日志.md'], 'notes' => [
                    'Reconcile module docs with shipped behavior before claiming done.',
                ]],
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
                ['surface' => 'page_interaction', 'minimum' => 'wls_browser_operator_path_multi_breakpoint'],
            ],
            'surfaces' => [
                self::SURFACE_FRONTEND_DEVELOPMENT => $frontendDevelopment,
            ],
            'hard_rules' => [
                'Do not invent undocumented event names.',
                'Do not skip extension-point selection before code changes.',
                'Incomplete verification must be reported explicitly.',
                'Repository doc/ is authoritative; root docs/ is legacy.',
                'After each feature, open the owning module doc/ (README, 需求, 开发日志, topic docs) and reconcile docs with behavior; do not finish without documentation updates when they diverge.',
                'Web/UI design must include tablet and PC responsive compatibility from the start (≈768 and ≥1024, plus 375 mobile); collect multi-breakpoint acceptance evidence.',
                'Multi-chapter delivery: each chapter requires UT, RT, WB (WB-OP + WB-VIS screenshots), and DL before the next chapter starts.',
                'Do not claim Web/UI done without Browser screenshots recorded in module doc/evidence/ and reconciled prototype checklist.',
                'When editing Theme/frontend widgets, layouts, partials, or .phtml, follow the frontend_development surface (Theme开发总指南), not ad-hoc attribute folklore.',
                'Never put <?php or <?= in Weline Taglib / w:* tag attribute values; use @lang, Hook, or body-level HTML attributes with htmlspecialchars.',
                'Theme/widget .phtml must not use inline <script> blocks with <?= or server-side PHP; use external @static JS plus data-* / data-js-ns / data-uid on the widget root.',
                'If inline <style>/<script> must remain in layout or partial templates, mark data-no-extract="true"; prefer external assets for widgets injected into data-wslot slots.',
                'Frontend development requires every scanned literal <section> and w:slot wrapper="section" to carry a non-empty semantic section identity attribute (weline-code); missing identity fails setup:upgrade.',
                'Theme layouts/partials may inline <w:widget> / fetch(widgets) only for Weline_Theme-owned widgets; other modules must use empty slots + default_injections (verify: php bin/w frontend:check-theme-layout-widgets).',
            ],
            // Compatibility alias used by older agents; prefer surfaces.frontend_development.
            'template_surface_rules' => $frontendDevelopment['template_surface_rules'],
            'frontend_development' => $frontendDevelopment,
            'authoritative_workflow_doc' => 'app/code/Weline/Ai/doc/AI工程交付流程.md',
            'chapter_delivery' => self::chapterDelivery(),
        ];
    }

    /** @return array<string, mixed> */
    public static function frontendDevelopmentSurface(): array
    {
        return [
            'id' => self::SURFACE_FRONTEND_DEVELOPMENT,
            'label' => '前端开发规范',
            'description' => 'Theme / 布局 / 部件 / partial / 前台模板开发的统一规范表面。section 身份属性（weline-code）只是其中一条硬约束，不是独立技能名。',
            'triggers' => [
                '部件', 'widget', '主题', 'theme', '布局', 'layout', 'partial',
                'phtml', '前端', 'frontend', '模板', 'section', 'slot',
            ],
            'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            'authoritative_docs' => [
                'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'app/code/Weline/Theme/doc/部件开发指南.md',
                'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                'app/code/Weline/Theme/doc/theme-css-variables-only.md',
            ],
            'norms' => [
                [
                    'id' => 'layer_choice',
                    'summary' => '先判定改动层：layout / partial / component / widget，再落文件',
                ],
                [
                    'id' => 'no_generated_edit',
                    'summary' => '禁止直接改 generated/ 与 view/tpl；改源模板后走编译/扫描链路',
                ],
                [
                    'id' => 'taglib_attr_no_php',
                    'summary' => 'w:* / Taglib 标签属性禁止 <?= / <?php',
                ],
                [
                    'id' => 'widget_external_js',
                    'summary' => '部件禁止带 <?= 的内联 script；用 @static JS + data-js-ns / data-uid',
                ],
                [
                    'id' => 'slot_fallback_no_demo',
                    'summary' => '布局 slot <else/> 禁止业务/demo 占位；用 default_injections',
                ],
                [
                    'id' => 'section_identity',
                    'summary' => '前台字面 <section> 与 w:slot wrapper="section" 必须有非空语义 section 身份（属性名 weline-code）',
                    'detail_doc' => 'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                    'verify' => 'php bin/w frontend:check-section-code',
                ],
                [
                    'id' => 'theme_layout_widget_owner',
                    'summary' => 'Theme layouts/partials 仅允许内嵌 Weline_Theme 部件；其他模块用 default_injections + 空 slot',
                    'detail_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'verify' => 'php bin/w frontend:check-theme-layout-widgets',
                ],
                [
                    'id' => 'css_variables_only',
                    'summary' => '视觉值优先走主题 CSS 变量，避免硬编码色值/间距',
                    'detail_doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
                ],
                [
                    'id' => 'browser_api',
                    'summary' => '浏览器业务请求走 Weline.Api.*，禁止 raw ajax/fetch fallback',
                ],
                [
                    'id' => 'responsive_tablet_pc',
                    'summary' => '设计阶段纳入平板(≈768)与 PC(≥1024) 响应式；验收收集多断点证据，禁止只做桌面再补丁',
                    'breakpoints' => ['375', '768', '1024', '1440'],
                ],
                [
                    'id' => 'docs_reconcile_per_feature',
                    'summary' => '每完成一个功能对照归属模块 doc/ 与实现，有差异则改文档或代码使二者对齐',
                ],
            ],
            'template_surface_rules' => [
                'surface' => self::SURFACE_FRONTEND_DEVELOPMENT,
                'label' => '前端开发规范',
                'forbidden' => [
                    'PHP tags inside HTML attribute values (e.g. attr="<?= ... ?>") on w:* / Taglib tags',
                    'Inline <script> containing <?= in Theme widgets/partials that render through slot injection',
                    'Business UI or demo copy inside layout slot fallbacks (use widgets + default_injections)',
                    'Non-Weline_Theme <w:widget> or fetch(.../widgets/...) inside Theme layouts/partials (use default_injections)',
                    'Frontend literal <section> or w:slot wrapper="section" without non-empty semantic section identity (weline-code attribute)',
                    'Editing generated/ or view/tpl as if they were source templates',
                    'Desktop-only Web UI implementation without tablet/PC responsive consideration',
                    'Shipping a feature without reconciling owning module doc/ with behavior',
                ],
                'required' => [
                    'Choose layout / partial / component / widget layer before editing',
                    'Widget JS scoped by data-js-ns + data-uid; load via @static(...js) with defer and data-no-extract when kept inline-adjacent',
                    'Dynamic values in attributes: set on HTML elements in body, not on Taglib tag attributes',
                    'Widget root uses WidgetUiScope and a stable type-level section identity attribute',
                    'Layout/partial literal <section> and w:slot wrapper="section" carry stable semantic section identity; verify with php bin/w frontend:check-section-code before setup:upgrade',
                    'Theme layouts/partials inline widgets only when owned by Weline_Theme; other modules use default_injections; verify with php bin/w frontend:check-theme-layout-widgets',
                    'Design and accept Web UI across tablet (≈768) and PC (≥1024), plus mobile 375 when relevant',
                    'After each feature, reconcile module README/需求/开发日志/topic docs with shipped behavior',
                ],
                'authoritative_doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                'authoritative_docs' => [
                    'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
                    'app/code/Weline/Theme/doc/部件开发指南.md',
                    'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
                ],
                'verification_commands' => [
                    'php bin/w frontend:check-section-code',
                    'php bin/w frontend:check-theme-layout-widgets',
                ],
            ],
            'verification_commands' => [
                'php bin/w frontend:check-section-code',
                'php bin/w frontend:check-theme-layout-widgets',
            ],
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
                    'surface' => str_contains($path, '/Theme/doc/')
                        ? self::SURFACE_FRONTEND_DEVELOPMENT
                        : 'workflow',
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
