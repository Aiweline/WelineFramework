<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Compiles framework hard constraints from Ai/doc into MCP-facing hard-constraints.v1.
 * Host bootstrap (AGENTS / ensure / session_startup_notices) must stay pointer-only.
 */
final class HardConstraintsCatalog
{
    public const SCHEMA = 'hard-constraints.v1';

    public const AUTHORITATIVE_DOC = 'app/code/Weline/Ai/doc/AI硬规则索引.md';

    public const AUTHORITATIVE_WORKFLOW_DOC = 'app/code/Weline/Ai/doc/AI工程交付流程.md';

    /**
     * Full package returned by prepare_project.agent_guidance.hard_constraints.
     *
     * @return array<string, mixed>
     */
    public static function package(): array
    {
        return [
            'schema' => self::SCHEMA,
            'must_obey' => true,
            'authoritative_doc' => self::AUTHORITATIVE_DOC,
            'authoritative_workflow_doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            'host_guidance_role' => 'pointer_only',
            'session_startup_notices_role' => 'pointer_only',
            'preamble' => self::preamble(),
            'rules' => self::rules(),
            'mcp_operational' => self::mcpOperationalRules(),
            'host_codex_delegation' => self::hostCodexDelegation(),
        ];
    }

    /**
     * Structured host-vs-Codex CLI work split for prepare_project.agent_guidance.
     * Independent of nested MCP CodexInvoker / knowledge.codex.enabled.
     *
     * @return array<string, mixed>
     */
    public static function hostCodexDelegation(): array
    {
        $policyId = 'host_delegate_explore_plan_review_to_codex_cli';
        $probeSnippet = <<<'SH'
if [ -n "${CODEX_CLI_PATH:-}" ] && [ -x "$CODEX_CLI_PATH" ]; then
  CODEX_BIN="$CODEX_CLI_PATH"
else
  CODEX_BIN="$(command -v codex 2>/dev/null || which codex 2>/dev/null || true)"
fi
SH;
        $planPrompt = '只读探索仓库；禁止改文件/写补丁。输出仅含 Markdown 三级：## 背景 / ## 方案 / ## 细节。'
            . '细节必须具体到文件路径、符号/规则 id、测试断言、验收与 fallback。'
            . '遵守 AGENTS.md 与 MCP hard_constraints；使用 Codex CLI 默认最新模型，禁止指定模型参数。';
        $reviewPrompt = '审查 staged/unstaged/untracked 改动：正确性、回归、安全、硬规则合规、测试缺口；'
            . '按严重程度列 findings；无阻断项时明确写无阻断项。使用 Codex CLI 默认最新模型，禁止指定模型参数。';

        return [
            'schema_version' => 'host-codex-delegation.v1',
            'policy_id' => $policyId,
            'applies_to' => [
                'coding_engineering_hosts',
                'cursor_and_non_codex_hosts_when_cli_available',
            ],
            'exemptions' => [
                'content_ops_skills_skip_mcp',
                'pure_chat_identity_concept_qa',
            ],
            'enabled_when' => 'codex_cli_available_and_host_is_not_codex',
            'native_codex_recursion_guard' => [
                'when_host_is_codex' => 'do_not_spawn_nested_codex',
                'action' => 'perform_explore_plan_review_natively_in_current_codex_session',
            ],
            'independent_of_nested_planner' => true,
            'independent_of' => [
                'knowledge.codex.enabled',
                'knowledge.codex.model',
                'CodexInvoker::planDocumentation',
            ],
            'binary_probe_order' => [
                'CODEX_CLI_PATH (executable)',
                'PATH codex via command -v / which',
            ],
            'binary_probe_shell' => $probeSnippet,
            'model_policy' => [
                'model' => 'cli_default',
                'model_argument_forbidden' => true,
                'forbid_flags' => ['-m', '--model'],
                'rationale' => 'Use the installed Codex CLI latest default model; do not pin an outdated model id.',
            ],
            'plan_mode_relationship' => [
                'host_plan_mode_for_planning' => 'container_and_user_approval_gate_only',
                'forbid_second_host_authored_plan' => true,
                'cursor_role_after_plan' => 'implement_code_only_from_codex_plan',
            ],
            'plan_content_contract' => [
                'sections_only' => ['背景', '方案', '细节'],
                'policy' => 'plan_content_focus_only',
                'detail_must_include' => [
                    'files_paths',
                    'symbols_or_rule_ids',
                    'test_assertions',
                    'acceptance',
                    'fallback',
                ],
            ],
            'plan_prompt' => $planPrompt,
            'review_prompt' => $reviewPrompt,
            // User must see that Codex—not the host—is the active worker while CLI runs.
            'user_visible_status' => [
                'required' => true,
                'forbid_silent_delegation' => true,
                'announce_in_chat_before_launch' => true,
                'announce_in_chat_on_complete' => true,
                'announce_in_chat_on_fallback' => true,
                'phases' => ['explore_plan', 'post_implement_review'],
                'must_include_tokens' => ['Codex', '正在工作'],
                'phrases' => [
                    'start_zh' => 'Codex 正在工作：{phase}…',
                    'done_zh' => 'Codex 已完成：{phase}',
                    'fallback_zh' => 'Codex 不可用，已回退宿主：{reason}',
                    'start_en' => 'Codex is working: {phase}…',
                    'done_en' => 'Codex finished: {phase}',
                    'fallback_en' => 'Codex unavailable; host fallback: {reason}',
                ],
                'phase_labels' => [
                    'explore_plan' => '探索与详细计划（codex exec）',
                    'post_implement_review' => '编码后审查（codex review --uncommitted）',
                ],
                'rationale' => 'Silent shell delegation hides who is working; the user must see an explicit Codex-working cue before/while CLI runs and a clear done/fallback cue afterward.',
            ],
            // Executable shell: prompts are piped via printf (not comments). Set REPOSITORY + PLAN_OUTPUT first.
            'plan_command_template' => trim($probeSnippet) . "\n"
                . 'test -n "$CODEX_BIN" || { echo "codex_cli_unavailable"; exit 1; }' . "\n"
                . 'test -n "$REPOSITORY" || { echo "repository_required"; exit 1; }' . "\n"
                . 'test -n "$PLAN_OUTPUT" || { echo "plan_output_required"; exit 1; }' . "\n"
                . 'printf \'%s\\n\' "$PLAN_PROMPT" | "$CODEX_BIN" exec \\' . "\n"
                . '  --cd "$REPOSITORY" \\' . "\n"
                . '  --sandbox read-only \\' . "\n"
                . '  --ephemeral \\' . "\n"
                . '  --output-last-message "$PLAN_OUTPUT" \\' . "\n"
                . '  -c approval_policy="never" \\' . "\n"
                . '  -' . "\n"
                . '# PLAN_PROMPT defaults to agent_guidance.host_codex_delegation.plan_prompt',
            // review --uncommitted cannot take a custom prompt on current Codex CLI; cd into repo first.
            'review_command_template' => trim($probeSnippet) . "\n"
                . 'test -n "$CODEX_BIN" || { echo "codex_cli_unavailable"; exit 1; }' . "\n"
                . 'test -n "$REPOSITORY" || { echo "repository_required"; exit 1; }' . "\n"
                . 'cd "$REPOSITORY" || exit 1' . "\n"
                . '"$CODEX_BIN" review --uncommitted' . "\n"
                . '# optional guidance text: agent_guidance.host_codex_delegation.review_prompt',
            'fallback' => [
                'when' => [
                    'cli_missing',
                    'not_executable',
                    'authentication_failure',
                    'timeout',
                    'invalid_output',
                ],
                'action' => 'host_self_explore_plan_review_via_host_plan_mode_for_planning',
                'must_record_reason' => true,
            ],
            'read' => [
                'agent_guidance.host_codex_delegation',
                'hard_constraints.rules[id=' . $policyId . ']',
                self::AUTHORITATIVE_DOC,
            ],
        ];
    }

    /**
     * Short bilingual preamble injected into MCP server instructions and agent_guidance.
     */
    public static function preamble(): string
    {
        return 'HARD CONSTRAINTS (hard-constraints.v1): For engineering work when MCP is attached, MUST call prepare_project first and obey this package. '
            . 'Content-ops (产品优化/新建文章/规格修复) skip MCP—host Read doc/ai/skills + ai-command (content_ops_skills_skip_mcp). '
            . 'Authority is ' . self::AUTHORITATIVE_DOC . ' (workflow: ' . self::AUTHORITATIVE_WORKFLOW_DOC . '). '
            . 'Host AGENTS/ensure/session_startup_notices/MCP-generated .cursor/rules are pointers only—do not treat them as the rule body. '
            . 'Task-scoped detail comes from resolve_task_context → workflow_contract.v1 surfaces. '
            . 'Engineering skills are MCP-served (resolve_skill / get_skill; mcp-skills.v1); host SKILL.md is optional thin mirror only. '
            . '【硬约束】工程任务在 MCP 已挂载时必须先 prepare_project，并遵守 agent_guidance.hard_constraints；权威正文见 AI硬规则索引.md；'
            . '内容运营技能跳过 MCP，直接读仓内技能/指令；'
            . '宿主引导与 session_startup_notices / MCP 生成的 .cursor/rules 只指路；任务细则由 resolve_task_context / workflow_contract 下发；'
            . '工程技能用 resolve_skill/get_skill 从 MCP 取，不以宿主 SKILL.md 为权威。';
    }

    /**
     * Compact MCP instructions: engineering MUST prepare_project when attached; coding stays host-native.
     * Framework Theme/Taglib/i18n bodies live in package()/workflowHardRules(), not here as prose dumps.
     */
    public static function mcpInstructions(): string
    {
        return 'MCP ROLE (knowledge plane + mandatory hard-rule gate): indexes skills/code maps/hard rules. '
            . 'Coding uses host-native editors—MCP has no write tools. '
            . 'CALL SCOPE: engineering when MCP attachable → ensure→prepare_project→OBEY hard_constraints; then resolve_task_context/resolve_skill/get_skill. '
            . 'Skip MCP for chat/content-ops (content_ops_skills_skip_mcp)—Read ai-command+doc/ai/skills; no prepare. '
            . 'Obey hard-constraints.v1 from agent_guidance or ' . self::AUTHORITATIVE_DOC . '. '
            . 'If MCP cannot attach: host Read ' . self::AUTHORITATIVE_DOC . '; do not invent rules. '
            . 'Scrutinize+architecture-first+decoupled; prefer framework_candidates; report 耦合提示/需求纠偏. '
            . 'preserve_dirty_workspace: never git checkout/restore/clean/stash to wipe dirty work; dirty-load current disk before edit; forbid other-session/old-baseline overwrite of live dirty files. '
            . 'LOCAL-FIRST runtime queries; production SSH only when user says 线上/生产/ssh weline/aiweline.com. '
            . 'i18n: module_i18n_chinese_source_default(source=简中); module CSV zh+en only; '
            . 'frontend_ui_requires_zh_en_csv(前端开发语言默认中文+中英CSV必译+i18n:collect); '
            . 'active_locale_must_show_target_language; '
            . 'user_mentions_translation_all_default_website_locales→默认站全语种进词典. '
            . 'Self-verify UT/RT/WB; feature e2e chapter+suite (formal headless); strip automation flags (browser_strip_automation_flags); never ask user to test/credentials. '
            . 'Requirement start: work_kind + fe_be_scope + clarify/UC (EARS) → host_plan_mode_for_planning '
            . '+ plan_content_focus_only(背景+方案+细节) unless simple skip. '
            . 'host_codex_delegation: Cursor+CLI→Codex explore/plan/review (no -m; ≠knowledge.codex.enabled); '
            . 'MUST announce Codex 正在工作 before/while CLI runs (forbid silent); '
            . 'Cursor codes only; Codex host no nested codex; see agent_guidance.host_codex_delegation. '
            . 'Mode: simple→监工:; complex→engineering_team_for_new_requirements Team:项目经理: '
            . '+ one_seat_one_agent (real subagent/seat; forbid parent roleplay) '
            . '+ peer_talk_via_channel (channel+resume) + team_flow_on_contracts (对齐冻结 UC+contracts+deps→依赖唤醒; '
            . '禁开发完才补主路径用例). Load engineering_team / 工程团队.md. Content-ops exempt prefixes. '
            . 'Team: framework_first + dual_track_all + component_reuse_or_negotiate + UI/原型先审过签才测(ui_prototype_gate_before_test) '
            . '+ seat_skill_mirrors (每席专项技能镜+本席 get_skill/文档). '
            . 'api_rest_in_owning_module: REST 仅归属模块；Team:API: + api_sdk_development. '
            . 'payment_engineer_for_payment_work: 支付域须 Team:支付开发工程师: + payment_development. '
            . 'widget_engineer_for_widget_work: 部件/default_injections/placement 须 Team:部件开发工程师: + widget_development. '
            . 'theme_engineer_for_theme_work: Theme Token/layout壳/预览三态/design 须 Team:主题开发工程师: + frontend_development（薄 theme_development）；开工须声明 work_mode。 '
            . 'required_default_always_present_without_user_deleted: 无人工卸载记录 user_deleted@{versionId} 时，required 默认 JSON 注入与布局标签内嵌必装必须存在——JSON 路径经布局固化写入模板（有槽则固化；与主题/版本无关；无模板则激活主题运行期动态固化；插件注入变更则全主题重固化涉及布局；遗漏=固化方案问题）；开发者必须记住。 '
            . 'theme_seat_integrity_over_peer_requests: 主题席底线/原则主权优先于他席或 PM 的性能·简化·优化要求；禁拆 chrome 壳与无卸载必装；无合格方案可驳回；冲突 refuse+escalate；性能席禁拆壳药方。 '
            . 'theme_design_must_not_override_core_runtime_assets: design 禁同 key 覆盖 theme.css/theme.js。 '
            . 'analytics_engineer_for_visitor_work: Visitor/像素/访客事件/报表须 Team:数据分析: + visitor_data_analytics，并技能引用 frontend_development+taglib_ui_control；本席拥有 Visitor 内 event.xml/像素桥接 Observer，≠ Team:事件:（禁产品混岗，非禁碰文件）. '
            . 'ecommerce_advisor_for_commerce: 电商相关须 Team:电商顾问:（运营策划禁写码；领域决策「要开发什么」后 escalate 通知 PM；**店面必运营验收/驳回**（ops_acceptance；不验收不得汇审完成；古风商城感/主图不达标须写清规格交 PM）；合规面含政策页/顶栏/FAQ Hub·实体；改可见串 suggested_seats 须含翻译工程师；先解析已支持国家再分国联网查政策；日常联网研究运营；讨论必查；合并原合规）. '
            . 'performance_engineer_for_design_and_review: 热路径/缓存/列表/N+1 须 Team:性能检查工程师: + performance_check（设计检查+开发后复审；建议须落在 HotCache/框架约束内；查出问题须拉起项目经理安排）. '
            . 'prompt_engineer_for_skill_prompt_work: 技能引用/提示词骨架/seat_skill_mirrors/MCP skill·surface 文案须 Team:提示词优化工程师: + prompt_optimization（仅重复才压；禁丢义；禁乱加；引用指针化+语义复审）. '
            . 'translation_engineer_for_i18n_work: 新文案/漏译/i18n in_scope/改用户可见文案 须 Team:翻译工程师:（别名 i18n）+ translation_engineer；collect→对比；模块 CSV 仅 zh+en；其它已选 locale→系统词典/实体；禁「其它语种默认不做」误导. '
            . 'requirement_issuer_owns_acceptance: escalate/dev_ask 发起席须 waiting_acceptance 盯验收（读 PM 进度写 issuer_acceptance；禁甩手；缺签收禁汇审）. '
            . 'ui_skill_decision; 有图(web_ui)→视觉UI+原型必上(prototype+frontend-design+weline-theme-development; complex team seats 原型+UI+前端+主题). '
            . 'requirement_acceptance_always; closeout 汇审; TDD; delivery URLs. '
            . 'After MCP use prefix Weline：; content[0] is the call receipt. '
            . 'session_learning_knowledge_conflict_gate: classify durable user rules as knowledge vs one-off requirements; '
            . 'obey validated learning; on conflict with prior knowledge STOP+report for user decision.';
    }

