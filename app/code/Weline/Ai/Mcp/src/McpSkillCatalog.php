<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * MCP-native skill catalog served by resolve_skill / get_skill.
 *
 * Skills are compiled from GuidanceWorkflowCatalog surfaces (+ delivery contracts).
 * They are not repository SKILL.md files and must not revive auto_generate_skills.
 */
final class McpSkillCatalog
{
    public const SCHEMA = 'mcp-skills.v1';

    public const PROVIDER = 'mcp';

    /**
     * Host-shell aliases: optional Cursor/Codex local SKILL.md may mirror these ids,
     * but MCP get_skill is authoritative for engineering skill bodies.
     *
     * @var array<string, string> alias => canonical skill_id
     */
    private const HOST_SHELL_ALIASES = [
        'weline-theme-development' => GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT,
        'local-browser-urls' => GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT,
        'weline-taglib-first' => GuidanceWorkflowCatalog::SURFACE_TAGLIB_UI_CONTROL,
        'weline-req-clarify' => GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
    ];

    /**
     * Policy block for prepare_project.agent_guidance.mcp_skills.
     *
     * @return array<string, mixed>
     */
    public static function policy(string $repository = ''): array
    {
        // Index-only for prepare_project: descriptions/paths stay in definitions + get_skill.
        $workflowSummary = self::summary($repository, 'workflow');
        $fullSummary = self::summary($repository);
        $greeting = $repository !== ''
            ? DocSkillCatalog::greetingCatalog($repository, $workflowSummary)
            : [
                'schema_version' => 'mcp-greeting-catalog.v1',
                'when' => ['hi', '你好', 'hello', '提取技能', 'list skills'],
                'list_from' => 'agent_guidance.mcp_skills.catalog',
                'workflow_preview' => $workflowSummary,
                'module_doc_count' => 0,
                'commands' => [],
                'how_to_load' => [
                    'discover' => 'resolve_skill',
                    'load' => 'get_skill',
                    'list_all' => 'resolve_skill(list_all=true) or task=提取技能',
                ],
                'note' => 'On greeting: print workflow_preview + commands; module docs via resolve_skill(list_all=true). Bodies via get_skill.',
            ];

        return [
            'schema_version' => self::SCHEMA,
            'provider' => self::PROVIDER,
            'static_skill_files' => false,
            'authority' => 'mcp',
            'host_shell_role' => 'optional_thin_mirror',
            'catalog_mode' => 'index_only',
            'catalog_index_fields' => ['skill_id', 'name', 'aliases', 'kind', 'surface_id'],
            'fetch' => [
                'discover' => 'resolve_skill',
                'load' => 'get_skill',
                'list_all' => 'resolve_skill(list_all=true) or task=提取技能',
                'required_fields' => ['skill_id'],
                'body_via' => 'get_skill',
            ],
            'instructions' => [
                'Engineering/product skills for this repository are served by MCP.',
                'When a task needs a skill body, call resolve_skill(task) then get_skill(skill_id).',
                'To list every skill (workflow + module doc indexes), call resolve_skill(list_all=true) or say 提取技能.',
                'On greeting hi/你好/hello with no coding ask: list MCP skills + commands from agent_guidance.mcp_skills.greeting.workflow_preview + commands (+ module_doc_count), or resolve_skill list_all; do not expect full descriptions in prepare_project catalog.',
                'Do not treat host editor SKILL.md as authoritative over MCP skill bodies.',
                'Host shells (e.g. Cursor Agent Skills) may exist only as thin reminders to call MCP.',
                'Full task docs still come from resolve_task_context; skills are procedural checklists.',
                'Whenever the task mentions CSS or 主题/theme: load UI skill frontend-design, prototype skill prototype, and theme skill weline-theme-development (get_skill) before styling.',
                'HARD: At requirement start classify work_kind + fe_be_scope (requirement_fe_be_scope_analysis), then run Spec Kit/Kiro-style clarify + use-case when needed (requirement_clarify_use_case_spec). Enable host Plan Mode (host_plan_mode_for_planning) UNLESS simple plan_skip with rationale≥24. EVERY ask MUST have real acceptance (requirement_acceptance_always)—Web touches need local Browser WB-OP visual+logic even without Playwright e2e. Layout/humanization/吐槽/审图 force prototype+frontend-design adjustments (ui_skill_surface_signal_gate). Then analyze implicit requirements; decide ui_skill_decision. When participate: prototype+frontend-design+weline-theme-development + type=shentu. Closeout MUST write huishen_notes 汇审 (closeout_requires_huishen).',
                'HARD: Any user message with an image/screenshot attachment (admin/CMS/error/storefront—not only retail/B2B) MUST run MCP command 审图 (dev/ai-command/theme/审图.md) immediately; do not wait for the word 审图. Classify error_shot vs ui_shot: non-error (ui_shot) defaults to UI modification required. Same-turn joint pipeline: extract wireframe/line sketch → prototype adjustments (prototype) → frontend-design humanization + aesthetic standards → weline-theme-development CSS/tokens; fix fails (do not critique-only). Shot-only/silent screenshot: UI+prototype audit—NOT confirming prior chat. If host skills frontend-design or prototype are missing: prompt visibly and self-install into Cursor Agent Store before E/F pass (image_attachment_shentu_bundle.missing_host_skills_gate).',
                'HARD: Prototype/feature Web UI page groupings MUST default to TOP tabs (feature_ui_keep_simple_top_tabs): one job per pane; secondary blocks use click-to-expand cards (default collapsed); redesign non-compliant dense stacked pages to tabs in the same feature.',
                'Module doc skills are extracted from doc/ai/INDEX.json + SKILL.md (+ AI-INDEX locators) into MCP memory only; never revive knowledge.auto_generate_skills.',
            ],
            'feature_skill_bundle' => [
                'rule_id' => 'requirement_implicit_analysis_skill_decision',
                'also_rule_id' => 'requirement_feature_kind_gate',
                'clarify_rule_id' => 'requirement_clarify_use_case_spec',
                'clarify_skill_id' => GuidanceWorkflowCatalog::SURFACE_REQUIREMENT_CLARIFY_USE_CASE,
                'clarify_command_path' => 'dev/ai-command/ai/需求澄清与用例规格.md',
                'triggers' => ['work_kind=feature', '功能', 'feature', 'ui_skill_decision=participate', '隐形需求', '需求澄清', '用例规格'],
                'required_when' => 'ui_skill_decision=participate',
                'required' => [
                    ['role' => 'prototype', 'host_skill' => 'prototype'],
                    ['role' => 'ui', 'host_skill' => 'frontend-design'],
                ],
                'also_require_acceptance_type' => 'shentu',
                'acceptance_rule_id' => 'acceptance_phase_requires_shentu',
                'closeout_rule_id' => 'closeout_requires_huishen',
            ],
            'css_or_theme_skill_bundle' => [
                'rule_id' => 'css_or_theme_requires_ui_prototype_theme_skills',
                'triggers' => ['css', 'CSS', '主题', 'theme'],
                'required' => [
                    ['role' => 'ui', 'host_skill' => 'frontend-design'],
                    ['role' => 'prototype', 'host_skill' => 'prototype'],
                    [
                        'role' => 'theme',
                        'host_skill' => 'weline-theme-development',
                        'mcp_skill_id' => GuidanceWorkflowCatalog::SURFACE_FRONTEND_DEVELOPMENT,
                        'fetch' => 'get_skill',
                    ],
                ],
            ],
            'image_attachment_shentu_bundle' => [
                'rule_id' => 'user_image_attachment_triggers_shentu',
                'hard_trigger' => 'any_user_message_image_or_screenshot_attachment',
                'triggers' => [
                    'image_attachment',
                    'screenshot_attachment',
                    '用户附图',
                    '粘贴图',
                    '截图附件',
                    '附图',
                    '发图即审',
                    '审图',
                    '审查图',
                    'UI 审图',
                    '截图审查',
                    '线稿',
                    '抽取线图',
                    '原型调整',
                    '改 UI',
                    '调样式',
                    'CSS',
                ],
                'command_path' => 'dev/ai-command/theme/审图.md',
                'host_skill' => 'weline-ui-shentu',
                'scope_note' => 'Includes admin/CMS/error/product UI screenshots; not limited to storefront retail/B2B. Non-error ui_shot defaults to UI modification. Shot-only messages default to UI+prototype audit + wireframe + joint skills.',
                'shot_intent' => [
                    'error_shot' => 'prioritize_exception_root_cause_fix_keep_error_ui_readable',
                    'ui_shot' => 'default_require_ui_modification_fix_checklist_fails',
                ],
                'silent_shot_default' => [
                    'when' => 'image_only_or_arrows_only_user_message',
                    'intent' => 'ui_and_prototype_audit_of_visible_surfaces',
                    'forbid' => [
                        'treat_as_prior_chat_confirmation',
                        'treat_as_chat_illustration',
                        'skip_ef_because_looks_like_context_proof',
                        'critique_only_without_ui_fix',
                    ],
                ],
                'joint_pipeline' => [
                    'extract_structural_wireframe',
                    'prototype_adjustments',
                    'frontend_design_aesthetics',
                    'theme_css_tokens_via_weline_theme_development',
                ],
                'required_actions' => [
                    'read_every_attached_image',
                    'classify_web_ui_or_non_frontend',
                    'classify_error_shot_or_ui_shot',
                    'ui_shot_defaults_to_ui_modification',
                    'extract_structural_wireframe_line_sketch',
                    'provide_prototype_adjustments',
                    'for_web_ui_run_checklist_and_fix_fails',
                    'judge_humanization_and_aesthetics_with_frontend_design_and_prototype',
                    'judge_theme_fit_with_weline_theme_development',
                    'joint_frontend_design_prototype_theme_same_turn',
                    'silent_shot_defaults_to_ui_prototype_not_confirmation',
                ],
                'review_dimensions' => [
                    'humanization',
                    'aesthetic_standards',
                    'theme_fit',
                    'wireframe_structure',
                    'prototype_adjustment',
                ],
                'required_skills_for_web_ui' => [
                    ['role' => 'ui', 'host_skill' => 'frontend-design'],
                    ['role' => 'prototype', 'host_skill' => 'prototype'],
                    [
                        'role' => 'theme',
                        'host_skill' => 'weline-theme-development',
                        'fetch' => 'get_skill',
                    ],
                ],
                'missing_host_skills_gate' => [
                    'check' => 'available_skills_or_agent_store',
                    'required_host_skills' => ['frontend-design', 'prototype'],
                    'on_missing' => [
                        'prompt_user_visible_warning',
                        'self_install_to_cursor_agent_store',
                        'read_skill_bodies_before_ef_pass',
                    ],
                    'forbid' => [
                        'silent_skip',
                        'pass_ef_without_skills',
                        'ask_user_to_install_in_settings_only',
                    ],
                    'prompt_template' => '⚠ 审图依赖技能缺失：缺少「UI 技能 frontend-design」和/或「原型技能 prototype」。正在自行安装/挂载到本机 Cursor Agent Store；补齐并 Read 正文前，不得对 E（人性化）/ F（规范美观）写 pass。',
                    'install_targets' => [
                        'cursor_agent_store_skills_dir',
                        '~/.cursor/skills/{frontend-design,prototype}/',
                    ],
                ],
            ],
            'catalog' => $fullSummary,
            'catalog_counts' => [
                'total' => count($fullSummary),
                'workflow' => count($workflowSummary),
                'module_doc' => max(0, count($fullSummary) - count($workflowSummary)),
            ],
            'commands' => $repository !== '' ? DocSkillCatalog::extractCommands($repository) : [],
            'greeting' => $greeting,
            'extract_skills_command' => [
                'triggers' => ['提取技能', 'list skills', 'mcp skills'],
                'path' => 'dev/ai-command/ai/提取技能.md',
                'action' => 'Scan module doc skill indexes into MCP catalog and print the full skill + command list.',
            ],
        ];
    }