    /**
     * Structured rules compiled from AI硬规则索引 (summaries + doc pointers).
     *
     * @return list<array{id: string, summary: string, doc: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'route_via_hard_rules_index',
                'summary' => 'Before code changes, route mandatory docs via AI硬规则索引.md; do not invent requirements from generic framework folklore.',
                'doc' => self::AUTHORITATIVE_DOC,
            ],
            [
                'id' => 'weline_ui_theme_first',
                'summary' => 'All storefront/admin visual UI MUST use first-party Weline Theme (Weline UI 2.0) component classes and theme CSS variable tokens; forbid third-party UI kits, hard-coded visual literals, and naked address/region inputs when <w:theme:address> exists.',
                'doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            ],
            [
                'id' => 'theme_base_components_token_only',
                'summary' => 'MANDATORY for Theme/UI development: basic/foundation components (w-button, w-input, w-select, w-textarea, w-field, w-badge, w-alert, w-text, w-menu, w-dialog, w-toast, w-table, and peers in foundation.css / Weline UI 2.0) MUST consume only semantic theme tokens (--weline-theme-*, --color-*, --backend-color-*, plus spacing/radius/shadow tokens). Forbid inventing private hex/rgb/hsl colors, parallel palettes, or component-local color CSS variables for those basics when building or restyling themes. Brand themes may override palette leaves in colors/_*.css only; they must not restyle basic components with hard-coded colors. Inherit the default semantic matrix from variables/_colors.css + colors/_default.css (frontend also loads _default before brand overlays like _ink).',
                'doc' => 'app/code/Weline/Theme/doc/theme-semantic-color-matrix.md',
            ],
            [
                'id' => 'ui_skill_requires_theme_skill',
                'summary' => 'MANDATORY: When using any host UI / frontend-design / aesthetic / prototype skill for Weline storefront OR admin/backend UI, MUST also load MCP skill weline-theme-development (get_skill) or surface frontend_development, plus Theme开发总指南.md and theme-css-variables-only.md BEFORE writing CSS/markup. Applies even when the user did not say CSS/主题—any visual .phtml/.css page work triggers this. Theme CSS tokens (--color-* / --weline-theme-* / --backend-color-* / spacing·radius·shadow) and Weline UI 2.0 classes (w-backend-page / w-card / w-field / w-input / w-button / w-table…) win over generic UI-skill palettes. Forbid inventing private hex/rgb palettes, px spacing scales, radius/shadow kits, parallel design tokens, or bare HTML form stacks that skip Theme/Weline UI chrome. UI/prototype skills may only guide composition, hierarchy, and copy within existing theme tokens. Host SKILL.md mirrors are optional and not authoritative over MCP. Also obey css_or_theme_requires_ui_prototype_theme_skills and backend_admin_ui_requires_frontend_theme_skills.',
                'doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
            ],
            [
                'id' => 'css_or_theme_requires_ui_prototype_theme_skills',
                'summary' => 'MANDATORY: Whenever the user requirement or task mentions CSS or 主题/theme (including Theme styling, theme tokens, storefront/admin visual CSS), BEFORE writing or changing styles/theme markup the Agent MUST load and obey all three: (1) UI skill frontend-design, (2) prototype skill prototype, (3) theme skill weline-theme-development via MCP get_skill (surface frontend_development). Theme tokens and Weline UI 2.0 still win; UI/prototype skills must not invent palettes or bypass Theme. Skip only for pure non-visual work with no CSS/theme intent. Prevents theme development drift.',
                'doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            ],
            [
                'id' => 'backend_admin_ui_requires_frontend_theme_skills',
                'summary' => 'MANDATORY for ANY admin/backend visual page or form (paths under view/templates/backend|Backend, */backend/* Controllers that fetch phtml, ACL menus that open admin UI): BEFORE writing or redesigning that UI, seats 前端/UI/原型/主题开发工程师 (and any Agent acting as them) MUST get_skill(frontend_development|weline-theme-development) AND Read Theme开发总指南.md + theme-css-variables-only.md, and MUST also load host skills frontend-design + prototype when doing layout/IA. Deliver with Weline UI 2.0 admin chrome (w-backend-page / w-backend-page__heading / w-card / w-field / w-input / w-select / w-button / w-table / w-alert—match peer Marketing/Smtp backend pages). FORBID primitive raw <h1>+naked <form>/<table> stacks, inline style="margin/width/border" decoration, hard-coded hex, or inventing a parallel admin kit. Backend-only Model/Service work with no phtml is out of scope. Complements ui_skill_requires_theme_skill, weline_ui_theme_first, engineering_team seat_skill_mirrors.',
                'doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            ],
            [
                'id' => 'user_image_attachment_triggers_shentu',
                'summary' => 'MANDATORY: When the user message includes any image/screenshot attachment (paste, Browser capture, acceptance shot, chat media)—including admin/CMS/error pages and other product UI, not only storefront retail/B2B—OR when 审图/布局调整/不够人性化/被吐槽 UX complaints apply—the Agent MUST immediately execute MCP command 审图 (dev/ai-command/theme/审图.md): Read every attached image (when present), classify web_ui|frontend_candidate|non_frontend and error_shot|ui_shot. ANY IMAGE = VISUAL UI SIGNAL: after classification web_ui OR confirmed frontend_candidate, FORCE ui_skill_decision=participate (FORBID skip); visual UI work is in_scope; Prototype + UI skills MUST come online—never treat the shot as chat illustration. COMPLEX engineering-team waves MUST staff real seats 原型 + UI + 前端 + 主题开发工程师 (roster + one_seat_one_agent); SIMPLE 监工 still MUST load prototype + frontend-design + weline-theme-development same turn (no Team: prefix). Content-ops product/主图/详情附图 stay on content_ops paths (no 监工/Team). non_frontend-only shots do not force seats. NON-ERROR DEFAULT: ui_shot means UI modification is required (checklist fails including human factors, aesthetic standards, and theme fit → fix to pass)—NOT critique-only, NOT prior-chat confirmation. JOINT PIPELINE (same turn): (1) extract structural wireframe/line sketch of visible layout, (2) prototype adjustments via prototype skill MUST change IA/placement, (3) humanization/aesthetics via frontend-design MUST adjust UI, (4) theme CSS/tokens via weline-theme-development (get_skill)—do not invent palettes. Prototype + frontend-design participation is MANDATORY on 审图—never skip them. error_shot prioritizes exception/root-cause fix while still keeping error UI readable. SILENT/SHOT-ONLY: image-only or arrows/? → UI+prototype audit of visible surfaces. Before E/F, verify host skills frontend-design and prototype; if missing, user-visible warning + self-install into Cursor Agent Store, then Read bodies—never pass E/F without them. Do NOT wait for 审图/审查图/UI 审图. Do NOT skip because another bug/task is open. Host weline-ui-shentu is thin reminder; authority is the command file + this rule (image_attachment_shentu_bundle). Also obey acceptance_phase_requires_shentu during verify.',
                'doc' => 'dev/ai-command/theme/审图.md',
            ],
            [
                'id' => 'product_optimize_triggers_detail_suite',
                'summary' => 'MANDATORY HIERARCHY + content_ops_skills_skip_mcp: 产品优化 (parent) CONTAINS three parallel child branches—NOT the same as 详情优化 alone. Parent triggers 产品优化/商品优化/product optimize (or /product/ URL + those intents, or 优化主图+详情/整品优化) → host Read ONLY `dev/ai-command/product/产品优化.md` + `app/code/Weline/Product/doc/ai/skills/ecommerce-product-optimize/SKILL.md` (and child paths). SKIP MCP prepare_project / resolve_skill / get_skill / project index on these turns. HARD: parent Agent MUST launch EXACTLY THREE parallel subagents in one turn and paste each branch skill+command into prompts: (1) main/gallery/variant via ecommerce-product-image + companions/weline-image-pipeline.md (lock catalog target_ar; square→1:1; peel then TRUE generative AI outpaint; FORBID cover-crop skinny/rembg/solid pads/blur-fill/fake expand/empty upscale/skip-images while claiming parent done); (2) 详情优化.md + ecommerce-detail-suite (layout/textify/selling points/data-weds); (3) 翻译优化.md + ecommerce-product-i18n (detect Website::getLanguageCodes()+\'\' completeness for product name/description/attrs; field-complete true-translate; verify /{locale}/product/). HARD CLOSEOUT: parent MUST run TWO review passes (审查#1 then 审查#2) against each child skill gate checklist; on FAIL, rework ONLY failing slot(s) with named defects (slot + skill clause + asset/locale/phenomenon)—forbid vague rework; after rework re-review until that pass PASSes; claim done ONLY after 审查#2 all-PASS with both review tables in the report. Forbid finishing parent with only detail or only images or only i18n, or delivering on first subagent report without dual review. Child-only triggers: 详情优化… → slot② only; 翻译优化/商品翻译/多语补全 → slot③ only; 主图优化/修主图 → slot① only—do NOT claim full parent suite. Skip data-weds="xq"/"<!--weds:xq-->" (compat data-weline-detail-suite=) for detail without force; NEVER 1688-as-skip. Incomplete main AR or locale leak still requires slots①/③. After detail success write data-weds="xq". Browser cache-off; then close Browser. Bare /product/ without optimize intent must NOT auto-trigger. Bundle product_optimize_detail_suite_bundle.',
                'doc' => 'dev/ai-command/product/产品优化.md',
            ],
            [
                'id' => 'blog_article_methodology_gate',
                'summary' => 'MANDATORY + content_ops_skills_skip_mcp for Blog/cultural long-form article work: when the user asks to create, rewrite, translate, review feasibility, or retarget entry links for blog posts (triggers 新建文章/写博客/博客文章/精写文章/审查文章/文章可行性/修文章/blog article/review blog article, or work whose deliverable is /blog/{slug} prose), Agent MUST immediately host-Read and obey `dev/ai-command/blog/新建文章.md` + repo skill `app/code/Weline/Blog/doc/ai/skills/weline-blog-article/`—SKIP MCP prepare_project / resolve_skill / get_skill / index loads. Modes: create|review|remediate. HARD: research online before factual claims; Blog not CMS for long-form body; open-license provenance images (forbid AI textile/object fakes as evidence); write only via BlogPostAdminService; translate ALL default-website language_codes (forbid Ollama unless user explicitly asks this turn); entry widgets must link blog/{slug} via @url (not bare /blog/... or /search?q=); after Theme/Catalog link edits clear ThemeRuntimeCacheCleaner + server:reload. Review mode uses review-checklist.md (可发|需补|不可发)—if user asks to fix, remediate (no critique-only). Not product 详情优化 / 产品优化. Bundle blog_article_methodology_bundle.',
                'doc' => 'dev/ai-command/blog/新建文章.md',
            ],
            [
                'id' => 'requirement_feature_kind_gate',
                'summary' => 'MANDATORY at requirement-start on every coding/engineering ask: classify work_kind as feature|non_feature BEFORE architecture mapping or code edits. feature = new/changed deliverable product capability or user-visible surface (page/interaction/business loop). non_feature = docs-only, hard-rule/MCP gate, pure infra, or non-product-surface fix. Prototype/UI participation is NOT auto-forced by feature alone—obey requirement_implicit_analysis_skill_decision and ui_skill_surface_signal_gate (analyze then decide; visual signals force participate). When ui_skill_decision=participate: skill_participation MUST include prototype+frontend-design+weline-theme-development and acceptance MUST include type=shentu. Obey acceptance_phase_requires_shentu.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'requirement_clarify_use_case_spec',
                'summary' => 'MANDATORY Spec Kit/Kiro-style gate BEFORE architecture mapping or code edits on every coding/engineering ask: (1) Run MCP command 需求澄清与用例规格 (dev/ai-command/ai/需求澄清与用例规格.md) and/or get_skill(requirement_clarify_use_case|weline-req-clarify). (2) For work_kind=feature: persist owning-module `doc/开发/spec/{feature-slug}.md` with status clarified|ready-for-plan, ≥1 user story, ≥2 EARS acceptance lines (WHEN/IF…SHALL), ≥1 use case (UC) with main success path steps that can feed type=e2e / Browser WB-OP; raise status to ready-for-plan only after readiness checklist passes. (3) Clarify underspecified areas with ≤5 targeted questions per round; encode Q&A into the spec Clarifications section—do not invent answers. (4) non_feature may set clarify_status=skipped with rationale ≥24 chars. (5) Forbid jumping from a one-line user ask straight to PHP/phtml patches. Complements requirement_framework_scrutiny, requirement_implicit_analysis_skill_decision, ui_feature_requires_e2e; does not replace architecture_design or delivery URLs.',
                'doc' => 'dev/ai-command/ai/需求澄清与用例规格.md',
            ],
            [
                'id' => 'host_plan_mode_for_planning',
                'summary' => 'DEFAULT MANDATORY during planning (after clarify when applicable, BEFORE business code): enable host Plan Mode—Cursor SwitchMode target_mode_id=plan—through architecture_design + chapter plan until the user approves implement, then SwitchMode to agent. Forbid production PHP/phtml/CSS while still planning. When Codex CLI is available on a non-Codex host, Plan Mode is the APPROVAL/CONTAINER only—plan body MUST come from host_delegate_explore_plan_review_to_codex_cli (forbid a second host-authored vague plan). SIMPLE SKIP allowed when plan_complexity=simple AND plan_skip_rationale≥24 chars AND all of: single owning module, no new extension-point invention, no multi-chapter plan, scope ≤~2h / one clear surface, no ambiguous FE+BE architecture choices. Simple skip still REQUIRES requirement_acceptance_always + FE/BE scope analysis; it does NOT skip acceptance or Browser WB-OP when Web is touched. If host has no Plan Mode: plan read-only and record host_plan_mode=unavailable + rationale≥24 (or use simple skip when eligible). Plan body MUST obey plan_content_focus_only. Complements requirement_clarify_use_case_spec, requirement_acceptance_always, architecture_first_for_requirements, host_delegate_explore_plan_review_to_codex_cli.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'host_delegate_explore_plan_review_to_codex_cli',
                'summary' => 'MANDATORY for coding/engineering hosts when Codex CLI is available: non-Codex hosts (esp. Cursor) MUST probe CODEX_CLI_PATH → PATH `codex` (`command -v` / `which`) and, when found, delegate (1) codebase explore + detailed implementation plan via read-only `codex exec` and (2) post-implement review of staged/unstaged/untracked diffs via `codex review --uncommitted` to the Codex CLI latest/default model—FORBID passing `-m`/`--model` (do not pin outdated models). USER-VISIBLE STATUS (hard): BEFORE launching any delegated `codex` Shell, the host MUST emit a user-visible chat line that includes the tokens `Codex` and `正在工作` (or English `Codex is working`) naming the phase (explore_plan / post_implement_review); AFTER finish emit `Codex 已完成` / `Codex finished`; on fallback emit `Codex 不可用，已回退宿主` with reason—FORBID silent delegation that looks like ordinary host work. Independent of nested MCP planner (`knowledge.codex.enabled` / `knowledge.codex.model` / CodexInvoker::planDocumentation)—host shell delegation does NOT require that flag. Cursor role AFTER a Codex plan: implement coding edits ONLY from that plan; FORBID inventing a second vague host plan. Cursor Plan Mode (`host_plan_mode_for_planning`) remains the user-approval container only—must not author a conflicting plan. Codex plan body MUST obey `plan_content_focus_only` (背景/方案/细节) with concrete files, symbols/rule ids, test assertions, acceptance, and fallback. When the current host IS already Codex: do explore/plan/review natively—FORBID spawning nested `codex exec`/`codex review` (recursion guard). On CLI missing/not executable/auth failure/timeout/invalid output: fallback to existing host explore+Plan Mode+self-review and RECORD the reason (with the fallback chat cue). EXEMPT: content_ops_skills_skip_mcp and pure chat/identity Q&A. Read agent_guidance.host_codex_delegation (incl. user_visible_status) for command templates and announce phrases.',
                'doc' => self::AUTHORITATIVE_DOC,
            ],
            [
                'id' => 'engineering_team_for_new_requirements',
                'summary' => 'MANDATORY mode pick by the parent session itself—do not wait for the user to say 工程团队 (engineering_team_for_new_requirements). SIMPLE (plan_complexity=simple, plan_skip rationale≥24): 监工模式 only—one supervisor, no roster, no meeting; every user-facing line MUST start with 监工: and MUST NOT contain Team:. COMPLEX (engineering ask that is not simple): the parent itself chooses team mode and which seats to call this wave. ONE_SEAT_ONE_AGENT (hard): each staffed seat MUST be a real host subagent (Task/equivalent)—FORBID the parent role-playing other seats by swapping Team: prefixes. Parent may utter ONLY Team:项目经理:; other Team:{席位}: lines are allowed solely as verbatim relays of that seat subagent report (e.g. relay Team:架构师:). PEER_TALK_VIA_CHANNEL (hard): seats converse via doc/开发/team/{slug}/channel/{thread}.md + resume of the peer real subagent; PM is switchboard only—FORBID inventing multi-seat dialogue. Persist roster.md with seat→agent_id. Waiting peer → result=waiting_peer + peer_to + channel_msg. FORBID plain paragraphs, fullwidth colons, or [架构师] in team mode. Content-ops (content_ops_skills_skip_mcp: 产品优化/详情优化/翻译优化/主图优化/新建文章/规格修复) MUST NOT use either prefix and keep their own squads. Load dev/ai-command/ai/工程团队.md or get_skill(engineering_team|weline-engineering-team). SEAT_SKILL_MIRRORS (hard): every staffed seat prompt MUST include base skeleton + engineering_team_bundle.seat_skill_mirrors.{seat} increment; seat MUST get_skill/Read its mcp_skill_ids and authoritative_docs before coding/reviewing (e.g. 前端→frontend_development+taglib_ui_control+Theme指南; 事件→event_extension+事件命名规范; API→api_sdk_development+API接口开发规范; 后端→模块开发完整指南+module_upgrade_gate)—FORBID generic-skeleton-only launches. FRAMEWORK FIRST: requirements/design/build/review map to framework mechanisms/components before business patches. DUAL TRACK ALL specialty seats: each triggered seat has 施工 + 合规复审; fail → rework, never enter acceptance dirty. FLOW (team_flow_on_contracts): after 立项会, run 对齐冻结会 (测试主持) to freeze executable UC + contracts.md + deps.md BEFORE tech-scheme finalization and construction—FORBID designing main-path use cases only after development finishes; acceptance wave EXECUTES frozen UC only (gaps → back to align-freeze). Construction is wake-on-deps concurrency: start only seats whose deps are satisfied; FORBID whole-team idle waiting at the finish line. Core roster: 项目经理(parent)+需求分析+领域探查+架构师+后端+前端+UI+原型+测试+安全+文档; framework seats by trigger matrix (扩展点/事件/查询/Taglib/Hook/Provider/i18n/ACL/Setup/电商顾问/API/部件开发工程师/主题开发工程师/支付开发工程师)—seats join by wave. Commerce touches MUST staff 电商顾问 (no code; web policy research; merges former 合规). REST/SDK changes MUST staff API seat and obey api_rest_in_owning_module. Widget/default_injections/placement work MUST staff 部件开发工程师 (dual track). Theme Token/layout壳/预览三态/app/design work MUST staff 主题开发工程师 (dual track; theme_engineer_for_theme_work). Payment shell/Provider/refund/webhook/reconcile/Payable money-path MUST staff 支付开发工程师 (dual track; payment_engineer_for_payment_work). UI in_scope MUST staff 原型+前端+主题开发工程师+UI; freeze components.md; insufficient components → 原型∥UI (±主题开发工程师) negotiate into component-negotiate.md before inventing. Persist surfaces.md + contracts.md + deps.md + meetings/align-freeze.md + doc/开发/team/{slug}/. Concurrency only when files/extension points do not overlap AND contracts+UC are frozen. Escalation: result=escalate then 专题会; if nobody can decide OR a major architecture contradiction, 停工汇报 and FORBID PHP/phtml/CSS until the user confirms. UI/PROTOTYPE GATE BEFORE TEST (ui_prototype_gate_before_test, UI in_scope): after specialty 合规复审, UI+原型 MUST review development results with reject authority (acceptance-ui.md / acceptance-prototype.md); either fail → PM resumes development subagent(s) to adjust → re-review; FORBID Tester executing acceptance e2e/WB until BOTH pass; after Tester pass → hand to 项目经理; ONLY after PM 汇审 pass may report completion/delivery to user—e2e green does NOT waive UI/原型 gate or PM 汇审. Subagent closed is not delivery. LOCAL DEV TEST ACCOUNTS (hard, see local_dev_test_accounts_self_serve): backend default admin/admin; frontend self-create—NEVER ask the user for credentials. Complements requirement_clarify_use_case_spec, host_plan_mode_for_planning, closeout_requires_huishen, forbid_user_manual_test_handoff, api_rest_in_owning_module.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'api_rest_in_owning_module',
                'summary' => 'MANDATORY for Admin/Frontend REST (AbstractRestController / BackendRestController / FrontendRestController / module Api/Rest/**), BinQuery/QueryProvider operations (query-bin or /bin/query), or public API/SDK contracts: (1) REST controllers ONLY under the resource-owning Vendor_Module—Website APIs under Weline_Websites; dictionary/translation APIs under Weline_I18n; never cross-module Rest. (2) SHARED QUERY CORE: reusable read/write I/O contracts live in owning-module QueryProvider; query-bin, /bin/query, and optional thin REST are entry shells only—default thin REST is #[Acl]/@Document then w_query(ns,op,params) (see Websites Provisioning/DomainRegistrar); FORBID re-implementing business in Rest/gateway shells. Domain Service/Model stay behind Provider. Independent REST without Query is allowed only for multipart/stream, webhooks, auth token exchange, fixed public SDK shapes, or framework QueryBin/BinQuery gateway shells. (3) DEFAULT DENY on Attribute BinQueryOperation: external=false and frontend=false unless explicitly opted in; public/Worker/external exposure MUST declare auth (any|guest|customer|backend) and per-entry gates—FORBID relying on undeclared auth as “secured”. First-party browser/admin business I/O MUST be QueryProvider/BinQuery via Weline.Api→query-bin—FORBID storefront parallel REST + native fetch; external HTTP/third-party SDK MAY use thin or exceptional REST. (4) PER-ENTRY PERMISSION MATRIX (hard): REST→class/method #[Acl]; in-app query-bin→FrontendQueryGateway+auth and backend ops require backend_acl; external /bin/query→external=true (+frontend when needed)+API Key scope covering mode; CDN public read only when external∧mode=read∧cache.cdn∧visibility=public. FORBID bypass (REST Acl present but same op missing backend_acl on query-bin; write+external without valid Key/scope). Each exposed operation MUST document entry×gate rows. w_query/FrameworkQueryService does NOT re-check descriptor gates—trust the caller. (5) Engineering-team waves that touch Rest OR BinQuery Provider MUST staff framework seat API (Team:API:) with seat_skill_mirrors + get_skill(api_sdk_development|weline-api-sdk) and Read API接口开发规范.md + BinQuery Provider开发指南.md; dual-track 施工+合规复审. Query seat reviews mechanism/contract (shared-core consistency) but MUST NOT skip API seat for Provider implementation. (6) Backend seat may own Service/Model behind the API but MUST NOT author Rest/QueryProvider files for the API seat. (7) REST methods need full @Document/@example/@param/@return; backend Rest needs #[Acl]; QueryProvider changes need framework:compile and query:help discoverability. (8) Same change set MUST update owning-module REST/API docs. Complements engineering_team_for_new_requirements, framework_decoupled_only, module_upgrade_gate, weline_api_not_raw_fetch.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/API接口开发规范.md',
            ],
            [
                'id' => 'payment_engineer_for_payment_work',
                'summary' => 'MANDATORY for Weline_Payment domain work: universal payment shell orchestration, docking a new payment method (Extends Provider + SystemConfig + checkout template), create/resume/authorize/capture/void/refund/query lifecycle, webhook/browser return/cancel, reconcile, Connect/OAuth, Payable money-path, or return/refund fund movement. Engineering-team waves MUST staff framework seat 支付开发工程师 (Team:支付开发工程师:) with seat_skill_mirrors + get_skill(payment_development|weline-payment-development) and Read 支付开发.md + payment-shell.md + provider-development.md (+ webhook.md when callbacks); dual-track 施工+合规复审. Backend / generic Provider seats may review SPI or supply business Resolver but MUST NOT skip 支付开发工程师 for Payment Provider implementation or shell rewrites. HARD: obey shell_provider_business_isomorph (channel business only in Extends Provider; shell Controllers orchestrate only); unified callback URLs via PaymentShellCallbackUrlCatalog + shell_token; amount_minor integers; refunds via Provider.refund + shell Refund/Ledger with stable codes/idempotency; verifyCallback/parseCallback pure (no write/queue/remote); PCI—no card PAN/CVV in shell/templates; CSP via Provider.cspDirectives() (forbid hardcoding gateway domains in Framework Defaults); secrets only in SystemConfig encrypted fields. HARD closed-loop acceptance (payment_browser_e2e_closed_loop): code edits alone are NOT done—payment engineer MUST wake real subagent Team:测试: (via PM or channel+resume) to run host real Browser WB-OP (cache disabled) over the full frontend pathway of each touched payment method (select→submit→success/fail/cancel; refund/Webhook when in-scope—e.g. PayPal change ⇒ full PayPal flow); payment-seat compliance review may PASS only after Tester Browser pass + durable evidence (transaction_no/order_uuid); FORBID unit/curl/shell-smoke as sole substitute; FORBID handoff to user. Complements shell_provider_business_isomorph, engineering_team_for_new_requirements, acceptance_real_business_pathway, browser_operator_self_test, forbid_user_manual_test_handoff.',
                'doc' => 'dev/ai-command/ai/支付开发.md',
            ],
            [
                'id' => 'widget_engineer_for_widget_work',
                'summary' => 'MANDATORY for Widget / storefront slot injection work: new or changed widgets, @widget/@param templates, extends/module/Weline_Widget/**/widget.php registration, default_injections, placement=layout|injection, empty <w:slot> fills, or layout-inline XOR JSON decisions. Engineering-team waves MUST staff framework seat 部件开发工程师 (Team:部件开发工程师:) with seat_skill_mirrors + get_skill(widget_development|weline-widget-development) and Read 部件开发指南.md + Theme开发总指南.md (widget placement); dual-track 施工+合规复审. Frontend/主题开发工程师 seats may consume injected widgets or Theme-owned inline widgets but MUST NOT skip 部件开发工程师 for foreign-module default_injections or cross-module layout <w:widget>/fetch. HARD: obey theme_layout_widget_owner—same-module layout tag XOR JSON; cross-module only empty slot + owning-module JSON; Theme layouts inline only Weline_Theme; never both paths (double render). Verify frontend:check-theme-layout-widgets (+ frontend:check-required-injection-sibling-fetch when applicable). Complements theme_layout_widget_owner, engineering_team_for_new_requirements.',
                'doc' => 'app/code/Weline/Theme/doc/部件开发指南.md',
            ],
            [
                'id' => 'theme_engineer_for_theme_work',
                'summary' => 'MANDATORY for Theme Token / layout shell / preview three-modes / design override work: Theme semantic colors, variables/_*.css, base w-* visual tokens, Theme layouts/partials shells, content-width/版心, preview/editor/runtime three-mode identity, Theme Editor chrome, app/design/{Vendor}/{theme} overlays, or Weline_Theme-owned widget templates/inlines. Engineering-team waves MUST staff framework seat 主题开发工程师 (Team:主题开发工程师:) with seat_skill_mirrors + get_skill(theme_development|frontend_development|weline-theme-development) and Read 主题开发.md + Theme开发总指南.md + theme-css-variables-only.md (+ preview-and-runtime-modes.md / theme-inheritance-and-file-conventions.md when applicable); dual-track 施工+合规复审. HARD work_mode gate: BEFORE any Theme/view/theme or app/design file edit, declare work_mode∈{default_theme,design_theme,theme_module_runtime}; undeclared edits FORBIDDEN; one wave one mode (cross-mode needs justification). Frontend/UI seats may consume tokens and w-* classes but MUST NOT skip 主题开发工程师 for Token/shell/preview/design ownership. HARD: forbid inventing private hex/rgb palettes or parallel spacing/radius kits; forbid Theme layouts inlining non-Weline_Theme widgets; defer foreign default_injections/placement XOR to 部件开发工程师 (widget_engineer_for_widget_work + theme_layout_widget_owner unchanged). HARD memorize required_default_always_present_without_user_deleted: without manual uninstall user_deleted@{versionId}, required JSON default_injections and layout-tag inlines always exist. Generation compat: old Team:主题: ≡ same seat. Complements theme_design_must_not_override_core_runtime_assets, required_default_always_present_without_user_deleted, weline_ui_theme_first, theme_base_components_token_only, preview_storefront_delivery_parity, engineering_team_for_new_requirements.',
                'doc' => 'dev/ai-command/ai/主题开发.md',
            ],
            [
                'id' => 'required_default_always_present_without_user_deleted',
                'summary' => 'MANDATORY system truth—developers MUST memorize (主题开发工程师 + 部件开发工程师 + Theme/Widget runtime): As long as there is NO manual uninstall `user_deleted@{versionId}`, required default JSON `default_injections` AND layout-tag-inlined required widgets MUST exist on storefront. MECHANISM for JSON path: bake into the layout’s solidified template whenever the target slot exists—independent of theme active flag / theme version. ONLY omission = this-version `user_deleted@{versionId}`. Missing defaults ⇒ solidification scheme/trigger bug (NOT “optional request overlay”). No solidified template ⇒ runtime dynamic solidify for the currently active theme; existing templates ⇒ re-solidify only when the theme adds/removes widgets; plugin install/change of JSON default_injections ⇒ re-solidify involved layouts under ALL themes (`rebakeAfterInjectionCollect`). FORBID treating published-shell completeness, `+skip_fill_solidified`, or editor non-backfill as license to drop required defaults. XOR placement still applies. Authority: Theme/doc/布局固化与默认注入.md; short spec Theme/doc/开发/spec/required-default-always-present.md; REQ-THEME-0036. Complements theme_layout_widget_owner, theme_engineer_for_theme_work, widget_engineer_for_widget_work, theme_seat_integrity_over_peer_requests.',
                'doc' => 'app/code/Weline/Theme/doc/布局固化与默认注入.md',
            ],
            [
                'id' => 'theme_seat_integrity_over_peer_requests',
                'summary' => 'MANDATORY seat-principle sovereignty for 主题开发工程师 (Team:主题开发工程师:): the bottom line is that Theme modules (Token / layout·partial chrome shells / required-default paths / preview parity / design mount) MUST keep working correctly and completely in the system—this OUTRANKS peer or PM requests framed as performance / simplify / 商城感 / optimize. HARD veto (驳回权): unless peers (架构师+性能检查工程师±部件) have designed a concrete scheme that BOTH solves the stated problem AND preserves theme integrity (no strip-shell / no drop of required defaults without user_deleted), 主题开发工程师 MAY and MUST reject the request—FORBID implementing half-baked optimize pressure. FORBID obeying such pressure by: (1) deleting or bypassing required JSON `default_injections` or layout-tag inlines when there is NO `user_deleted@{versionId}`; (2) stripping or hollowing storefront/admin chrome shells (header / footer / nav / 版心 container and other required layout·partial skeletons); (3) substituting “fewer widgets = faster” for legitimate HotCache/CachePolicy/batch Query/prefetch/FPC/compile-plane optimizations. On conflict: write channel dissent → result=escalate + @项目经理：请立刻组队解决 (suggested_seats: 架构师 + 性能检查工程师 + 部件开发工程师)—FORBID silently wrecking the page then reporting done. Symmetric HARD for 性能检查工程师: design/review MUST NOT list removing header/footer/required widgets or clearing injections as an allowed optimization direction; such diffs → review fail + escalate. Complements required_default_always_present_without_user_deleted, theme_engineer_for_theme_work, theme_layout_widget_owner, performance_engineer_for_design_and_review, findings_wake_pm. Authority: 主题开发.md §席位底线; 性能检查.md §禁拆壳; theme-engineer-charter meetings/席位底线补钉.md.',
                'doc' => 'dev/ai-command/ai/主题开发.md',
            ],
            [
                'id' => 'theme_design_must_not_override_core_runtime_assets',
                'summary' => 'HARD: Design themes under app/design/{Vendor}/{theme} MUST NOT override same logical-key assets/css/theme.css or assets/js/theme.js (ThemePathResolver::isCoreRuntimeAsset)—such overlays blank the default global component/runtime entry. Brand via colors/_*.css, variables/_*.css, and separate assets/css/{brand}.css mounted with <theme:css>. Only work_mode=default_theme may edit Theme/view/theme assets/css/theme.css|assets/js/theme.js. Complements theme_engineer_for_theme_work; details in 主题开发.md Mode B.',
                'doc' => 'dev/ai-command/ai/主题开发.md',
            ],
            [
                'id' => 'analytics_engineer_for_visitor_work',
                'summary' => 'MANDATORY for Weline_Visitor domain work: entire module ownership including pixel runtime (pixel.js / WelinePixel.track / weline-pixel:: / data-pixel-event|data-visitor-event|data-cta-event|data-ga-event), event dictionary/chains, PixelEventVendor Extends + fan-out (GA4/GTM/third-party), sandbox monitor, conversion dedupe, Visitor-owned event.xml/Observer pixel bridges, and analytics reports/dashboards. Engineering-team waves MUST staff framework seat 数据分析 (Team:数据分析:) with seat_skill_mirrors + get_skill(visitor_data_analytics|weline-visitor-analytics) + get_skill(frontend_development|weline-theme-development) + get_skill(taglib_ui_control|weline-taglib-first) (frontend skill refs HARD) and Read 像素拓展使用指南.md + Visitor_Pixel_GTM_GA4_系统设计.md + 像素事件供应商管理-定稿合同.md + event/事件链注册.md + event/访客像素标签.md + 开发/spec/conversion-event-dedupe.md + 数据分析功能使用指南.md + store-saleschannel-scope.md + Theme开发总指南.md + Taglib 场景映射表 + Weline.Api使用指南.md; dual-track 施工+合规复审. Frontend/Backend MUST NOT skip 数据分析 for Visitor shell/runtime/Vendor/report work—declarative markup may be peer-helped by 前端 under this seat’s contract. HARD product-role split (NOT absolute file ban): this seat ≠ Team:事件: (generic Framework Event); this seat OWNS Weline_Visitor/etc/event.xml and Visitor/Observer/** pixel bridges and Weline_Visitor::* event contracts—FORBID treating Visitor pixel as framework Event seat work; FORBID Event seat writing pixel.js/Vendor/reports/Visitor pixel Observers. Config scope = Website→Store→Channel via <w:scope> ≠ path filter scope_json. Dual-channel: GtmBridge sole dataLayer export; enable GTM ⇒ disable GA4 direct (anti double-count). Dedupe: sandbox.emit → occupy → then fan-out; FORBID early return null on main track. FORBID business-module dataLayer bypass or private third-party pixel scripts; third-party via PixelEventVendorInterface + cspDirectives(); browser I/O only Weline.Api.resource|graph|stream (BinQuery)—forbid native fetch and Api.request/get/post to business Controllers. Complements engineering_team_for_new_requirements, weline_business_scope_hierarchy.',
                'doc' => 'app/code/Weline/Visitor/doc/像素拓展使用指南.md',
            ],
            [
                'id' => 'ecommerce_advisor_for_commerce',
                'summary' => 'MANDATORY for commerce-related complex engineering-team work touching Product/Catalog/Cart/Checkout/Order/Payment-policy/Shipping/站店渠 (or 商品/购物车/结账/订单/运费/促销/退换货/跨境/首页落地页运营设计/获客投放KOL活动策划). Staff framework seat 电商顾问 (Team:电商顾问:; aliases 电商开发顾问/运营策划) as a real subagent from 立项波; MUST attend 对齐冻结会 and 技术方案会 and record stance—FORBID freezing UC/contracts/mechanisms without 电商顾问表态. Role is OPS PLANNER + ADVISOR ONLY: domain decisions (「要开发什么」+ success criteria), campaign/growth planning, requirement reasonableness, ecommerce design briefs (homepage/funnel), map to local ecommerce module capabilities; FORBID writing PHP/phtml/CSS/JS/XML/JSON config (paths_changed must be none). HARD workflow: after domain decision is finalized, MUST escalate to 项目经理 with @项目经理：请立刻组队解决 + suggested_seats + dev_ask—PM same-turn staffs technical discussion (怎么开发); FORBID advisor self-scheduling construction or bypassing PM to direct tech seats. Merges former 合规 seat: dual track = 顾问轨(运营策划/领域决策) + 政策与合规复审轨; write meetings/电商顾问-review.md; fail blocks acceptance. HARD storefront compliance copy checklist (pointer): Theme policy pages, top-bar/marketing claims, FAQ Hub (FaqHubContent/FaqSeedCopyCatalog/w_weline_faq_item) + FAQ entity packs, Cookie/privacy chrome—when fail escalate changes user-visible strings, suggested_seats MUST include 翻译工程师 (+ theme/frontend as needed). HARD ops research: WebSearch current ops articles before discussions/domain decisions; cite URL+date. HARD policy research: FIRST resolve site-supported countries from LOCAL sources (Shipping RegionService::getCountries / Payment method supported countries / requirement-narrowed subset; website locales are auxiliary only)—FORBID a fixed CN/US-only checklist. THEN WebSearch current regulations per relevant country for the ask; MUST re-search before every discussion/align-freeze/tech-scheme/review stance (do not treat prior chat memory as already researched); cite supported_countries + source URL/official name + retrieval date—FORBID claiming no risk without sources; high unresolved risk → escalate or 停工. Content-ops execution (产品优化/详情优化/…) SKIP this seat as runner (advisor may set strategy/acceptance standards only). Payment implementation remains 支付开发工程师. HARD ops acceptance gate (汉服古风商城): 电商顾问 MUST验收监控 storefront visible surfaces after construction/publish (ops_acceptance=pass|fail); FORBID PM 汇审/user-done without ops acceptance; layout/古风商城感/主图效果 fail MUST escalate with clear image/layout specs for PM to staff; FORBID rubber-stamp weird broken pages. Complements engineering_team_for_new_requirements, findings_wake_pm, translation_engineer_for_i18n_work, task_plan_compliance_review ecommerce dimension, payment_engineer_for_payment_work.',
                'doc' => 'dev/ai-command/ai/电商顾问.md',
            ],
            [
                'id' => 'performance_engineer_for_design_and_review',
                'summary' => 'MANDATORY for complex engineering-team work touching storefront/admin hot paths, listing/catalog/PDP/search, HotCache/CachePolicy/CachePool/WLS shared cache, FPC/warmup, N+1/batch prefetch, slow-request/TTFB/cold-start performance, or new shared cache keys. Staff framework seat 性能检查工程师 (Team:性能检查工程师:) as a real subagent from 立项波; MUST attend 立项讨论 / 对齐冻结会 / 技术方案会 together with 架构师 and record stance—FORBID freezing hot-path/cache UC/contracts without BOTH 架构师 and 性能检查工程师表态. Role MUST actually check performance (not旁听): require deep knowledge of Weline framework structure (modules/extension points/WLS request lifecycle/HotCache·CachePolicy·CachePool layering) AND this ask’s business characteristics (storefront vs admin, personalization/drafts, which hot-path segment, reusable batch owners) before advising—FORBID optimization directions without a business-characteristic summary + framework mapping. MUST review whether cache design is compliant (CachePolicy scope/vary/dependencies, invalidation owners, no mutable Model/personalized HTML/drafts in shared pools, no business-class parallel process bags, no swapping DB N+1 for WLS RPC N+1). MUST jointly discuss with Team:架构师: to customize optimization directions (target hot paths, allowed mechanisms, forbiddens, evidence bar); minutes architect_joint=true. HARD no-strip-shell prescriptions (theme_seat_integrity_over_peer_requests): allowed directions are framework-legal only (HotCache/CachePolicy, batch Query, prefetch, legitimate FPC/compile-plane)—FORBID listing remove header/footer/nav/版心 chrome, drop required widgets, or clear default_injections as optimization; such design → 否决; such diffs → review fail + escalate. Dual track: 设计检查轨 (meetings/性能检查-design.md) + 实现复审轨 after coding (meetings/性能检查-review.md with pass/fail + evidence)—both waves MUST check performance; fail blocks acceptance. HARD after findings (design fail / review fail / probe P0–P2 / cache非合规): MUST immediately escalate to wake Team:项目经理: with @项目经理：请立刻组队解决 (suggested_seats)—NO Issue task list; FORBID privately sequencing rework waves or only notifying the user. HARD: get_skill(performance_check|weline-performance-check) and Read 性能检查.md + 统一缓存范围与性能优化.md + 扩展点选型.md BEFORE advising. Default: write read-only probes + review minutes; feature rework goes to owning seats via PM. Content-ops exempt. Complements engineering_team_for_new_requirements, findings_wake_pm, theme_seat_integrity_over_peer_requests.',
                'doc' => 'dev/ai-command/ai/性能检查.md',
            ],
            [
                'id' => 'prompt_engineer_for_skill_prompt_work',
                'summary' => 'MANDATORY for complex engineering-team (or dedicated) work that creates/edits skill references, subagent prompt skeletons, seat_skill_mirrors / prompt_increment, MCP skill/surface descriptions (McpSkillCatalog / GuidanceWorkflowCatalog), HardConstraintsCatalog skill-adjacent wording, host thin-mirror SKILL.md descriptions, or compresses duplicate descriptions across team skills/commands—OR when the user asks 提示词优化 / 技能压缩 / 技能引用优化 / 优化题词. Staff framework seat 提示词优化工程师 (Team:提示词优化工程师:) as a real subagent before bulk prompt rewrites. Role MUST optimize prompts: skill refs are pointers (get_skill ids + doc paths)—FORBID pasting other skills’ full bodies as “references”; duplicate hard-rule/checklist text keeps ONE authority + pointers elsewhere; slim prompt_increment to seat-unique HARD/boundaries (do not restate generic skeleton). HARD rewrite iron rules: (1) ONLY edit after identifying duplication evidence (≥2 same-meaning expansions with paths+excerpts)—FORBID rewriting without duplication evidence; (2) FORBID omitting/weakening original meaning—mandatory staffing, forbiddens, seat boundaries, artifact paths, hard-rule ids must remain executable after compression; (3) FORBID inventing new rules/flows/seat duties not in the original text—pointers may only target existing authorities; (4) pointer replacement ≠ deleting the sole authority body. Dual track: 优化施工轨 (meetings/提示词优化-design.md with duplication evidence) + 语义复审轨 (meetings/提示词优化-review.md pass/fail)—FORBID deleting/weakening hard-rule semantics, mandatory staffing, forbiddens, Team:{seat}: exact names, or dual-track artifact paths while marking pass; FORBID adding novel obligations in the same diff. HARD: get_skill(prompt_optimization|weline-prompt-optimization) and Read 提示词优化.md + 工程团队.md + AI硬规则索引.md BEFORE rewriting. On semantic-regression conflicts: findings_wake_pm escalate to 项目经理. Default: edit command/mirror/surface prompt text; business feature code goes to owning seats. Content-ops (产品优化/详情/主图/翻译) exempt. Complements engineering_team_for_new_requirements, seat_skill_mirrors, findings_wake_pm.',
                'doc' => 'dev/ai-command/ai/提示词优化.md',
            ],
            [
                'id' => 'translation_engineer_for_i18n_work',
                'summary' => 'MANDATORY for complex engineering-team (or dedicated) work that adds/changes user-visible copy (Theme/policy/FAQ Hub/FAQ entity/marketing chrome etc.), i18n=in_scope, locale-leak screenshots (e.g. Chinese under en_US or English fallback under ru_RU), site-wide translation audit, or interface/flow tip translation—OR when staffing the former thin seat i18n. Staff framework seat 翻译工程师 (Team:翻译工程师:; alias i18n) as a real subagent (PM MUST staff same wave or immediately after escalate; construction seats may self-carry this skill). Role MUST audit storefront/admin regions so active/default locale matches UI+flow tips; on mismatch translate/fix (except brand/trademark/tech tokens). HARD workflow: resolve WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT) → php bin/w i18n:collect → Chinese source default → compare gaps → write translations → collect again → spot-check ≥1 non-zh/en locale (e.g. ru_RU/de_DE). MODULE CSV FORMAT BOUNDARY ONLY: zh_Hans_CN + en_US—FORBID other module locale CSV. FORBID misleading「其它语种默认不做」phrasing: every other selected default-website locale MUST land in system dictionary upsert+publishLocale (and FAQ/entity locale rows as owned)—aligns active_locale_must_show_target_language; user-mentioned 翻译 also triggers user_mentions_translation_all_default_website_locales (same path). MUST get_skill(translation_engineer|weline-translation-engineer)+template_i18n+module_i18n_csv and Read 翻译工程师.md + 模块翻译CSV规范.md. FORBID rewriting templates to English to fake locale display; FORBID treating content-ops product 翻译优化 as this seat. On blocked ownership escalate wake 项目经理. Complements module_i18n_chinese_source_default / active_locale_must_show_target_language / module_i18n_csv_collect / user_mentions_translation_all_default_website_locales; content-ops exempt.',
                'doc' => 'dev/ai-command/ai/翻译工程师.md',
            ],
            [
                'id' => 'local_dev_test_accounts_self_serve',
                'summary' => 'MANDATORY for local/dev acceptance (UT/RT/WB-OP/Playwright e2e) on this repo: Agent/engineering team MUST NOT ask the user for login credentials or “please log in and verify”. Backend DEFAULT username/password = admin / admin (same as tests/e2e loginAsAdmin and PLAYWRIGHT_ADMIN_* fallbacks). Frontend: create a customer yourself (register UI or CLI); recommended e2e.customer@weline.local / E2eTest!234—create if missing. Env vars override when set; when unset MUST use these defaults. Production/线上 is out of scope (backup/auth rules apply). Failed default login → self-heal (reset password / fix captcha bootstrap / check WLS) or report 「验收未完成」with technical evidence—still never solicit passwords from the user. Complements forbid_user_manual_test_handoff, engineering_team_for_new_requirements, agent_self_verify_before_done.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'findings_wake_pm',
                'summary' => 'MANDATORY when specialty seats FIND problems OR 电商顾问 finalizes domain decision「要开发什么」(电商顾问 policy/compliance/ops gaps or dev_ask, 性能检查工程师 design/review fails, 提示词优化工程师 semantic conflicts, 安全 findings, escalate-class cross-track gaps, etc.): (1) DO NOT use an Issue task list / board backlog. (2) Immediately escalate to 项目经理—return slip result=escalate with evidence, options≥2, recommendation, suggested_seats, notify_pm=true, and explicit @项目经理：请立刻组队解决 (advisor domain decisions also include dev_ask + success criteria). (3) 项目经理 MUST same-turn open channel/{finding-thread}.md, resume/staff real seats, meet (同意/异议/否决), write meetings/{finding-thread}.md, open/update a SESSION plan_id in doc/开发/session/{slug}.md, and wake owners—for advisor dev_ask, same-turn staff technical discussion (需求分析/架构/施工席; 顾问 only audits ops drift)—FORBID only relaying findings without staffing or without SESSION plan items. (4) Finding/decision seats FORBID self-arranging construction waves, bypassing PM to direct tech seats, or long user-only essays without escalate. Complements requirement_issuer_owns_acceptance, engineering_team_for_new_requirements, requirement_session_dashboard, pm_plan_lifecycle, ecommerce_advisor_for_commerce, performance_engineer_for_design_and_review.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'requirement_issuer_owns_acceptance',
                'summary' => 'MANDATORY after findings_wake_pm escalate/dev_ask: the issuing seat is the requirement issuer and MUST NOT hands-off. (1) After escalate, issuer continuous result MUST be waiting_acceptance (roster status=waiting_acceptance)—FORBID immediate closed or treating the seat task as done. (2) Periodic acceptance = wake on every progress milestone: construction closed, Tester pass, before 汇审; and when PM/parent monitors while the plan_id is still open → PM MUST resume the issuer. (3) When resumed, issuer MUST Read SESSION + PM progress report (channel/*-progress.md or equivalent) + delivery evidence, then write issuer_acceptance=pass|fail (storefront also ops_acceptance). fail → re-escalate with gap specs; pass + downstream gates → only then issuer may closed that finding. (4) SESSION plan_id MUST record issuer_seat + issuer_acceptance=pending|pass|fail. (5) FORBID 汇审 / user completion when any finding/dev_ask plan_id lacks issuer_acceptance=pass. Complements findings_wake_pm, pm_plan_lifecycle, requirement_session_dashboard, ecommerce_advisor_for_commerce.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'requirement_session_dashboard',
                'summary' => 'MANDATORY for every executable coding/engineering requirement (监工 and team): at kickoff create/maintain owning-module doc/开发/session/{feature-slug}.md (same slug as doc/开发/spec/{slug}.md; template dev/ai-command/ai/templates/requirement-session.md). SESSION is the single dashboard for wave/phase, plan_id lifecycle table, incomplete gaps, review index, delivery-notify log, and related_web_urls—team/{slug}/ meetings remain detail files. ONLY 项目经理 (or 监工 parent) may edit SESSION; specialty seats FORBID editing SESSION or closing plan_ids. No SESSION → FORBID construction code edits. SESSION 未完成清单 non-empty (not 「无」) → FORBID claiming done / user completion report. Complements pm_plan_lifecycle, engineering_team_for_new_requirements, closeout_requires_huishen.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'pm_plan_lifecycle',
                'summary' => 'MANDATORY PM charter for engineering-team (and 监工 equivalent): 项目经理 is sole SESSION bookkeeper and plan-lifecycle owner—NOT code author, NOT multi-seat roleplay, NOT a substitute for architecture/specialty合规/code review. Lifecycle: review plan gaps → staff seats → monitor → on every seat closed|escalate|waiting_peer|waiting_acceptance require notify_pm=true + @项目经理：本席已交付/上报，请检查并更新 SESSION → PM same-turn DoD check (deliverable paths exist; related_web_urls/evidence when required; frozen UC intent untouched; owner matches contracts; FORBID closing plan_id before test) → update SESSION → wake deps (deps-satisfied seats MAY run in parallel; SESSION bookkeeping MUST NOT serialize the whole team) → wait Tester pass notify → PM recheck acceptance process → on finding plan_ids also require issuer_acceptance=pass (requirement_issuer_owns_acceptance: write progress + @发起席：请验收进度 + resume issuer at construction closed / test pass / before 汇审 / each monitor turn while open) → close plan_id only then. Findings (e.g. performance) MUST open SESSION child plan_id with issuer_seat and stay open until build+test+PM recheck+issuer_acceptance=pass. Rework: record on SESSION then restaff—loop until clean. Report completion to user ONLY when all plan_ids closed + triggered specialty reviews pass + gaps 「无」 + 汇审 pass. Complements requirement_session_dashboard, findings_wake_pm, requirement_issuer_owns_acceptance, ui_prototype_gate_before_test.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'ui_prototype_gate_before_test',
                'summary' => 'MANDATORY for complex engineering-team waves with UI in_scope (ui_skill_decision=participate or roster 原型+UI): HARD ORDER after construction closed and triggered specialty 合规复审 pass—(1) UI and 原型 MUST review development results with reject authority: write meetings/acceptance-ui.md and meetings/acceptance-prototype.md with substantive live-page verdicts (not form attendance / tester proxy); (2) either fail → 项目经理 MUST resume the development subagent(s) (前端/UI/主题等相关施工席) to adjust, then re-review—FORBID PM/tester代签 or skipping; (3) FORBID Tester executing acceptance UT/RT/WB/e2e closeout runs until BOTH UI and 原型 verdict=pass (red-light skeleton during construction is still allowed; EXECUTION waits); (4) after Tester pass → hand to 项目经理; (5) ONLY after PM 汇审 pass may report completion/delivery URLs to the user. e2e green does NOT waive this gate. Non-UI waves may skip UI/原型 review but still Tester→PM 汇审 before user report. Complements engineering_team_for_new_requirements, acceptance_substantive_signoff, closeout_requires_huishen, forbid_user_manual_test_handoff.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],

            [
                'id' => 'plan_content_focus_only',
                'summary' => 'MANDATORY for every engineering plan body (Plan Mode create_plan / plan.md / session plan notes / user-facing architecture plan): write ONLY three focused sections—(1) 背景: why this ask and the current gap for THIS topic; (2) 方案: what to do and the chosen approach (mechanism/owning_module/reuse/not_to_do as compact bullets); (3) 细节: concrete how-to steps, chapter/dev_tasks, files/paths, and acceptance how. FORBID topic drift and padding: process-catalog essays, unrelated module tours, motivational fluff, restating the whole MCP workflow, parallel feature pitches, decorative overviews that do not change the build, or long narrative that re-explains hard rules already in hard_constraints. Keep required structured fields (requirements, architecture_design keys, acceptance) as short bullets under those three sections—do not invent extra “愿景/价值主张/行业背景” chapters. Complements host_plan_mode_for_planning; does NOT waive architecture_design_structured, acceptance planning, or plan compliance dimensions.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'requirement_fe_be_scope_analysis',
                'summary' => 'MANDATORY at requirement-start on every coding/engineering ask BEFORE code edits: analyze whether this ask needs frontend (phtml/CSS/Theme/JS/Browser UI), backend (Service/Model/API/Controller/Provider), both, or neither. Record fe_be_scope as frontend|backend|both|na with ≥1 concrete bullet each for in-scope sides (paths or surfaces). Forbid coding only one side when analysis shows both (e.g. new field without admin/storefront display, or UI-only without persist/API). Feeds impact_surfaces when entity/field signals fire and ui_skill_decision. Complements requirement_implicit_analysis_skill_decision and requirement_cross_layer_impact_gate.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'requirement_acceptance_always',
                'summary' => 'MANDATORY: Every coding/engineering requirement MUST be acceptance-verified before claiming done—never “code only”. Minimum: (1) ≥1 acceptance item with real evidence (unit/contract and/or runtime probe and/or Browser WB-OP); (2) Any Web/UI/.phtml/CSS/page touch MUST pass local host Browser WB-OP covering BOTH visual (WB-VIS when screenshot-capable) AND operator logic clicks/flows—even when Playwright e2e is skipped under simple classification; (3) work_kind=feature that is NOT simple MUST still obey ui_feature_requires_e2e + plan_full_pathway_e2e_suite. curl/CDP Runtime.evaluate alone MUST NOT substitute Browser WB-OP. Incomplete acceptance → only 「代码已改，验收未完成」. Complements browser_operator_self_test, agent_self_verify_before_done, forbid_user_manual_test_handoff.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
            ],
            [
                'id' => 'requirement_implicit_analysis_skill_decision',
                'summary' => 'MANDATORY at requirement-start on every coding/engineering ask BEFORE code edits: (1) Analyze the CURRENT environment for implicit/hidden requirements—existing Taglib/API/Model/Provider tables, ownership boundaries, mapping/config pages already present, data sync/pull needs, overflow/UX debts, coupling risks, and adjacent modules that make the ask incomplete if ignored. Record ≥1 implicit_requirements bullets (use 无/无隐形需求/none only when truly none after analysis). (2) Also run requirement_fe_be_scope_analysis (frontend/backend/both/na). (3) Plan reasonably from that analysis—do not over-build UI for taglib/API wiring; do not skip provider warehouse tables/pull when mapping needs remote warehouses; do not invent parallel controls when Taglib exists. (4) Decide ui_skill_decision=participate|skip FROM the analysis—NOT by blindly forcing prototype+UI on every feature, AND NOT by wrongly skipping when visual work is in scope. participate when layout/interaction/CSS redesign, new visual surface, messy existing page redesign, CSS/主题 intent, humanization/complaint/吐槽 signals, or 审图 is in scope; also force participate when goal/requirements/scope show UI surface signals (see ui_skill_surface_signal_gate). skip only with ui_skill_rationale (≥24 chars) when NO visual signals (e.g. pure Provider/API/Model, replace hand-filled IDs with existing tags, MCP gate-only). When participate: skill_participation MUST include prototype+frontend-design+weline-theme-development and acceptance MUST include type=shentu—prototype and UI MUST actually adjust (not critique-only). Complements requirement_feature_kind_gate, ui_skill_surface_signal_gate, requirement_framework_scrutiny, taglib_before_hand_rolled_controls, shell_provider_business_isomorph.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'requirement_cross_layer_impact_gate',
                'summary' => 'MANDATORY engineering gate when goal/requirements show entity+field+change signals (e.g. 给订单增加类型 / add product field): (1) impact_surfaces REQUIRED with subject + layers covering schema_model, service_api, admin_ui, storefront_ui, i18n, tests_e2e (optional event_hook/checkout_flow); each layer status=in_scope|na|out (na/out need note≥8). (2) schema_model must be in_scope; ≥1 of admin_ui|storefront_ui in_scope; service_api/i18n/tests_e2e in_scope or na+note. (3) Reject empty-only implicit_requirements (无/none). (4) Each in_scope layer must be covered by some concrete task. Prefer resolve_task_context.framework_candidates.impact_candidates for paths when MCP is used. Prevents literal single-layer patches. Complements requirement_implicit_analysis_skill_decision and architecture_design_structured. Non-entity asks may omit impact_surfaces.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'ui_skill_surface_signal_gate',
                'summary' => 'MANDATORY engineering gate: when goal, requirements, implicit_requirements, scope_paths, user complaints, 审图 context, OR any user image/screenshot attachment classified web_ui|frontend_candidate show visual/UX signals—.phtml/.css paths, view/templates|hooks, theme color/variable CSS, OR keywords/phrases such as 改UI/调样式/页面布局/布局调整/信息架构/审图/线稿/原型调整/CSS/主题/不够人性化/不人性化/被吐槽/难用/太乱/太丑/体验差/humanization—OR simply「有图/附图/粘贴截图」for product UI—REJECT ui_skill_decision=skip and REQUIRE participate with skill_participation including prototype + frontend-design + weline-theme-development plus type=shentu. HARD: 有图（本仓 Web UI）→ 视觉 UI in_scope + 原型必须上场（skills always; complex team also seats 原型+UI+前端+主题开发工程师）. Prototype and frontend-design MUST produce concrete layout/interaction adjustments (not critique-only). Negation phrases like 无布局重设计 do not count as signals and do NOT cancel an attached web_ui image. Pure backend/API/Provider/MCP-gate work without those signals may still skip with rationale. Complements requirement_implicit_analysis_skill_decision, user_image_attachment_triggers_shentu, css_or_theme_requires_ui_prototype_theme_skills.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'feature_add_requires_current_ui_review',
                'summary' => 'MANDATORY when a coding/engineering requirement adds or extends a user-visible feature on an existing Web UI surface (admin/CMS/storefront) AND ui_skill_decision=participate (or CSS/layout redesign is in scope): BEFORE inventing layout/placement or writing production CSS/phtml, Agent MUST open/capture the CURRENT live page (Browser cache-off) and run 审图 (dev/ai-command/theme/审图.md) on that current shot—wireframe → prototype placement → frontend-design → theme tokens. If the current UI is already messy/dense/broken hierarchy (乱), redesign that surface as part of the same feature (do not bolt a new widget onto a chaotic page). Complements requirement_implicit_analysis_skill_decision and acceptance_phase_requires_shentu; this rule is the design-time gate on the EXISTING page, not only post-change acceptance. Skip only when ui_skill_decision=skip with rationale that no visual redesign is in scope.',
                'doc' => 'dev/ai-command/theme/审图.md',
            ],
            [
                'id' => 'feature_ui_keep_simple_top_tabs',
                'summary' => 'MANDATORY for feature Web UI design AND prototype (admin/CMS/storefront): ALL page groupings MUST use TOP-level tabs (Theme/Weline UI `w-tabs` / top tablist)—one primary job per visible pane. Prototype verdict MUST default to top-tab IA, not a single long stacked page. When a page would stack distinct jobs (list + create form + dictionary/settings, progress + config/ops, monitor + edit), SPLIT into top tabs so users switch segments. Content that still needs to appear without owning a tab MUST use click-to-expand cards (`w-disclosure` / equivalent), default collapsed—forbid always-open multi-card walls. Non-compliant dense pages MUST be redesigned to top tabs in the same feature (do not bolt more blocks onto an overloaded page). Prefer top-level tabs over nested dense cards; secondary subtabs only inside the active primary segment. Complements feature_add_requires_current_ui_review, frontend-design/prototype participation, and 审图 density checks.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'acceptance_phase_requires_shentu',
                'summary' => 'MANDATORY during verify/acceptance when ui_skill_decision=participate, or when any browser/UI visual acceptance surface changed: Agent MUST run 审图 on acceptance Browser screenshots (dev/ai-command/theme/审图.md joint pipeline: wireframe → prototype → frontend-design → theme) before marking visual/shentu acceptance passed. Record type=shentu acceptance with evidence containing 审图/shentu/线稿/checklist signals; weak evidence blocks closeout. When ui_skill_decision=skip (analysis: no visual redesign), shentu may be omitted or na with explicit N/A reason—still keep unit/probe/browser evidence for wired surfaces. Complements user_image_attachment_triggers_shentu and agent_self_verify_before_done.',
                'doc' => 'dev/ai-command/theme/审图.md',
            ],
            [
                'id' => 'closeout_requires_huishen',
                'summary' => 'MANDATORY before claiming done: perform 汇审 (joint closeout review) and write huishen_notes containing the word 汇审 (≥8 chars) covering requirements, implicit_requirements, architecture, acceptance evidence, ui_skill_decision, and—when participate—prototype/UI plus 审图 conclusions. Missing/weak huishen_notes → do not claim closeout done. User-facing closeout report MUST include a 「汇审」 section. Obey plan_todo_evidence_closeout and docs_reconcile_on_closeout.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'theme_address_for_region_pickers',
                'summary' => 'Country/province/city/district/region pickers in storefront AND admin (forms, list filters, multi-select chips, system-embargo country add, destination/carrier coverage, region create) MUST use <w:theme:address> (selection=single|multi; levels/catalog as needed). Forbid hand-rolled country/region <select>, raw ISO country-code text inputs, custom chip rows that replace the tag, or cascading inputs that bypass Theme Address. Before writing .phtml controls, read Taglib 场景映射表.md and pick the official tag. Chips/menus come from the tag (and weline_ui_floating_primitives).',
                'doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
            ],
            [
                'id' => 'weline_ui_floating_primitives',
                'summary' => 'Menus, popovers, tooltips, combobox panels, dialogs/modals, product/media/icon pickers, address multi dropdowns, MCP/Agent frontend popups, and other floating surfaces MUST use Weline.UI primitives (Weline.UI.dialog, menu/popover/tooltip/combobox/anchored-float, or UI.floating.attach). Forbid inventing private modal overlays, hand-computed left/top, custom flip/boundary scripts, or private portal stacks that bypass the shared floating/dialog kernel.',
                'doc' => 'app/code/Weline/Theme/doc/widgets/anchored-float.md',
            ],
            [
                'id' => 'frontend_unified_content_container',
                'summary' => 'Storefront layouts/pages/widgets/module CSS MUST use the shared content-width shell from theme-layout-content-width.md — never invent a private page container. Shell A (inside Theme .w-container): width:100% + padding-inline:0 only (no second max-width/gutter). Shell B (standalone chrome-only layouts): width:min(100%, var(--weline-layout-content-max-width)) + padding-inline:var(--weline-layout-content-padding-inline) or .w-theme-content-width; forbid pixel fallbacks (1440px/1200px/1180px/90rem) and double gutters. Verify with ThemeFrontendLayoutsContentWidthContractTest / ThemeStorefrontModuleContentWidthContractTest when touching width shells.',
                'doc' => 'app/code/Weline/Theme/doc/theme-layout-content-width.md',
            ],
            [
                'id' => 'theme_js_module_declare_only',
                'summary' => 'Storefront Theme/widget/layout BUSINESS JS modules MUST register in weline.modules.js and load only via Weline.declare / data-weline-load / data-weline-declare (or head module-declarations hook). After any create/update/move/delete of module registrations or their paths, MUST run `php bin/w resource:compile welineModules` (or full resource:compile) before closeout—source registry alone does not update runtime base. Forbid widget/layout hand-rolled <script src="@static(...js)"> or bare <js> tags for module-level scripts (Cart/Checkout/Wishlist/Customer and equivalents). EXCEPTION (widget_static_assets_bake_to_head): widget static .js/.css listed on w:widget layout-source|source (or @widget.layout_source|source) and baked into the layout asset closure MAY be emitted once as external <link>/<script> by the unified position-aware bake emitter—NOT a license for ad-hoc template scripts. Reuse existing layout slots; do not invent parallel mounts.',
                'doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
            ],
            [
                'id' => 'widget_static_assets_bake_to_head',
                'summary' => 'MANDATORY for storefront widget static CSS/JS files: declare on <w:widget layout-source="Vendor_Module:path.css,..." source="..."> (and/or @widget.layout_source / @widget.source). At layout bake (ThemeLayoutEntityBakeCoordinator / Materializer, same gate as rebakeAfterInjectionCollect), collect node∪planned required (minus user_deleted) into page-assets/chrome-assets sidecar; layout-source is always emitted early in head. Ordinary source uses source-postion (takes precedence) or alias source-position, normalized as source_position, default head; body/end-body before closing body; footer before closing footer (without footer, fall back to body end). Deduplicate URLs with layout priority; ordinary source position priority is head > footer > body. FORBID ALL widget inline CSS/executable JS, including <style>, executable <script>, style= and on*= attributes; pass instance data via data attributes. FORBID template bare resource tags; FORBID per-request full widget-deps rescan as primary; FORBID welding sole truth into shell.phtml or chrome.rendered. Request path only merges dynamic deltas (overlay/preview/personalization/draft). layout-source restricted to chrome/header/footer/nav (or theme allowlist)—content widgets marking layout-source = contract FAIL. Authority: Theme/doc/部件静态资源固化规范.md. Complements theme_js_module_declare_only (bake-head exception) and widget_engineer_for_widget_work.',
                'doc' => 'app/code/Weline/Theme/doc/部件静态资源固化规范.md',
            ],
            [
                'id' => 'weline_js_loader_framework_only',
                'summary' => 'MANDATORY: Frontend view/statics/js/weline.js is the framework ModuleLoader core only. Allowed: (1) Weline.declare / Weline.load / data-weline-load|declare scanning and name-agnostic modulesLoad knobs—NO path-heuristic URL preloads; (2) a thin maintenance hook that lazily loads Maintenance module JS (e.g. maintenanceAsyncWait) on 503/maintenance signals—never embed maintenance or business UI in weline.js. Forbidden: path-heuristic boot lists; baking business logic or business module names—including cart, account, wishlist, compareShopper, miniCart*, storefront*, customer*, currency, coupon/redeem/apply—into defaults, nameMap fallbacks, or Weline.* business proxies. Core storefront i18n JS is owned by Weline_Framework module registration `i18n` (Weline_Framework::js/i18n.js); Theme declares load; external Weline_I18n is enhancement only. Do not rename Framework/Phrase to I18n (conflicts with lowercase Framework/i18n CSV packs). Business modules register in owning-module weline.modules.js (paths/globalVar/load) and widgets declare via data-weline-load / data-weline-declare / Weline.declare. Optional non-business transport aliases only: api / dom (and welineApi / welineDom)—NOT account.',
                'doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
            ],
            [
                'id' => 'dom_mutation_observe_via_weline_dom',
                'summary' => 'MANDATORY architecture: Document-wide MutationObserver (document / documentElement / body + childList|subtree) MUST use Weline.dom.observe (shared coalesced bus in weline.js)—never raw `new MutationObserver` on those roots from Theme/UI/Captcha/business widgets. The bus keeps ONE physical observer per target+options fingerprint, fans out onRecords/onFlush after a setTimeout quiet window (forbid rIC({timeout}) alone / double-rAF immediate reobserve). Element-scoped observers may stay private; DEV delivery_storm guard is a safety net only, not the fix. Authoritative: Frontend/doc/架构/DOM-Mutation观察总线.md + Theme 前端JS模块加载规范 §1.2.',
                'doc' => 'app/code/Weline/Frontend/doc/架构/DOM-Mutation观察总线.md',
            ],
            [
                'id' => 'taglib_before_hand_rolled_controls',
                'summary' => 'MANDATORY before ANY selective/domain picker in .phtml (country, region, website/store/channel scope, language, currency, website, file, icon, ACL, DataTable filters, provider/enum dropdowns that map to a Taglib): FIRST open Taglib 场景映射表.md + 标签全量索引.md and pick the official Taglib/Hook. Architecturally, selectable options SHOULD be tags—not hand-rolled <select>/<input type=text> ISO codes/chip rows. Examples: country/region → <w:theme:address selection=single|multi>; scope → <w:scope>; language → <w:i18n:switcher>. Only invent a new Taglib in the owning module when the catalog has none; never bypass an existing tag with raw HTML.',
                'doc' => 'app/code/Weline/Taglib/doc/场景映射表.md',
            ],
            [
                'id' => 'storefront_internal_url_via_url_helper',
                'summary' => 'MANDATORY: Storefront/admin in-site navigations (href/action/data-*-url, JSON links consumed by storefront HTML) MUST be generated by the official URL helpers—never concatenated as \'/\'.$path or hardcoded /module/action. Templates: @url/@frontend-url/@backend-url (or <url>/<frontend-url>/<backend-url>). PHP/Service/Query: Weline\\Framework\\Http\\Url::getUrl / getFrontendUrl / getBackendUrl (or $this->getUrl family). External http(s) URLs may stay as-is. Authoritative: Framework 06-url标签使用指南.md.',
                'doc' => 'app/code/Weline/Framework/doc/4-内置标签/06-url标签使用指南.md',
            ],
            [
                'id' => 'weline_business_scope_hierarchy',
                'summary' => 'Weline 「范围/Scope」 = Website → Store → Channel (Taglib <w:scope>, SystemConfigTargetScopeService, target_scope). Inheritance/fallback is channel ← store ← website ← global (SystemConfig::getFallbackScopes): child scopes inherit parent until overridden. Path/URL globs are 「路径过滤」, not Scope. Never invent a parallel scope model or stuff website/store/channel into path_include JSON. Authoritative: Websites/doc/store-saleschannel-scope.md + SystemConfig inheritance docs.',
                'doc' => 'app/code/Weline/Websites/doc/store-saleschannel-scope.md',
            ],
            [
                'id' => 'systemconfig_unified_config_terms',
                'summary' => 'MANDATORY: MCP retrieval and agent task routing MUST map configuration vocabulary (配置/统一配置/统一配置中心/系统配置/嵌入配置/配置嵌入/<w:config:*>/config:embed/config:field/SystemConfig/Weline_SystemConfig) to the framework unified SystemConfig surface and embedded config (<w:config:embed>). Never invent a parallel business config store, private Config Service, or ad-hoc settings UI when SystemConfig + Extends templates cover the need. Lexicon: LearningMcp\\SystemConfigTermRouting (path-intent + query expansion + context roles). Authoritative: SystemConfig/doc/README.md + config-embed标签使用指南.md.',
                'doc' => 'app/code/Weline/SystemConfig/doc/README.md',
            ],
            [
                'id' => 'systemconfig_config_embed_declared_keys',
                'summary' => 'MANDATORY: Business pages using <w:config:embed> MUST set field/fields to the EXACT declared <w:config:field key> strings from extends/module/Weline_SystemConfig/Config/{area}/*.phtml (path keys like dropship/platforms/enabled or short keys like product_share_enabled—whichever the template declares). Inventing short aliases (e.g. platforms_enabled for dropship/platforms/enabled) yields undeclared red banner 「没有这个字段」and non-editable controls. Declare Extends template before embed; prefer Affiliate/B2B Config shell (w-stack + <w:scope> + embed + SystemConfig center link). Authoritative: SystemConfig/doc/config-embed标签使用指南.md.',
                'doc' => 'app/code/Weline/SystemConfig/doc/config-embed标签使用指南.md',
            ],
            [
                'id' => 'template_lang_not_php_i18n',
                'summary' => 'Frontend template user-visible copy uses <lang> / @lang(); never <?= __() ?> in HTML body or attributes. @lang()/@lang{} treat unquoted commas as argument separators—source text containing commas must use quotes (@lang(\'a, b\')) or <lang>a, b</lang>, else compile yields ParseError.',
                'doc' => 'app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md',
            ],
            [
                'id' => 'at_lang_no_unquoted_comma',
                'summary' => 'Never write unquoted commas inside @lang()/@lang{} source text (e.g. @lang{支持 .ico, .png}); commas split args and compile to broken PHP like <?=__(\'支持 .ico\', .png)?>. Prefer <lang>…</lang> or quoted @lang(\'…\').',
                'doc' => 'app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md',
            ],
            [
                'id' => 'hook_triple',
                'summary' => 'New Hook requires hook.php + doc/hook/*.md + view/hooks/*.phtml.',
                'doc' => 'app/code/Weline/Hook/doc/Hook创建规范.md',
            ],
            [
                'id' => 'hook_name_type_partial_or_layout',
                'summary' => 'Standard Hook names MUST be {Module}::{frontend|backend}::{partials|layouts}::{component}::{position}. The type segment is ONLY partials or layouts — never theme-editor, checkout, product, account, etc. Put page/feature names in component or position.',
                'doc' => 'app/code/Weline/Hook/doc/Hook创建规范.md',
            ],
            [
                'id' => 'event_documented',
                'summary' => 'New Event requires documented name in doc/event/ and etc/event.xml; no undocumented dispatch.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/事件命名与注册规范.md',
            ],
            [
                'id' => 'module_version_bump',
                'summary' => 'After Model/Controller/event.xml/hook.php/register.php changes, the same change set MUST include etc/module.php with a strictly greater version. Then run setup:upgrade (or --route).',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
            ],
            [
                'id' => 'controller_url_action_not_http_prefix',
                'summary' => 'Controller get*/post*/put*/delete* prefixes are HTTP verbs only; URL actions must be edit/add/save — never /getEdit or /postSave.',
                'doc' => 'app/code/Weline/Framework/doc/2-快速开始/03-自定义控制器.md',
            ],
            [
                'id' => 'extension_point_before_code',
                'summary' => 'Complete extension-point selection before code changes; do not invent undocumented event names; do not new cross-module concrete Service/Model.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
            ],
            [
                'id' => 'requirement_framework_scrutiny',
                'summary' => 'MANDATORY for every coding/engineering change: AFTER understanding the user ask and BEFORE architecture mapping or code edits, scrutinize requirements against framework information (扩展点选型.md, AI硬规则索引, module doc/, Taglib/Hook/Event/Query) and optional resolve_task_context.framework_candidates. If the literal ask is unreasonable (coupled write, hand-rolled control when Taglib exists, invented events, wrong layer, overbuilt), MUST NOT implement it as-is—record problem + better approach in requirement_scrutiny (≥1; use 合理/无调整/ok when aligned), rewrite requirements to the corrected approach, then proceed. ALWAYS include scrutiny_basis {checklist≥2, doc_paths≥1} even when scrutiny is 合理. When adjustments exist, user reports MUST include a 「需求纠偏」 section. Obey architecture_first_for_requirements, architecture_design_structured, framework_decoupled_only.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'architecture_design_structured',
                'summary' => 'MANDATORY for every coding/engineering change: BEFORE code edits, record structured architecture_design {mechanism: Event|Query|Hook|Interface|Taglib|none:reason, owning_module: Vendor_Module, reuse[], invent[], not_to_do[] (≥1), req_map[{requirement,mechanism,target}] covering each requirements bullet}. Prefer resolve_task_context.framework_candidates for mechanism/reuse selection when MCP is used—do not invent event names or hand-roll Taglib domains. architecture prose may be derived from architecture_design but remains required (≥40 chars). Also require scrutiny_basis {checklist≥2 from FrameworkPlanCandidates::CHECKLIST, doc_paths≥1 under */doc/*}; 「合理」 alone is insufficient. Forbid hand-rolled language/currency/website select without Taglib and cross-module new Service/Model without coupling acknowledgment. Obey architecture_first_for_requirements, framework_decoupled_only, requirement_framework_scrutiny.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'architecture_first_for_requirements',
                'summary' => 'MANDATORY for every coding/engineering change: BEFORE code edits, map each understood (and scrutiny-corrected) user requirement to architecture-layer choices in architecture (≥40 chars, all risk levels including trivial) AND architecture_design (architecture_design_structured)—extension mechanism (Event/Query/Hook/Interface/Taglib/none), owning module boundaries, key paths/layers, and what not to invent. Forbid jumping from requirements straight to code patches. Obey requirement_framework_scrutiny, extension_point_before_code, framework_decoupled_only.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'framework_decoupled_only',
                'summary' => 'MANDATORY: Every coding/engineering change MUST follow framework information (扩展点选型.md, module doc/, AI硬规则索引, documented Event/Query/Hook/Interface/Taglib) and MUST use a decoupled design. Forbid coupled writes: cross-module new of concrete Service/Model, undocumented event names, hand-rolled controls when Taglib exists, or bypassing published Interface/Query. plan.architecture must state framework basis + decoupling; plan.coupling_findings is required (≥1 entry; use 无/无耦合/none when none). If coupling is discovered during analysis or implementation, record it in coupling_findings and MUST surface a user-facing 「耦合提示」 section in the feature report (never silently ship coupled code).',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/扩展点选型.md',
            ],
            [
                'id' => 'shell_provider_business_isomorph',
                'summary' => 'MANDATORY for Weline_Payment / Weline_Dropship (and any shell+Provider SPI isomorphic to Payment): vendor/supplier/method-specific business logic MUST live in the Extends Provider class (and optional helpers that Provider owns/calls)—one Provider file declares capabilities via Interface. Shell Controllers/Services MAY orchestrate (register/scan providers, ACL, Inbox, unified URLs, tables, scope tooling) and MAY call other shell classes, but MUST NOT reimplement or hardcode a specific provider API, credentials parse, catalog/fulfillment/freight/webhook/payment lifecycle, or per-vendor Controller rewrite. New supplier/method docking standard: Provider + SystemConfig template (+ Payment checkout template when needed). Authoritative: Payment/doc/payment-shell.md, Payment/doc/provider-development.md, Dropship/doc/dropship-shell.md, Dropship/doc/provider-development.md.',
                'doc' => 'app/code/Weline/Payment/doc/payment-shell.md',
            ],
            [
                'id' => 'docs_reconcile_on_closeout',
                'summary' => 'After each feature, reconcile owning module doc/ with shipped behavior before claiming done.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'plan_todo_evidence_closeout',
                'summary' => 'Never claim a multi-todo / multi-chapter plan “done/completed” unless EVERY todo/chapter has concrete evidence including its bound pathway e2e PASS (and feature plans also the final e2e-plan-suite). Partial work MUST be reported as “部分完成/代码已改，e2e 未通过” with an explicit unfinished checklist—NEVER as finished and NEVER by asking the user to close remaining test cases. Update module doc/开发日志.md with unfinished items. Marking Cursor todos completed without evidence is forbidden. Complements plan_full_pathway_e2e_suite.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'agent_self_verify_before_done',
                'summary' => 'MANDATORY self-verify after implement on every coding/engineering ask (prefer TDD red→green first)—obey requirement_acceptance_always + acceptance_real_business_pathway: the Agent MUST personally execute verification matching 开发标准验收层级 BEFORE claiming done—(1) pure logic: focused unit/contract tests actually run to PASS; (2) command/API/persist/runtime: real command or API result plus tests; (3) work_kind=feature that is NOT simple: Playwright e2e via `php bin/w e2e:run` MUST PASS for EACH chapter FULL business pathway (not shell/CTA-only smoke) AND finally the plan-level suite (ui_feature_requires_e2e / plan_full_pathway_e2e_suite / acceptance_real_business_pathway)—closeout MUST name durable artifacts (order_uuid/…); (4) ANY page/UI/.phtml/CSS touch needs host-available real Browser WB-OP for visual AND operator logic (unit/curl MUST NOT substitute; obey browser_operator_self_test)—even when e2e is skipped. Do not stop after code edits. acceptance status passed|skipped|na REQUIRES non-empty evidence; required e2e/WB only status=passed with strong evidence. If verification incomplete, report only 「代码已改，验收未完成」/「代码已改，e2e 未通过」/「代码已改，真实通路验收未完成」—never claim feature done and never hand testing to the user.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
            ],
            [
                'id' => 'browser_operator_self_test',
                'summary' => 'MANDATORY for any Web/UI/.phtml/CSS/page change OR any requirement whose fe_be_scope includes frontend: AI MUST use the host’s real local Browser (WB-OP) on agreed use cases covering BOTH (a) visual acceptance (layout/spacing/theme; WB-VIS screenshots when capable) AND (b) real operator logic (click/fill/navigate expected flows)—cache disabled on every open. Playwright e2e (`php bin/w e2e:run`) is ADDITIONAL for non-simple features (ui_feature_requires_e2e); skipping e2e under simple classification does NOT waive WB-OP. Unit tests, curl, and CDP Runtime.evaluate / one-off IDE probes MUST NOT substitute for WB-OP. If the host has no interactive Browser, report only “代码已改，WebUI 验收未完成”—do not ask the user to test. Obey browser_cache_disabled_on_open, browser_operator_non_preemptive, and requirement_acceptance_always.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'browser_operator_non_preemptive',
                'summary' => 'MANDATORY for host WB-OP (Cursor ide-browser / Simple Browser / Glass and host-equivalent operator browsers): acceptance Browser work MUST run non-preemptively in the BACKGROUND by default—do NOT steal IDE/chat focus from the user. Cursor: omit `position` on `browser_navigate` (background navigation that preserves focus); FORBIDDEN defaulting to `position:"active"` or otherwise forcing the Browser panel/tab into the foreground while the user is working. Background ≠ skip: WB-OP remains required (browser_operator_self_test). Exception ONLY when the user explicitly asks to watch / focus the Browser (same spirit as e2e_playwright_headless_default). Complements browser_operator_self_test / browser_cache_disabled_on_open / browser_strip_automation_flags / browser_release_after_delivery.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'ui_feature_requires_e2e',
                'summary' => 'DEFAULT MANDATORY for work_kind=feature: plan MUST include (a) ≥1 chapter pathway acceptance type=e2e per chapter AND (b) plan-level e2e-plan-suite; Agent auto-runs Playwright/`php bin/w e2e:run`; closeout requires ALL e2e status=passed with strong evidence. SIMPLE EXEMPTION: plan_complexity=simple + e2e_skip_rationale≥24 (same criteria as host_plan_mode simple skip) may omit Playwright e2e ONLY IF local Browser WB-OP visual+logic PASS evidence is recorded (requirement_acceptance_always / browser_operator_self_test). curl/CDP/narrative clicks remain e2e_evidence_weak and also WB-weak. NEVER ask the user to test. non_feature (docs/gates/infra with no Web) is exempt from both e2e and WB. Complements plan_full_pathway_e2e_suite, forbid_user_manual_test_handoff, browser_operator_self_test, agent_self_verify_before_done.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'plan_full_pathway_e2e_suite',
                'summary' => 'MANDATORY for every non-simple engineering feature: (1) EVERY chapter/dev_task hard-binds its own type=e2e that covers that chapter’s FULL functional pathway—Agent writes and auto-runs Playwright before marking the chapter done; (2) AFTER all chapter e2e PASSes, run UNIFIED e2e-plan-suite. Simple features with recorded e2e_skip_rationale obey requirement_acceptance_always via Browser WB-OP instead. Forbid reporting “done” after partial chapters, skipping all verification, or asking humans to manually close test cases. Complements chapter_ut_rt_wb_dl, ui_feature_requires_e2e, plan_todo_evidence_closeout, acceptance_real_business_pathway.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'acceptance_real_business_pathway',
                'summary' => 'MANDATORY for work_kind=feature (engineering team / 监工 alike): claiming done REQUIRES ≥1 REAL business-pathway acceptance that creates or mutates durable domain evidence—e.g. a real order_uuid / display number, paid/unpaid status flip, persisted row, or equivalent artifact the user can look up—NOT shell-only / CTA-copy / empty-query smoke. FORBIDDEN as sole closeout evidence: (a) opening `/checkout/success?outcome=cancel` without order_uuid and only asserting button text; (b) opening `#orders` and only asserting “no fatal”; (c) template-string UT alone for a payment/order UX feature; (d) e2e that never exercises the frozen main UC steps (submit→cancel/fail→continue-pay→resume, etc.). Shell/smoke specs MAY exist as supplements but MUST NOT replace the real pathway. Closeout report MUST name the concrete artifact ids (order_uuid / transaction_no / …). Incomplete real pathway → only 「代码已改，真实通路验收未完成」—never claim feature done. Complements tester_tests_must_be_real, plan_full_pathway_e2e_suite, agent_self_verify_before_done, requirement_acceptance_always, forbid_user_manual_test_handoff, engineering_team_for_new_requirements.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'tester_tests_must_be_real',
                'summary' => 'MANDATORY for Team:测试: and any Agent/监工 self-verify: tests MUST be real—do NOT deceive yourself. FORBIDDEN circular pass: invent fake fixtures / hand-crafted JSON / fake Model arrays / stubbed SUT responses, then assert against that invented data (or only against rows you side-inserted bypassing the frozen main UC) and claim PASS. ALLOWED: exercise the real production pathway—formal Playwright runner (`php bin/w e2e:run`), host Browser WB-OP, real register/login (local_dev_test_accounts_self_serve), or fixtures that create durable artifacts THROUGH real services—and assert independently lookup-able evidence (order_uuid / DB / admin UI / display number). Closeout evidence MUST NOT exist only in the test-process memory. Complements acceptance_real_business_pathway, agent_self_verify_before_done, e2e_playwright_formal_runner_only, browser_operator_self_test, browser_strip_automation_flags.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'browser_strip_automation_flags',
                'summary' => 'MANDATORY for Team:测试: / Agent WB-OP and formal Playwright e2e on local/dev acceptance: BEFORE navigating or interacting with any page that may load captcha / reCAPTCHA / human-verification (and preferably on EVERY acceptance Browser open alongside browser_cache_disabled_on_open), STRIP automation-detection flags so the session looks like a real user browser—otherwise cloud captcha will block login/submit and the pathway is not a real test. REQUIRED actions (host-capable subset): (1) clear `navigator.webdriver` via CDP `Page.addScriptToEvaluateOnNewDocument` / Playwright `context.addInitScript` / equivalent so it is undefined/false BEFORE page scripts run; (2) Playwright formal runner MUST launch Chromium with `--disable-blink-features=AutomationControlled` and MUST NOT keep `--enable-automation` (ignoreDefaultArgs); (3) after open, optionally assert `navigator.webdriver` is falsy before captcha clicks. FORBIDDEN: treating “Human-machine verification failed / reCAPTCHA blocked automation” as an acceptable WB-OP pass or as excuse to skip real login/submit; FORBIDDEN leaving default Playwright/CDP automation markers that trip bot scoring. Scope is this repo’s local/dev acceptance against our own storefront captcha—not a general captcha-bypass exploit guide. Complements tester_tests_must_be_real, browser_operator_self_test, browser_cache_disabled_on_open, local_dev_test_accounts_self_serve, e2e_playwright_formal_runner_only.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'forbid_user_manual_test_handoff',
                'summary' => 'MANDATORY: Agent MUST NOT ask the user to test, verify, refresh-and-retry, click through acceptance, OR supply local/dev login credentials for any coding/engineering feature. Forbidden handoff phrases include 请你测试/请刷新后再试/请自行验证/帮我点一下确认/请提供测试账号/请给密码/you can verify/please log in. Local backend defaults to admin/admin; frontend accounts are self-created (local_dev_test_accounts_self_serve). The Agent runs UT/RT, required Playwright e2e when not simple-exempt, AND always required Browser WB-OP for Web touches, records evidence, then delivers. Incomplete acceptance → only report 「代码已改，验收未完成」/「代码已改，e2e 未通过」—never claim done and never hand the test-case loop or credential ask to humans. Obeys requirement_acceptance_always + ui_feature_requires_e2e + plan_full_pathway_e2e_suite + acceptance_real_business_pathway + agent_self_verify_before_done + local_dev_test_accounts_self_serve.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'e2e_playwright_headless_default',
                'summary' => 'MANDATORY for Agent Playwright e2e (`php bin/w e2e:run` / Playwright): default MUST be headless—no Chromium/UI popup. Prefer bare `e2e:run` (runner defaults headless + PLAYWRIGHT_HEADLESS=1) or explicit `--headless`. Do NOT pass `--headed`/`--ui` unless the user explicitly asks to watch the browser. Complements ui_feature_requires_e2e / agent_self_verify_before_done / forbid_user_manual_test_handoff. Host WB-OP Browser for page acceptance is separate and unaffected.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'e2e_playwright_formal_runner_only',
                'summary' => 'MANDATORY: Agent MUST launch Playwright ONLY through the formal runner so browser lifecycle is owned by the runner and cleaned up on exit. Allowed: `php bin/w e2e:run …` (preferred) or `npx playwright test …` with repo `tests/e2e/playwright.config.js` (or module-collected specs under that runner). FORBIDDEN: ad-hoc `node -e` / one-off scripts that call `chromium.launch` / `firefox.launch` / `webkit.launch`, backgrounded fire-and-forget Playwright probes, or leaving `chrome-headless-shell` orphans (audio/CPU leak). Need a new check → add/extend a `.spec.js` under module `Test/e2e` or `test/e2e` and run via the formal runner—do not improvise a disposable browser. Host WB-OP (Cursor ide-browser / Simple Browser) is separate and unaffected. Complements e2e_playwright_headless_default / ui_feature_requires_e2e / browser_release_after_delivery.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'browser_cache_disabled_on_open',
                'summary' => 'Every time AI opens or navigates an acceptance Browser for WB-OP/WB-VIS: MUST disable HTTP disk/memory cache for that session BEFORE trusting the page. Cursor ide-browser: CDP Network.enable then Network.setCacheDisabled({cacheDisabled:true}), then navigate (or Page.reload({ignoreCache:true})). If setCacheDisabled is denied by the host, fall back to ignoreCache reload for that load and note the degrade—never verify this turn’s CSS/JS/HTML against default browser cache. Clearing the whole browser profile cache is NOT required (often blocked).',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'browser_release_after_delivery',
                'summary' => 'After WB-OP (and optional WB-VIS), every user-facing feature/stage report MUST include 「交付地址」, then AI MUST immediately close every acceptance Browser tab/webview opened this turn (Cursor: unlock then browser_tabs close for Glass/Simple Browser/ide-browser; other hosts: quit/close the operator session). Do not leave idle Browser processes. Exception only when the user explicitly asks to keep tabs open. Pure non-UI work that never opened a Browser: N/A.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'cursor_debug_csp_developer_tooling',
                'summary' => 'MANDATORY for Cursor agent debug ingest (browser fetch to http://127.0.0.1:7277 or http://localhost:7277, including #region agent log): before relying on those debug records, ensure app/etc/env.php sets security.headers.csp_developer_tooling to a CSP fragment that allows connect-src http://127.0.0.1:7277 http://localhost:7277 (example: connect-src http://127.0.0.1:7277 http://localhost:7277). Framework SecurityHeaderPolicyService unions that Env key into response CSP only when DEV or DEBUG and the key is non-empty—never hardcode Cursor localhost into SecurityHeaderDefaults production CSP, never contribute via Extends Security/Csp app defaults, and never expect production (non-DEV and non-DEBUG) responses to include 7277. If CSP console blocks 7277 while debugging, fix the Env key first; do not weaken production CSP baselines.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/安全响应头策略.md',
            ],
            [
                'id' => 'feature_delivery_urls',
                'summary' => 'Every feature completion or stage handoff report MUST end with a 「交付地址」/Delivery URLs section listing every touched frontend/backend page (and API routes when applicable) as probe-verified http(s) Markdown links [label](url). Local default Host MUST be {project_hash}.test.weline.com (example http://p05113ef3.test.weline.com:9555/...); NEVER use *.weline.test as the primary acceptance Host when *.test.weline.com is available (even if /etc/hosts lists both); NEVER force 127.0.0.1 when *.test.weline.com exists; never omit the section; never host-private pseudo-protocols (e.g. command:simpleBrowser) as the primary link; mark N/A when no UI. After that section is written, obey browser_release_after_delivery.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md',
            ],
            [
                'id' => 'responsive_breakpoints',
                'summary' => 'Web/UI must design tablet≈768 and PC≥1024 (plus 375 when the surface is mobile-relevant) from the start; for visual storefront/admin layouts collect multi-breakpoint evidence when the host can capture screenshots (WB-VIS under module doc/evidence/).',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'image_explicit_width_height_css',
                'summary' => 'MANDATORY for any storefront/admin/content image work (including <w:file:image>, raw <img>, CMS/Theme file-image nodes): set explicit HTML width+height (or aspect_ratio / layout_width+layout_height that compile to them) for CLS intrinsic ratio, THEN pair with responsive CSS max-width:100%; height:auto (Theme foundation / .w-file-image). Prefer <w:file:image width height|aspect_ratio>; do not ship images without dimensions “because responsive CSS alone is enough”. Cover/object-fit cases may override height in a more specific rule but must still emit width/height for ratio. Aligns with Google CLS guidance.',
                'doc' => 'app/code/Weline/FileManager/doc/file-image-cls-尺寸与响应式.md',
            ],
            [
                'id' => 'media_reference_identity_protocol',
                'summary' => 'MANDATORY: Media occupancy identity follows MediaReferenceIdentity.v1. Build ONLY via w_scope(scope?, type, code, other?) (PHP) or window.w_scope (JS)—never hand-paste identity_path or synthesize scope~sku. scope: segments are storage_scope only; sku:/theme:/brand: are identity only. Unbind uses type+scope+code AND. resource.scope on resource_changed is OPTIONAL (auto from request/Ambient; CLI must pass). Swap/clear image = unbind refs only, never delete files; physical delete goes to trash. Common pick: file-manager tag calls w_scope; visual editor sets explicit identity on the tag (instance). Authoritative: FileManager media-reference-identity-protocol.md + skill media-reference-identity.',
                'doc' => 'app/code/Weline/FileManager/doc/media-reference-identity-protocol.md',
            ],
            [
                'id' => 'chapter_ut_rt_wb_dl',
                'summary' => 'Multi-chapter delivery (plan_full_pathway_e2e_suite): each chapter is one complete acceptable full-pathway e2e closed loop (Agent auto-runs Playwright for that chapter’s FE/BE logic) and requires UT, RT, WB (WB-OP host Browser operator path + WB-VIS when visual/screenshot-capable), and DL before the next chapter. After all chapters, run the unified plan e2e suite. Mark chapter progress done before opening the next chapter.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'section_weline_code',
                'summary' => 'Literal <section> and w:slot wrapper="section" require non-empty semantic weline-code; verify with frontend:check-section-code.',
                'doc' => 'app/code/Weline/Theme/doc/frontend-section-weline-code.md',
            ],
            [
                'id' => 'theme_layout_widget_owner',
                'summary' => 'MANDATORY widget placement (REQ-THEME-0036, 2026-09-21/22 user纠偏): (1) SAME module — layouts/partials may <w:widget>/fetch ONLY that module’s own widgets; if already inlined, FORBID also declaring the same module|code in JSON default_injections (layout XOR injection; delete the JSON; mark placement=layout). (2) CROSS module — FORBID layouts/partials from inlining or fetch-calling another module’s widgets; foreign widgets MUST enter ONLY via empty <w:slot> + the owning module’s JSON default_injections (application default injection). Theme layouts/partials may inline <w:widget> only for Weline_Theme-owned widgets. Never both JSON and layout tags for the same widget (double render). HARD presence (required_default_always_present_without_user_deleted): without manual uninstall user_deleted@{versionId}, chosen-path required defaults exist—JSON path bakes into solidified layout templates (slot exists ⇒ bake; missing defaults ⇒ solidification bug; plugin injection ⇒ rebake all themes’ involved layouts). Engineering team: staff 部件开发工程师 (widget_development) for widget/injection waves. Runtime Overlay soft-skips missing/duplicate fills (no storefront 500). Verify: php bin/w frontend:check-theme-layout-widgets (+ frontend:check-required-injection-sibling-fetch when applicable).',
                'doc' => 'app/code/Weline/Theme/doc/部件开发指南.md',
            ],
            [
                'id' => 'preview_storefront_delivery_parity',
                'summary' => 'MANDATORY: Theme editor / theme-preview / visual_editor / preview=1 / editor_mode / workspace-preview MUST execute the SAME business logic and delivery code paths as the live storefront — identical control flow for Hooks, widgets, floats, nested hooks, session-backed context, captcha/quick-add, auto-detect, and other visitor-facing behavior. FORBIDDEN: any preview-only or editor_mode-only early-return, branch, stub, or gate that discards, skips, quiets, or replaces storefront logic/code just because the request is a canvas/iframe (examples FORBIDDEN: CustomerService body-end preview return; Checkout delivery-context skipping quick-add Hook / auto-detect; StoreMusic Hook preview skip). FORBIDDEN: dual implementations where preview uses a stripped path and publish uses the real path. Layout-aware Hook deferral is allowed ONLY when the same yield condition runs on publish AND preview. Pipeline hygiene (skip a throwaway SSR whose HTML cannot keep nested w:slot markers) is OK only if the real fill pass still runs the full storefront logic. Cache bypass and editor diagnostic markers that ADD chrome without removing storefront logic are OK. Missing domain identity (e.g. no PDP product on a layout canvas) may use empty/demo payload WITHOUT skipping the widget/Hook render path itself. Identity authority is separate (see preview-and-runtime-modes.md): visual editor = request params; version live preview = preview-token deserialize; formal storefront = RequestContext/Scope — do not confuse identity sources with delivery parity.',
                'doc' => 'app/code/Weline/Theme/doc/preview-and-runtime-modes.md',
            ],
            [
                'id' => 'taglib_no_literal_at_static_in_callback',
                'summary' => 'Taglib callback return HTML must not contain literal @static(...); resolve via Template::fetchTagSource / Local::resolveModuleStaticUrl.',
                'doc' => 'app/code/Weline/Taglib/doc/如何自定义Tag.md',
            ],
            [
                'id' => 'module_i18n_csv_collect',
                'summary' => 'Every module keeps zh_Hans_CN.csv and en_US.csv with aligned source keys; en_US translate column MUST be real English (never empty and never leave Chinese source as the en value); zh_Hans_CN translate column MUST be Simplified Chinese identity (never put English into the zh translate column). After CSV/string changes run php bin/w i18n:collect — claim translation done only after collect + locale spot-check. Ties to active_locale_must_show_target_language: placeholder Chinese in the en_US translate column OR English left in zh_Hans_CN translate column is a hard delivery failure.',
                'doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            ],
            [
                'id' => 'frontend_ui_requires_zh_en_csv',
                'summary' => 'MANDATORY frontend development convention for ALL 前端/UI/原型/主题开发工程师 seats and Agents: development language defaults to Simplified Chinese. Whenever adding/changing user-visible storefront/admin UI strings: (1) keep Chinese sources in templates/menus/ACL/__()/@lang/<lang>—Chinese-in-source is CORRECT; FORBID English as default source; (2) bilingual display is via module i18n CSV (NOT CSS)—write BOTH i18n/zh_Hans_CN.csv (Chinese identity translate) AND i18n/en_US.csv (real English translate) for every new/changed source in the same turn; FORBID missing CSV rows, English-in-zh column, or Chinese-in-en column; (3) run php bin/w i18n:collect; (4) spot-check the active admin/storefront locale. BEFORE claiming UI done. Engineering team 前端 seat MUST load template_i18n + module_i18n_csv with Theme skills (frontend_development surface). Complements module_i18n_chinese_source_default, module_i18n_csv_collect, backend_admin_ui_requires_frontend_theme_skills.',
                'doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            ],
            [
                'id' => 'module_i18n_chinese_source_default',
                'summary' => 'MANDATORY for ALL modules (admin + storefront) and especially frontend developers: user-visible source phrases MUST be Simplified Chinese by default — templates (<lang>/@lang), PHP __(), menu.xml title, ACL labels, and other i18n sources. Chinese-in-source is CORRECT and expected (this IS the frontend development language). FORBIDDEN: English (or other non-Chinese) as the default source string in templates/menus (that causes zh locale to show English and mixes CSV columns). Bilingual support = Chinese source in code + zh_Hans_CN identity + en_US real English translate column via CSV (see module_i18n_csv_collect / frontend_ui_requires_zh_en_csv)—NOT by rewriting templates to English and NOT via CSS. Display language is separate — when the active/default locale is not Chinese, obey active_locale_must_show_target_language. Proper nouns/tech tokens (ID, HTTP, Cron, SQL) may stay as-is when they are not prose UI copy. When fixing a module that used English sources, convert code sources to Chinese and rewrite CSV keys to Chinese — do not only patch zh CSV with English→Chinese while leaving English in templates.',
                'doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            ],
            [
                'id' => 'active_locale_must_show_target_language',
                'summary' => 'MANDATORY (rule-level, pairs with module_i18n_chinese_source_default): Source phrases in code/templates remain Simplified Chinese. The rendered UI MUST follow the ACTIVE request locale and/or website DEFAULT language — NOT the source language. If the website default language is en_US (or the visitor is on en_US / any non-Chinese locale), user-visible copy MUST be that locale’s translation (module en_US.csv translate column and/or system dictionary for other locales). FORBIDDEN: leaving Chinese on screen because en_US (or other target locale) still uses Chinese source as the translate placeholder; treating “template is Chinese” as a reason to show Chinese under a non-Chinese locale; rewriting template sources to English to “fix” display. Fix path: keep Chinese sources → write real target-language translations → php bin/w i18n:collect → spot-check the active/default locale page. Applies to storefront and admin whenever locale ≠ zh_Hans_CN.',
                'doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            ],
            [
                'id' => 'user_mentions_translation_all_default_website_locales',
                'summary' => 'MANDATORY when the user mentions 翻译 / translate / translation as a work request (including a screenshot of leftover source-language chrome): resolve target locales from the DEFAULT website selected languages — WebsiteLanguage::getWebsiteLanguageCodes(Website::ID_DEFAULT) on the LOCAL database (website_id=0; keep zh_Hans_CN+en_US baseline). Translate the asked surface (and any attached untranslated UI copy) into EVERY selected locale with real target-language copy; never leave Chinese source as the translation; never stop at en_US only. Module i18n CSV may ONLY store zh_Hans_CN.csv and en_US.csv — never create or write other locale CSV files. Non-baseline locales MUST land in the system dictionary (LocaleDictionary / ai:import-csv / AI translate + publishLocale), not module CSV. After zh/en CSV changes run php bin/w i18n:collect. The user may explicitly narrow locales. Do not start Ollama unless the user asked. Query locales locally first (runtime_status_query_local_first).',
                'doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            ],
            [
                'id' => 'no_generated_no_routes_xml',
                'summary' => 'Never edit generated/; never use routes.xml (routing is auto-discovered); end ORM chains with fetch()/fetchArray().',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
            ],
            [
                'id' => 'no_native_js_dialogs',
                'summary' => 'MANDATORY for all storefront/admin JS UX: forbid native window.alert / window.confirm / window.prompt (and bare alert/confirm/prompt). Use Theme UI feedback — Weline.UI.toast for notices, Weline.UI.dialog.confirm / Theme.Notice for confirmations. Contract tests SHOULD assert absence of native dialogs on touched templates/scripts. Do not bury this under unrelated generated/routes rules.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
            ],
            [
                'id' => 'weline_api_not_raw_fetch',
                'summary' => 'MANDATORY: Browser business I/O for first-party admin/storefront MUST use BinQuery as the default and only transport — `Weline.Api.resource|graph|stream → worker/query-bin`. Forbid native fetch/XMLHttpRequest/$.ajax/axios, hand-written /api/framework/query-bin, and business REST URLs. Forbid treating BinQuery as an HTTP-controller fallback (HTTP-first then catch→Api). If Weline.Api is unavailable, degrade locally (empty/offline)—do not invent a second native request channel.',
                'doc' => 'app/code/Weline/Frontend/doc/Weline.Api使用指南.md',
            ],
            [
                'id' => 'no_php_tags_in_comments',
                'summary' => 'Never put <?= or <?php (or short <? open tags) inside comments (//, #, /* */, /** */, HTML <!-- -->). This targets PHP open/close tags in comments only—not ordinary commented-out statements like // $x = 1;. File headers must use literal text/dates (forbid leftover generator templates such as date short-echo in block comments); delete dead template blocks instead of wrapping <?= inside <?php /* ?>...*/.',
                'doc' => self::AUTHORITATIVE_DOC,
            ],
            [
                'id' => 'chinese_comments_friendly_style',
                'summary' => 'New/changed explanatory comments (//, #, /* */, /** */, HTML <!-- -->) and PHPDoc summaries default to Simplified Chinese; keep existing English comments unless the edit rewrites them. Prefer friendly readable code: match surrounding style, clear names, flat control flow, no clever abstraction or industrial boilerplate; add short Chinese comments only where intent is not obvious. Still obey no_php_tags_in_comments.',
                'doc' => 'app/code/Weline/Framework/doc/3-开发/开发标准与验收.md',
            ],
        ];
    }

    /**
     * Index-only hard_rules for workflow_contract.v1 — no summary prose dump.
     * Full rule bodies live in prepare_project.agent_guidance.hard_constraints (package()).
     *
     * @return array<string, mixed>
     */
    public static function workflowHardRulesRef(): array
    {
        $ruleIds = [];
        foreach (self::rules() as $rule) {
            $id = trim((string) ($rule['id'] ?? ''));
            if ($id !== '') {
                $ruleIds[] = $id;
            }
        }
        $operationalIds = [];
        foreach (self::mcpOperationalRules() as $rule) {
            $id = trim((string) ($rule['id'] ?? ''));
            if ($id !== '') {
                $operationalIds[] = $id;
            }
        }

        return [
            'schema' => 'hard-rules-ref.v1',
            'must_obey' => true,
            'source' => 'prepare_project.agent_guidance.hard_constraints',
            'authoritative_doc' => self::AUTHORITATIVE_DOC,
            'rule_ids' => $ruleIds,
            'operational_ids' => $operationalIds,
            'detail_via' => [
                'prepare_project.agent_guidance.hard_constraints.rules',
                'prepare_project.agent_guidance.hard_constraints.mcp_operational',
                self::AUTHORITATIVE_DOC,
            ],
            'note' => 'workflow_contract ships rule ids only; read full summaries from prepare_project.agent_guidance.hard_constraints (already in session) or the authoritative doc. Surfaces still add task-scoped norms.',
        ];
    }

    /**
     * Flat English hard_rules list (compatibility / contract tests).
     * Prefer workflowHardRulesRef() in workflow_contract payloads.
     *
     * @return list<string>
     */
    public static function workflowHardRules(): array
    {
        $rules = [];
        foreach (self::rules() as $rule) {
            $rules[] = $rule['summary'];
        }
        $rules[] = 'Incomplete verification must be reported explicitly.';
        $rules[] = 'Never claim a multi-todo plan done without per-todo evidence; partial work must list unfinished items in the user report and doc/开发日志.md.';
        $rules[] = 'After implement the Agent MUST self-verify by acceptance tier (UT/RT/WB) before claiming done (agent_self_verify_before_done); never mark acceptance passed/skipped/na without non-empty evidence; incomplete verification → only report 代码已改，验收未完成.';
        $rules[] = 'Prefer TDD: write failing tests first (red), implement to green, refactor; done only after real test commands PASS with evidence—never claim done on code-only.';
        $rules[] = 'Repository doc/ is authoritative; root docs/ is legacy.';
        $rules[] = 'Never put <?php or <?= in Weline Taglib / w:* tag attribute values; use @lang, Hook, or body-level HTML attributes with htmlspecialchars.';
        $rules[] = 'Never put <?= or <?php inside comments (//, #, /* */, /** */, HTML <!-- -->); this forbids PHP open tags in comments only—not ordinary commented-out statements like // $x = 1;.';
        $rules[] = 'New/changed explanatory comments and PHPDoc summaries default to Simplified Chinese; prefer friendly readable code that matches surrounding style (clear names, flat flow, no clever over-abstraction); still obey no_php_tags_in_comments.';
        $rules[] = 'Never put unquoted commas inside @lang()/@lang{} source text; commas are argument separators and produce ParseError (use quoted @lang(\'…\') or <lang>…</lang>).';
        $rules[] = 'Theme/widget .phtml must not use inline <script> blocks with <?= or server-side PHP; register external modules in weline.modules.js and load with Weline.declare / data-weline-load / data-weline-declare, plus data-* / data-js-ns / data-uid on the widget root.';
        $rules[] = 'Taglib callback()/runtime_callback() return HTML must not contain literal @static(...); compile only resolves @static in .phtml source AST—callback strings are baked verbatim and browsers 404 on .../@static(Module::css/foo.css). Resolve via Template::fetchTagSource(dir_type_STATICS, Module::path) (see I18n\\Taglib\\Local::resolveModuleStaticUrl) or emit PHP echo in callback output.';
        $rules[] = 'If inline <style>/<script> must remain in layout or partial templates, mark data-no-extract="true"; prefer external assets for widgets injected into data-wslot slots.';
        $rules[] = 'When editing Theme/frontend widgets, layouts, partials, or .phtml, follow the frontend_development surface (Theme开发总指南) and MCP skill weline-theme-development via get_skill; if a host UI/frontend-design skill is also active, theme tokens still win—do not invent colors or spacing.';
        $rules[] = 'Whenever the task mentions CSS or 主题/theme, load UI skill frontend-design + prototype skill prototype + theme skill weline-theme-development (MCP get_skill) before writing styles; theme tokens win (css_or_theme_requires_ui_prototype_theme_skills).';
        $rules[] = 'At requirement start classify work_kind, then run Spec Kit/Kiro-style clarify + use-case spec (requirement_clarify_use_case_spec): feature MUST persist doc/开发/spec/{slug}.md with EARS + UC and status clarified|ready-for-plan before architecture/code; non_feature may skip with rationale≥24; load get_skill(requirement_clarify_use_case) or command 需求澄清与用例规格.';
        $rules[] = 'Analyze FE/BE scope at requirement start (requirement_fe_be_scope_analysis): record fe_be_scope=frontend|backend|both|na with concrete bullets; do not ship one side when both are needed.';
        $rules[] = 'When entity/field change signals fire (e.g. 给订单增加类型), plan.impact_surfaces is mandatory with cross-layer inventory (schema_model/service_api/admin_ui/storefront_ui/i18n/tests_e2e); reject empty-only implicit_requirements; align in_scope layers to dev_tasks (requirement_cross_layer_impact_gate). Prefer framework_candidates.impact_candidates.';
        $rules[] = 'During planning (after clarify when applicable, before business code): enable host Plan Mode (host_plan_mode_for_planning)—on Cursor SwitchMode target_mode_id=plan—UNLESS plan_complexity=simple with plan_skip_rationale≥24 (single module, ≤~2h, no new extension invention); simple skip does NOT waive acceptance. Stay in Plan Mode until user approves implement then switch to agent; if host lacks Plan Mode, plan read-only and record unavailable + rationale≥24; forbid production PHP/phtml/CSS edits while still planning.';
        $rules[] = 'Host Codex CLI delegation (host_delegate_explore_plan_review_to_codex_cli): when Codex CLI is available on non-Codex hosts (Cursor), delegate explore + detailed 背景/方案/细节 plan (`codex exec` read-only) and post-edit review (`codex review --uncommitted`) to Codex CLI default/latest model—FORBID `-m/--model`; independent of knowledge.codex.enabled; BEFORE each delegated `codex` launch MUST chat-announce `Codex` + `正在工作` (phase named)—FORBID silent delegation; after finish announce `Codex 已完成`; on fallback announce `Codex 不可用，已回退宿主` + reason; Cursor implements code only from that plan; Plan Mode is container/approval only (no second vague plan); native Codex host MUST NOT spawn nested codex; content_ops/chat exempt; on CLI failure fallback to host Plan Mode and record reason. See agent_guidance.host_codex_delegation.user_visible_status.';
        $rules[] = 'Parent picks the mode (engineering_team_for_new_requirements): simple plan_skip → 监工 and every user-facing line starts with 监工: (no Team:); complex → parent=项目经理 only (Team:项目经理:), one_seat_one_agent (real subagent per seat; forbid parent roleplay), peer_talk_via_channel (channel/{thread}.md + resume peers; forbid forged multi-seat dialogue); relay other Team:{席位}: e.g. Team:架构师: only from real subagent reports. Content-ops (产品优化/新建文章) use neither prefix. Team: requirement_session_dashboard (doc/开发/session/{slug}.md; PM-only bookkeeping) + pm_plan_lifecycle (gaps→staff→monitor→notify_pm DoD check→test→PM recheck→close plan_id; deps-parallel OK); framework_first; seat_skill_mirrors (paste per-seat skill mirror; seat get_skill/Read scoped docs before code); dual_track_all triggered seats (施工+合规复审; fail→rework); component_reuse_or_negotiate (原型∥UI ±主题开发工程师 → component-negotiate.md); team_flow_on_contracts—对齐冻结会 (测试主持) freezes executable UC+contracts.md+deps.md before build; wake-on-deps concurrency; forbid post-dev main-path use-case design; acceptance executes frozen UC only; surfaces.md+components.md+contracts.md+deps.md; ui_prototype_gate_before_test: UI+原型 review (reject→resume development) BEFORE Tester e2e/WB; both pass then test; Tester pass→PM 汇审→then report (acceptance-ui.md / acceptance-prototype.md)—e2e green does not waive; parallel only on non-overlapping frozen tracks; 停工 and wait on architecture contradictions; persist doc/开发/team/{slug}/ + session/{slug}.md. Framework seats include API, 支付开发工程师, and 数据分析 (Visitor pixel—not framework Event). Subagent closed is not delivery. Local/dev: NEVER ask user for credentials—backend admin/admin, frontend self-create (local_dev_test_accounts_self_serve).';
        $rules[] = 'Requirement session dashboard (requirement_session_dashboard): every executable engineering ask MUST keep owning-module doc/开发/session/{slug}.md (same slug as spec; template requirement-session.md)—phase/plan_ids/gaps/review index/delivery-notify log; ONLY PM/监工 edits SESSION; no SESSION → forbid construction; gaps non-empty → forbid claiming done.';
        $rules[] = 'PM plan lifecycle (pm_plan_lifecycle): PM is sole SESSION bookkeeper—review gaps→staff→monitor→every seat delivery notify_pm→DoD check (not code-review substitute)→update SESSION→wake deps (parallel OK)→Tester notify→PM recheck→close plan_id; findings open child plan_ids until test+recheck; rework recorded on SESSION; report only when all plan_ids closed + 汇审 pass.';
        $rules[] = 'REST/BinQuery API (api_rest_in_owning_module): staff Team:API: for Rest OR QueryProvider/BinQuery waves; get_skill(api_sdk_development|weline-api-sdk); Read API接口开发规范 + BinQuery Provider开发指南. Shared Query core: reusable I/O in QueryProvider; thin REST default = #[Acl]+w_query; shells must not reimplement business. Attribute BinQueryOperation DEFAULT DENY (external=false, frontend=false)—opt-in exposure + explicit auth. Rest only in owning Vendor_Module. Per-entry gates: REST Acl; query-bin auth/backend_acl; /bin/query external+API Key scope; CDN public only read+external+cdn+visibility=public—forbid cross-entry bypass. First-party browser I/O = BinQuery (not hand-rolled REST). After Provider edits: framework:compile + query:help. Update owning-module API docs same change set.';
        $rules[] = 'Payment domain (payment_engineer_for_payment_work): staff Team:支付开发工程师: for Weline_Payment shell/Provider docking/refund/webhook/reconcile/Payable money-path; get_skill(payment_development|weline-payment-development); Read 支付开发.md + payment-shell.md + provider-development.md. Obey shell_provider_business_isomorph; unified URLs+shell_token; PCI/CSP/encrypted secrets. HARD closed-loop: after code edits MUST wake Team:测试: for real Browser WB-OP full pathway of each touched method (e.g. PayPal→full PayPal flow); review pass only with Tester Browser pass + transaction_no/order_uuid—forbid code-only or unit/curl as sole closeout; forbid backend-only skip of payment engineer.';
        $rules[] = 'Widget static assets (widget_static_assets_bake_to_head): declare layout-source/source (or @widget.layout_source/source); bake to page/chrome-assets sidecar; layout early head, source-postion/source-position choose head(default)/body(end-body)/footer with footer fallback to body end; layout wins URL dedupe, source positions head > footer > body; forbid ALL inline CSS/executable JS including style= and on*=; Read 部件静态资源固化规范.md.';
        $rules[] = 'Widget domain (widget_engineer_for_widget_work): staff Team:部件开发工程师: for widgets/default_injections/placement/slot XOR; get_skill(widget_development|weline-widget-development); Read 部件开发指南.md; obey theme_layout_widget_owner—forbid frontend/主题开发工程师 skipping foreign injections.';
        $rules[] = 'Theme domain (theme_engineer_for_theme_work): staff Team:主题开发工程师: for Theme Token/layout壳/预览三态/app/design/Weline_Theme-owned shells; declare work_mode before edits; get_skill(theme_development|frontend_development|weline-theme-development); Read 主题开发.md + Theme开发总指南.md; forbid frontend/UI-only skip of theme engineer; defer foreign injections to 部件开发工程师.';
        $rules[] = 'Required defaults always present (required_default_always_present_without_user_deleted): without manual uninstall user_deleted@{versionId}, required JSON default_injections and layout-tag inlines MUST exist—JSON path bakes into solidified layout templates (slot exists ⇒ bake; independent of theme/version; no template ⇒ runtime solidify active theme; plugin injection change ⇒ rebake involved layouts under all themes; missing defaults ⇒ solidification bug). Developers MUST memorize.';
        $rules[] = 'Theme seat integrity over peer requests (theme_seat_integrity_over_peer_requests): 主题开发工程师 bottom line (Theme modules work correctly) OUTRANKS peer/PM performance·simplify·optimize pressure; FORBID stripping chrome shells or dropping required defaults without user_deleted; WITHOUT a designed scheme that solves the problem AND preserves integrity → MAY/MUST 驳回 (reject); conflict → refuse+escalate; 性能检查工程师 FORBID strip-shell prescriptions.';
        $rules[] = 'theme_design_must_not_override_core_runtime_assets: app/design MUST NOT same-key override theme.css/theme.js; brand via colors/variables/独立 CSS.';
        $rules[] = 'Commerce advisor (ecommerce_advisor_for_commerce): on Product/Catalog/Cart/Checkout/Order/Payment-policy/Shipping/站店渠/ops-design complex team MUST staff Team:电商顾问: (ops planner—no code); domain decision「要开发什么」→ escalate PM same-turn tech discussion; attend 立项/对齐冻结/技术方案会; resolve site-supported countries then WebSearch per country before each stance; ops WebSearch before domain decisions; cite supported_countries; storefront compliance checklist includes policy pages/top-bar/FAQ Hub·entity/Cookie chrome; user-visible-string remediations MUST suggest 翻译工程师; HARD ops acceptance: MUST验收监控 storefront (ops_acceptance); FORBID 汇审 done without ops pass; fail layout/汉风商城感/主图 → escalate with clear image/layout specs for PM to staff; merges former 合规 seat; content-ops execution exempt.';
        $rules[] = 'Performance engineer (performance_engineer_for_design_and_review): on hot-path/cache/list/catalog/search/N+1/slow-request complex team MUST staff Team:性能检查工程师:; MUST check performance; know framework structure + business characteristics; review cache-design compliance; jointly customize optimization directions with Team:架构师:; after findings MUST immediately escalate wake Team:项目经理: (@项目经理：请立刻组队解决)—NO Issue list; FORBID private rework sequencing; get_skill(performance_check); Read 性能检查.md + 统一缓存范围与性能优化.md + 扩展点选型.md; evidence-backed design+post-dev review; content-ops exempt.';
        $rules[] = 'Prompt engineer (prompt_engineer_for_skill_prompt_work): on skill-ref / prompt-skeleton / seat_skill_mirrors / MCP skill·surface wording / duplicate-description compression (or user 提示词优化) MUST staff Team:提示词优化工程师:; get_skill(prompt_optimization); Read 提示词优化.md + 工程团队.md + AI硬规则索引.md; ONLY compress after duplication evidence (≥2 same-meaning places); FORBID omitting original meaning; FORBID inventing new rules; refs=pointers only; one authority for duplicates; semantic review must not weaken hard rules; content-ops exempt.';
        $rules[] = 'Translation engineer (translation_engineer_for_i18n_work): on new/changed user-visible copy / i18n in_scope / locale leak / interface·flow translation MUST staff Team:翻译工程师: (alias i18n); get_skill(translation_engineer)+template_i18n+module_i18n_csv; Read 翻译工程师.md + 模块翻译CSV规范.md; resolve default-website language_codes; collect→compare→zh/en module CSV + other locales system dictionary/entity→collect→spot-check ≥1 non-zh/en; module CSV zh+en FORMAT BOUNDARY only—FORBID non-zh/en module CSV and FORBID「其它语种默认不做」; FORBID content-ops product 翻译优化 as this seat; content-ops exempt.';
        $rules[] = 'Local/dev acceptance accounts (local_dev_test_accounts_self_serve): backend default admin/admin; frontend create e2e.customer@weline.local / E2eTest!234 if missing. FORBID asking the user for passwords or “please log in”. Production out of scope.';
        $rules[] = 'Findings wake PM (findings_wake_pm): specialty seats that find problems MUST immediately escalate to 项目经理 (@项目经理：请立刻组队解决 + notify_pm)—NO Issue task list; PM same-turn staffs seats via channel+resume, opens SESSION plan_id, and drives resolution—FORBID backlog lists or user-only essays without escalate.';
        $rules[] = 'Requirement issuer owns acceptance (requirement_issuer_owns_acceptance): after escalate/dev_ask the issuing seat MUST stay waiting_acceptance (FORBID hands-off closed); PM MUST resume issuer on every progress milestone with readable progress + @发起席：请验收进度; issuer MUST Read SESSION/PM report and write issuer_acceptance=pass|fail; FORBID 汇审/close plan_id without issuer_acceptance=pass.';
        $rules[] = 'UI/prototype gate before test (ui_prototype_gate_before_test): UI in_scope → after specialty review, UI+原型 review development with reject authority (acceptance-ui/acceptance-prototype); fail → PM resumes development subagent → re-review; FORBID Tester e2e/WB execution until both pass; Tester pass → PM 汇审 → only then report to user; e2e green does not waive.';
        $rules[] = 'Plan body focus only (plan_content_focus_only): write 背景 (why/gap) + 方案 (what/approach) + 细节 (how/tasks/acceptance)—forbid unrelated essays, workflow dumps, parallel pitches, or decorative overviews that drift the topic.';
        $rules[] = 'EVERY coding/engineering ask MUST have real acceptance evidence before done (requirement_acceptance_always). Skipping Playwright e2e under simple classification still REQUIRES local Browser WB-OP for visual AND operator logic on any Web/UI touch (browser_operator_self_test)—curl/CDP alone are insufficient.';
        $rules[] = 'Feature closeout REQUIRES a real business pathway with durable artifacts (acceptance_real_business_pathway)—e.g. real order_uuid after continue-pay—NOT shell-only CTA/smoke pages without the frozen main UC. Incomplete → only 「代码已改，真实通路验收未完成」.';
        $rules[] = 'Tests MUST be real (tester_tests_must_be_real): FORBID inventing fake fixtures/JSON/Model arrays or stubbing the SUT then asserting that invented data to claim PASS; ALLOW only real pathway evidence (formal e2e/Browser/real services) that is independently lookup-able—do not deceive yourself.';
        $rules[] = 'Strip automation flags on acceptance Browser/e2e (browser_strip_automation_flags): before captcha/login/submit WB-OP or Playwright runs, clear navigator.webdriver (CDP Page.addScriptToEvaluateOnNewDocument / addInitScript) and launch Chromium without AutomationControlled/--enable-automation; FORBID treating reCAPTCHA “automation blocked” as WB-OP pass.';
        $rules[] = 'Host WB-OP MUST be non-preemptive (browser_operator_non_preemptive): run acceptance Browser in the background by default (Cursor: omit position on browser_navigate); FORBID position:"active" / stealing IDE focus unless the user explicitly asks to watch; background ≠ skip WB-OP.';
        $rules[] = 'At requirement start analyze current-environment implicit/hidden requirements into plan.implicit_requirements, set ui_skill_decision=participate|skip from that analysis, and classify work_kind=feature|non_feature (requirement_implicit_analysis_skill_decision + requirement_feature_kind_gate)—force participate on page/layout/CSS/theme/.phtml OR humanization/吐槽/审图 signals (ui_skill_surface_signal_gate); never wrongly skip visual work; never force prototype on pure backend.';
        $rules[] = 'When ui_skill_decision=participate or 审图/布局调整/不够人性化/被吐槽: skill_participation MUST include prototype + frontend-design + weline-theme-development and they MUST adjust UI/IA (not critique-only; theme tokens win; no invented palettes).';
        $rules[] = 'When ui_skill_decision=participate (or adding features onto an existing Web UI), screenshot/审图 the CURRENT page before designing placement; if the current UI is messy, redesign that surface with the feature (feature_add_requires_current_ui_review).';
        $rules[] = 'Keep feature Web UI + prototype simple: ALL page groupings use TOP tabs; one job per pane; secondary content uses click-to-expand cards (default collapsed); redesign non-compliant dense pages to tabs—never stack list+form+dictionary on one long page (feature_ui_keep_simple_top_tabs).';
        $rules[] = 'During verify/acceptance when ui_skill_decision=participate or visual UI surfaces changed, run 审图 and record type=shentu evidence with 审图/线稿/checklist signals (acceptance_phase_requires_shentu).';
        $rules[] = 'Before closeout write huishen_notes containing 汇审 covering requirements/implicit_requirements/ui_skill_decision/acceptance (and participate→prototype/UI/审图); missing 汇审 blocks closeout_allowed (closeout_requires_huishen).';
        $rules[] = 'Any image / <w:file:image> / file-image node MUST set HTML width+height (or aspect_ratio) for CLS, then CSS max-width:100%;height:auto (image_explicit_width_height_css).';
        $rules[] = 'Media identity MUST use w_scope / window.w_scope (media_reference_identity_protocol); never hand-paste paths; resource.scope optional except CLI.';
        $rules[] = 'Shell+Provider isomorphism (shell_provider_business_isomorph): Payment/Dropship vendor business logic belongs in Extends Provider only; shell Controllers orchestrate and must not reimplement a specific provider. Payment-domain implementation waves also staff Team:支付开发工程师: (payment_engineer_for_payment_work).';
        $rules[] = 'Non-simple work_kind=feature MUST plan and PASS chapter full-pathway type=e2e AND final e2e-plan-suite (`php bin/w e2e:run`); simple may skip Playwright with rationale but MUST still PASS local Browser WB-OP visual+logic; NEVER ask the user to test; NEVER claim done without acceptance evidence (ui_feature_requires_e2e + plan_full_pathway_e2e_suite + requirement_acceptance_always + forbid_user_manual_test_handoff + browser_operator_self_test).';
        $rules[] = 'Agent Playwright e2e MUST run headless by default (`php bin/w e2e:run` without `--headed`/`--ui`, or with `--headless`); do not pop Chromium/UI unless the user explicitly asks to watch (e2e_playwright_headless_default).';
        $rules[] = 'Agent MUST launch Playwright ONLY via formal runner (`php bin/w e2e:run` or `npx playwright test` with repo playwright.config)—FORBIDDEN ad-hoc `node -e` / `chromium.launch` probes or fire-and-forget headless orphans (e2e_playwright_formal_runner_only). Host WB-OP Browser is separate.';
        $rules[] = 'Theme editor / theme-preview / visual_editor / preview=1 / editor_mode / workspace-preview MUST execute the SAME business logic and delivery code paths as the live storefront (preview_storefront_delivery_parity). FORBIDDEN: any preview-only or editor_mode-only early-return/branch/stub that discards or skips storefront logic (Hooks, widgets, floats, nested hooks, quick-add, auto-detect, session context, etc.); dual stripped-preview vs real-publish paths are forbidden; layout-aware Hook deferral is OK only when the same yield runs on publish AND preview; pipeline hygiene for throwaway SSR is OK only if the real fill still runs full logic. Identity authority (theme_preview_runtime_three_modes / preview-and-runtime-modes.md): visual editor = request params; version live preview = token deserialize; formal = RequestContext/Scope.';
        $rules[] = 'Do not claim visual Web/UI done without WB-VIS evidence when the host can capture screenshots: record under module doc/evidence/ and reconcile module doc/原型设计.md when that file exists; WB-OP still required for interactive UI even when screenshots are N/A.';
        $rules[] = 'Judge engineering plans on architecture, decoupling, ecommerce compliance, prototype design, e2e completeness, plan size, and closed-loop rigor; each chapter hard-binds pathway e2e (feature chapters unique pathway e2e + separate e2e-plan-suite) unless simple-exempt with WB-OP; mark done only after evidence, then next chapter; closeout only after suite PASS or simple WB-OP PASS.';

        return $rules;
    }

    /**
     * MCP-runtime operational rules (not product Theme rules).
     *
     * @return list<array{id: string, summary: string}>
     */
    public static function mcpOperationalRules(): array
    {
        return [
            [
                'id' => 'mcp_call_scope',
                'summary' => 'MCP is the knowledge plane (skills index, code map, domain hard-rule delivery). Coding uses host-native editors—MCP has no repository write tools. Skip MCP for pure chat/identity Q&A. Skip MCP for content-ops skill turns (see content_ops_skills_skip_mcp). Exception: greeting (hi/你好/hello) or 提取技能 may list skills+commands via prepare_project / resolve_skill(list_all). For coding/engineering when MCP is attached or attachable: MANDATORY ensure (if needed) → prepare_project → READ and OBEY agent_guidance.hard_constraints BEFORE any edits; then use resolve_task_context / search_project_knowledge / get_skill as needed. If hard_constraints disappeared from the turn context (compaction/summary), RE-call prepare_project—do not invent rules from memory. If MCP cannot attach after ensure, fall back to host Read of AI硬规则索引.md—do not invent rules and do not pretend MCP was obeyed. Host AGENTS.md + MCP-generated .cursor/rules coldstart gate point to this; never hand-write .cursor/rules to "remember" guidance.',
            ],
            [
                'id' => 'content_ops_skills_skip_mcp',
                'summary' => 'MANDATORY SKIP-MCP class: content/commerce-ops skills are NOT coding/engineering cold-start. When the user ask is (or clearly routes to) 产品优化/商品优化/详情优化/商详优化/翻译优化/商品翻译/主图优化/规格图优化/修主图/新建文章/写博客/审查文章/文章可行性/精写文章/blog article/规格修复 (or host/repo skills ecommerce-product-optimize, ecommerce-detail-suite, ecommerce-product-image, ecommerce-product-i18n, weline-blog-article), Agent MUST NOT call prepare_project, resolve_skill, get_skill, search_project_knowledge, resolve_task_context, or otherwise load the MCP skill/index package. Authority is host Read of matching `dev/ai-command/**` + `app/code/*/doc/ai/skills/**/SKILL.md` (host Store mirrors are thin pointers only). Do not pull unrelated MCP skills or hard_constraints bodies “just in case”. If the SAME turn also asks framework Theme/PHP architecture coding beyond these scripted content pipelines, only that coding slice requires normal mcp_call_scope prepare_project.',
            ],
            [
                'id' => 'preserve_dirty_workspace',
                'summary' => 'CRITICAL / MANDATORY: Preserve every pre-existing tracked, staged, untracked, and ignored dirty change across MCP repair, reload, generation refresh, validation rollback, crash recovery, and ordinary host edits. Host Agent Shell is equally forbidden from git checkout -- path, git restore, git reset, git clean, git stash (or equivalents) to “clean” a workspace, align HEAD, or redo an edit—that wipes local work and is a severe ban. HARD dirty-load: BEFORE any Write/StrReplace/ApplyPatch, host-Read the CURRENT on-disk working-tree file; all edits MUST continue on that live dirty content (already-changed dirty zone). FORBIDDEN: basing patches on HEAD, index, conversation-stale buffers, other-session snapshots, agent-transcript excerpts, or any older baseline and writing them back—that causes cross-session overwrite of each other’s in-progress dirty files. Multi-agent/multi-chat MUST NOT clobber live dirty work with stale versions. Hash drift → fail-closed keep disk; never restore-from-old then overwrite. MCP child processes may only inspect Git; every Git mutation, config/helper injection, pager helper, and force/discard path is forbidden.',
            ],
            [
                'id' => 'host_editor_rules_mcp_generated_only',
                'summary' => 'MANDATORY: Engineering/product rules are owned only by MCP hard-constraints.v1 and authoritative repo docs (Ai/Framework/module doc/). Cursor/Codex/Claude/VS Code host editor rule files (.cursor/rules/*.mdc, .cursorrules, CLAUDE.md, .codex/*, .github/copilot-instructions.md, editor-private AGENTS forks, etc.) MUST NOT be hand-authored or directly edited by the Agent as the rule source—switching projects makes those files disappear or diverge. Host editor rule artifacts (including .cursor/rules/weline-mcp-coldstart.mdc and .cursor/hooks.json for learning collection) MUST be generated only by MCP ensure/guidance HostEditorRulesGenerator / HostCursorHooksGenerator; AGENTS.md remains pointer-only for MCP attach. Never treat editor-private rule files as authoritative over prepare_project hard_constraints.',
            ],
            [
                'id' => 'mcp_skills_fetch_from_mcp',
                'summary' => 'MANDATORY for coding/engineering skills: served by MCP mcp-skills.v1 (prepare_project.agent_guidance.mcp_skills). Discover with resolve_skill(task) and load bodies with get_skill(skill_id or host-shell alias). List all with resolve_skill(list_all=true) or 提取技能. EXCEPTION content_ops_skills_skip_mcp: for 产品优化/详情优化/翻译优化/主图优化/新建文章/规格修复 families, repo `doc/ai/skills/*/SKILL.md` + matching `dev/ai-command` ARE authoritative—host Read those paths; do NOT route through get_skill/prepare_project; host Store SKILL.md may be thin mirrors pointing at repo paths. Never revive knowledge.auto_generate_skills projections. Task document fragments for engineering still use resolve_task_context.',
            ],
            [
                'id' => 'runtime_status_query_local_first',
                'summary' => 'MANDATORY: Runtime/status/ops queries (cron, queue, AI/i18n scheduled translation progress, logs, DB counts, “is it still running”) DEFAULT to the LOCAL workspace database/processes. Do NOT SSH or query production/staging unless the user explicitly names 线上/生产/ssh weline/aiweline.com/预发 (or an explicit remote host). SSH MCP default profile=weline is ONLY the production host mapping when production SSH is already authorized—it is NOT a default query target and does NOT create a translation-special production rule. Prior chat history about production MUST NOT override this local-first default.',
            ],
            [
                'id' => 'greeting_lists_mcp_skills_and_commands',
                'summary' => 'MANDATORY: When the user greets with hi / 你好 / hello (and no coding ask), the Agent MUST reply with the MCP skill catalog and command list from prepare_project.agent_guidance.mcp_skills.greeting (or resolve_skill list_all / task=提取技能): list workflow skills, module-doc skills, and dev/ai-command instructions clearly. Do not invent skills; do not start coding edits on greeting-only turns.',
            ],
            [
                'id' => 'session_learning_knowledge_conflict_gate',
                'summary' => 'MANDATORY session-learning knowledge gate: (1) Classify user asks—durable framework/process constraints that should bind future work are KNOWLEDGE (learning intent); one-off delivery tasks are REQUIREMENT. A requirement that changes framework architecture/policy MAY ALSO mint a knowledge candidate after it lands. (2) Cursor/Codex learning hooks feed Learning SQLite; Agent MUST treat prepare_project.agent_guidance.learning_conflicts and resolve_task_context rules from validated_session_learning as binding project memory. (3) Before edits that would contradict validated/contested learning or open contradictions: STOP, report conflict (old rule vs new ask/change, options≥2, recommendation), and wait for explicit user decision—do not silently override. (4) When the user confirms a knowledge rule, record evidence/outcome via learningctl when available; when they supersede, mark contested/revised and proceed only after decision. Content-ops skill turns exempt.',
            ],
        ];
    }
}