    /**
     * Index-only catalog listing for prepare_project / greeting (no bodies, no descriptions).
     * Full descriptions and paths remain on definitions() / get_skill / resolve_skill(includeContent).
     *
     * @return list<array<string, mixed>>
     */
    public static function summary(string $repository = '', ?string $kindFilter = null): array
    {
        $rows = [];
        foreach (self::definitions($repository) as $skill) {
            $kind = (string) ($skill['kind'] ?? '');
            if ($kind === '') {
                $kind = (($skill['surface_id'] ?? '') !== '') ? 'workflow' : 'module_doc';
            }
            if ($kindFilter !== null && $kind !== $kindFilter) {
                continue;
            }
            $row = [
                'skill_id' => $skill['skill_id'],
                'name' => $skill['name'],
                'aliases' => $skill['aliases'] ?? [],
                'kind' => $kind,
            ];
            $surfaceId = trim((string) ($skill['surface_id'] ?? ''));
            if ($surfaceId !== '') {
                $row['surface_id'] = $surfaceId;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(string $repository = ''): array
    {
        $skills = self::workflowDefinitions();
        if ($repository !== '') {
            foreach (DocSkillCatalog::extractSkills($repository) as $docSkill) {
                $skills[] = $docSkill;
            }
        }

        return $skills;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function workflowDefinitions(): array
    {
        $skills = [];
        foreach (GuidanceWorkflowCatalog::allSurfaces() as $surfaceId => $surface) {
            if (!is_array($surface)) {
                continue;
            }
            $aliases = [];
            foreach (self::HOST_SHELL_ALIASES as $alias => $canonical) {
                if ($canonical === $surfaceId) {
                    $aliases[] = $alias;
                }
            }
            $hostShell = is_string($surface['authoritative_skill'] ?? null)
                ? trim((string) $surface['authoritative_skill'])
                : '';
            if ($hostShell !== '' && !in_array($hostShell, $aliases, true)) {
                $aliases[] = $hostShell;
            }

            $row = self::fromSurface($surface, $aliases);
            $row['kind'] = 'workflow';
            $skills[] = $row;
        }

        foreach (self::HOST_SHELL_ALIASES as $alias => $canonical) {
            $found = false;
            foreach ($skills as $skill) {
                if (($skill['skill_id'] ?? '') === $canonical || in_array($alias, $skill['aliases'] ?? [], true)) {
                    $found = true;
                    break;
                }
            }
            if (!$found && isset(GuidanceWorkflowCatalog::allSurfaces()[$canonical])) {
                $row = self::fromSurface(GuidanceWorkflowCatalog::allSurfaces()[$canonical], [$alias]);
                $row['kind'] = 'workflow';
                $skills[] = $row;
            }
        }

        return $skills;
    }

    /**
     * Match skills for a task query (trigger / name / alias / id substring scoring).
     *
     * @return list<array<string, mixed>>
     */
    public static function resolve(
        string $task,
        int $limit = 5,
        bool $includeContent = false,
        string $repository = '',
        bool $listAll = false,
    ): array {
        $limit = $listAll ? max(1, min(500, $limit > 20 ? $limit : 500)) : max(1, min(20, $limit));
        $taskLower = mb_strtolower(trim($task), 'UTF-8');
        if (in_array($taskLower, ['提取技能', 'list', 'list_all', 'list skills', 'mcp skills', 'all'], true)) {
            $listAll = true;
            $taskLower = '';
        }
        $scored = [];

        foreach (self::definitions($repository) as $skill) {
            $score = 0.0;
            if ($listAll || $taskLower === '') {
                $score = $listAll ? 1.0 : 0.05;
            } else {
                $haystack = mb_strtolower(implode("\n", [
                    (string) $skill['skill_id'],
                    (string) $skill['name'],
                    (string) $skill['description'],
                    implode(' ', $skill['aliases'] ?? []),
                    implode(' ', $skill['triggers'] ?? []),
                    (string) ($skill['module'] ?? ''),
                    (string) ($skill['relative_path'] ?? ''),
                ]), 'UTF-8');
                if (str_contains($haystack, $taskLower)) {
                    $score += 1.0;
                }
                foreach ($skill['triggers'] ?? [] as $trigger) {
                    $trigger = mb_strtolower(trim((string) $trigger), 'UTF-8');
                    if ($trigger !== '' && str_contains($taskLower, $trigger)) {
                        $score += 0.55;
                    }
                }
                foreach ($skill['aliases'] ?? [] as $alias) {
                    $alias = mb_strtolower(trim((string) $alias), 'UTF-8');
                    if ($alias !== '' && (str_contains($taskLower, $alias) || $taskLower === $alias)) {
                        $score += 0.85;
                    }
                }
                $id = mb_strtolower((string) $skill['skill_id'], 'UTF-8');
                if ($id !== '' && (str_contains($taskLower, $id) || $taskLower === $id)) {
                    $score += 0.9;
                }
            }
            if ($score <= 0.0) {
                continue;
            }
            $row = self::publicSkill($skill, $includeContent);
            $row['score'] = round($score, 6);
            $scored[] = $row;
        }

        usort($scored, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_slice($scored, 0, $limit);
    }

    /**
     * Load one skill by skill_id, alias, or name (case-insensitive).
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $selector, bool $includeContent = true, string $repository = ''): ?array
    {
        $key = mb_strtolower(trim($selector), 'UTF-8');
        if ($key === '') {
            return null;
        }

        if (isset(self::HOST_SHELL_ALIASES[$key])) {
            $key = mb_strtolower(self::HOST_SHELL_ALIASES[$key], 'UTF-8');
        }

        foreach (self::definitions($repository) as $skill) {
            $candidates = [
                (string) $skill['skill_id'],
                (string) $skill['name'],
                ...array_map('strval', $skill['aliases'] ?? []),
            ];
            foreach ($candidates as $candidate) {
                if (mb_strtolower(trim($candidate), 'UTF-8') === $key) {
                    return self::publicSkill($skill, $includeContent);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $surface
     * @param list<string> $aliases
     * @return array<string, mixed>
     */
    private static function fromSurface(array $surface, array $aliases): array
    {
        $surfaceId = (string) ($surface['id'] ?? '');
        $docs = [];
        if (is_string($surface['authoritative_doc'] ?? null) && trim((string) $surface['authoritative_doc']) !== '') {
            $docs[] = trim((string) $surface['authoritative_doc']);
        }
        foreach (is_array($surface['authoritative_docs'] ?? null) ? $surface['authoritative_docs'] : [] as $doc) {
            $doc = trim((string) $doc);
            if ($doc !== '' && !in_array($doc, $docs, true)) {
                $docs[] = $doc;
            }
        }

        $norms = [];
        foreach (is_array($surface['norms'] ?? null) ? $surface['norms'] : [] as $norm) {
            if (!is_array($norm)) {
                continue;
            }
            $norms[] = [
                'id' => (string) ($norm['id'] ?? ''),
                'summary' => (string) ($norm['summary'] ?? ''),
                'detail_doc' => (string) ($norm['detail_doc'] ?? ''),
            ];
        }

        $triggers = [];
        foreach (is_array($surface['triggers'] ?? null) ? $surface['triggers'] : [] as $trigger) {
            $trigger = trim((string) $trigger);
            if ($trigger !== '') {
                $triggers[] = $trigger;
            }
        }
        foreach ($aliases as $alias) {
            if ($alias !== '' && !in_array($alias, $triggers, true)) {
                $triggers[] = $alias;
            }
        }

        $label = (string) ($surface['label'] ?? $surfaceId);
        $description = (string) ($surface['description'] ?? $label);

        return [
            'skill_id' => $surfaceId,
            'name' => $label,
            'description' => $description,
            'aliases' => array_values(array_unique(array_filter($aliases, static fn (string $a): bool => $a !== ''))),
            'surface_id' => $surfaceId,
            'triggers' => $triggers,
            'authoritative_docs' => $docs,
            'norms' => $norms,
            'verification_commands' => is_array($surface['verification_commands'] ?? null)
                ? $surface['verification_commands']
                : [],
            'template_surface_rules' => is_array($surface['template_surface_rules'] ?? null)
                ? $surface['template_surface_rules']
                : [],
            'feature_delivery_urls' => $surfaceId === GuidanceWorkflowCatalog::SURFACE_WEBUI_BROWSER_CLOSEOUT
                ? GuidanceWorkflowCatalog::featureDeliveryUrls()
                : null,
            'provider' => self::PROVIDER,
            'static_skill_files' => false,
        ];
    }

    /**
     * @param array<string, mixed> $skill
     * @return array<string, mixed>
     */
    private static function publicSkill(array $skill, bool $includeContent): array
    {
        $kind = (string) ($skill['kind'] ?? '');
        if ($kind === '') {
            $kind = (($skill['surface_id'] ?? '') !== '') ? 'workflow' : 'module_doc';
        }
        $row = [
            'skill_id' => $skill['skill_id'],
            'name' => $skill['name'],
            'description' => $skill['description'],
            'aliases' => $skill['aliases'],
            'surface_id' => $skill['surface_id'] ?? '',
            'triggers' => $skill['triggers'] ?? [],
            'authoritative_docs' => $skill['authoritative_docs'] ?? [],
            'norms' => $skill['norms'] ?? [],
            'provider' => self::PROVIDER,
            'static_skill_files' => false,
            'kind' => $kind,
            'module' => $skill['module'] ?? '',
            'relative_path' => $skill['relative_path'] ?? '',
            'host_shell_role' => ($skill['aliases'] ?? []) === []
                ? 'none'
                : 'optional_thin_mirror',
            'fetch' => [
                'tool' => 'get_skill',
                'skill_id' => $skill['skill_id'],
            ],
        ];
        if ($includeContent) {
            $source = trim((string) ($skill['content_source'] ?? ''));
            $row['content'] = $source !== '' ? $source : self::renderBody($skill);
            $row['content_format'] = 'markdown';
            $row['verification_commands'] = $skill['verification_commands'] ?? [];
            if (is_array($skill['template_surface_rules'] ?? null) && $skill['template_surface_rules'] !== []) {
                $row['template_surface_rules'] = $skill['template_surface_rules'];
            }
            if (is_array($skill['feature_delivery_urls'] ?? null)) {
                $row['feature_delivery_urls'] = $skill['feature_delivery_urls'];
            }
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $skill
     */
    private static function renderBody(array $skill): string
    {
        $lines = [];
        $lines[] = '# ' . (string) $skill['name'];
        $lines[] = '';
        $lines[] = (string) $skill['description'];
        $lines[] = '';
        $lines[] = '- skill_id: `' . (string) $skill['skill_id'] . '`';
        $lines[] = '- provider: MCP (`' . self::PROVIDER . '`)';
        $lines[] = '- static_skill_files: false（不以仓库/宿主 SKILL.md 为权威）';
        if (($skill['aliases'] ?? []) !== []) {
            $lines[] = '- host shell aliases（可选薄壳）: `' . implode('`, `', $skill['aliases']) . '`';
        }
        $lines[] = '';
        $lines[] = '## 权威文档';
        $lines[] = '';
        foreach ($skill['authoritative_docs'] ?? [] as $doc) {
            $lines[] = '- `' . $doc . '`';
        }
        if (($skill['authoritative_docs'] ?? []) === []) {
            $lines[] = '- （见 AI硬规则索引与对应 workflow surface）';
        }
        $lines[] = '';
        $lines[] = '## 规范要点';
        $lines[] = '';
        foreach ($skill['norms'] ?? [] as $norm) {
            if (!is_array($norm)) {
                continue;
            }
            $id = trim((string) ($norm['id'] ?? ''));
            $summary = trim((string) ($norm['summary'] ?? ''));
            if ($summary === '') {
                continue;
            }
            $lines[] = '- ' . ($id !== '' ? '`' . $id . '` — ' : '') . $summary;
        }
        $rules = is_array($skill['template_surface_rules'] ?? null) ? $skill['template_surface_rules'] : [];
        $required = is_array($rules['required'] ?? null) ? $rules['required'] : [];
        $forbidden = is_array($rules['forbidden'] ?? null) ? $rules['forbidden'] : [];
        if ($required !== []) {
            $lines[] = '';
            $lines[] = '## 必须';
            $lines[] = '';
            foreach ($required as $item) {
                $lines[] = '- ' . $item;
            }
        }
        if ($forbidden !== []) {
            $lines[] = '';
            $lines[] = '## 禁止';
            $lines[] = '';
            foreach ($forbidden as $item) {
                $lines[] = '- ' . $item;
            }
        }
        $commands = is_array($skill['verification_commands'] ?? null) ? $skill['verification_commands'] : [];
        if ($commands !== []) {
            $lines[] = '';
            $lines[] = '## 验证';
            $lines[] = '';
            foreach ($commands as $command) {
                $lines[] = '- `' . $command . '`';
            }
        }
        $lines[] = '';
        $lines[] = '## 取用方式';
        $lines[] = '';
        $lines[] = '1. `resolve_skill(task=…)` 发现匹配技能';
        $lines[] = '2. `get_skill(skill_id=' . (string) $skill['skill_id'] . ')` 加载本正文';
        $lines[] = '3. 任务文档片段仍用 `resolve_task_context`';

        return implode("\n", $lines);
    }
}
