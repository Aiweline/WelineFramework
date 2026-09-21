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
        return 'MCP ROLE (knowledge plane + mandatory hard-rule gate): Weline MCP indexes skills, code maps, and domain hard rules. '
            . 'Coding/editing uses host-native tools (Read/Write/ApplyPatch/Shell)—MCP does not provide repository write tools. '
            . 'CALL SCOPE: engineering/coding when MCP attachable → ensure(if needed) → prepare_project → READ/OBEY '
            . 'agent_guidance.hard_constraints BEFORE edits; then optionally resolve_task_context / resolve_skill / get_skill. '
            . 'Skip MCP for chat/content-ops (content_ops_skills_skip_mcp)—Read ai-command+doc/ai/skills; no prepare. '
            . 'Obey hard-constraints.v1 from agent_guidance or ' . self::AUTHORITATIVE_DOC . '. '
            . 'If MCP cannot attach: host Read ' . self::AUTHORITATIVE_DOC . '; do not invent rules. '
            . 'Scrutinize+architecture-first+decoupled only; prefer framework_candidates; report 耦合提示/需求纠偏. '
            . 'preserve_dirty_workspace: never git checkout/restore/clean/stash to wipe dirty work. '
            . 'LOCAL-FIRST runtime queries; production SSH only when user says 线上/生产/ssh weline/aiweline.com. '
            . 'i18n: source=简中; module CSV zh+en only; active locale shows target lang; 用户提翻译→默认站全语种进词典. '
            . 'Self-verify UT/RT/WB; feature e2e chapter+suite (formal headless runner); never ask user to test/credentials. '
            . 'Requirement start: work_kind + fe_be_scope + clarify/UC (EARS) → Plan Mode (背景+方案+细节) unless simple skip. '
            . 'Mode: simple→监工:; complex→engineering_team_for_new_requirements Team:项目经理: '
            . '+ one_seat_one_agent (real subagent/seat; forbid parent roleplay) '
            . '+ peer_talk_via_channel (channel+resume) + team_flow_on_contracts (对齐冻结 UC+contracts+deps→依赖唤醒; '
            . '禁开发完才补主路径用例). Load engineering_team / 工程团队.md. Content-ops exempt prefixes. '
            . 'Team: framework_first + dual_track_all + component_reuse_or_negotiate + UI/原型签收. '
            . 'ui_skill_decision; 审图参与时 prototype+frontend-design+weline-theme-development. '
            . 'requirement_acceptance_always; closeout 汇审; TDD; delivery URLs. '
            . 'After MCP use prefix Weline：; content[0] is the call receipt.';
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
                'summary' => 'MANDATORY: When using any host UI / frontend-design / aesthetic skill for Weline storefront or admin UI, MUST also load MCP skill weline-theme-development (get_skill) or surface frontend_development, plus Theme开发总指南.md and theme-css-variables-only.md before writing CSS/markup. Theme CSS tokens (--color-* / --weline-theme-* / spacing·radius·shadow) and Weline UI 2.0 classes win over generic UI-skill palettes. Forbid inventing private hex/rgb palettes, px spacing scales, radius/shadow kits, or parallel design tokens; UI skills may only guide composition, hierarchy, and copy within existing theme tokens. Host SKILL.md mirrors are optional and not authoritative over MCP. Also obey css_or_theme_requires_ui_prototype_theme_skills whenever the task mentions CSS or 主题/theme.',
                'doc' => 'app/code/Weline/Theme/doc/theme-css-variables-only.md',
            ],
            [
                'id' => 'css_or_theme_requires_ui_prototype_theme_skills',
                'summary' => 'MANDATORY: Whenever the user requirement or task mentions CSS or 主题/theme (including Theme styling, theme tokens, storefront/admin visual CSS), BEFORE writing or changing styles/theme markup the Agent MUST load and obey all three: (1) UI skill frontend-design, (2) prototype skill prototype, (3) theme skill weline-theme-development via MCP get_skill (surface frontend_development). Theme tokens and Weline UI 2.0 still win; UI/prototype skills must not invent palettes or bypass Theme. Skip only for pure non-visual work with no CSS/theme intent. Prevents theme development drift.',
                'doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
            ],
            [
                'id' => 'user_image_attachment_triggers_shentu',
                'summary' => 'MANDATORY: When the user message includes any image/screenshot attachment (paste, Browser capture, acceptance shot, chat media)—including admin/CMS/error pages and other product UI, not only storefront retail/B2B—OR when 审图/布局调整/不够人性化/被吐槽 UX complaints apply—the Agent MUST immediately execute MCP command 审图 (dev/ai-command/theme/审图.md): Read every attached image (when present), classify web_ui|frontend_candidate|non_frontend and error_shot|ui_shot. NON-ERROR DEFAULT: ui_shot means UI modification is required (checklist fails including human factors, aesthetic standards, and theme fit → fix to pass)—NOT critique-only, NOT prior-chat confirmation. JOINT PIPELINE (same turn): (1) extract structural wireframe/line sketch of visible layout, (2) prototype adjustments via prototype skill MUST change IA/placement, (3) humanization/aesthetics via frontend-design MUST adjust UI, (4) theme CSS/tokens via weline-theme-development (get_skill)—do not invent palettes. Prototype + frontend-design participation is MANDATORY on 审图—never skip them. error_shot prioritizes exception/root-cause fix while still keeping error UI readable. SILENT/SHOT-ONLY: image-only or arrows/? → UI+prototype audit of visible surfaces. Before E/F, verify host skills frontend-design and prototype; if missing, user-visible warning + self-install into Cursor Agent Store, then Read bodies—never pass E/F without them. Do NOT wait for 审图/审查图/UI 审图. Do NOT skip because another bug/task is open. Host weline-ui-shentu is thin reminder; authority is the command file + this rule (image_attachment_shentu_bundle). Also obey acceptance_phase_requires_shentu during verify.',
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
                'summary' => 'DEFAULT MANDATORY during planning (after clarify when applicable, BEFORE business code): enable host Plan Mode—Cursor SwitchMode target_mode_id=plan—through architecture_design + chapter plan until the user approves implement, then SwitchMode to agent. Forbid production PHP/phtml/CSS while still planning. SIMPLE SKIP allowed when plan_complexity=simple AND plan_skip_rationale≥24 chars AND all of: single owning module, no new extension-point invention, no multi-chapter plan, scope ≤~2h / one clear surface, no ambiguous FE+BE architecture choices. Simple skip still REQUIRES requirement_acceptance_always + FE/BE scope analysis; it does NOT skip acceptance or Browser WB-OP when Web is touched. If host has no Plan Mode: plan read-only and record host_plan_mode=unavailable + rationale≥24 (or use simple skip when eligible). Plan body MUST obey plan_content_focus_only. Complements requirement_clarify_use_case_spec, requirement_acceptance_always, architecture_first_for_requirements.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
            ],
            [
                'id' => 'engineering_team_for_new_requirements',
                'summary' => 'MANDATORY mode pick by the parent session itself—do not wait for the user to say 工程团队 (engineering_team_for_new_requirements). SIMPLE (plan_complexity=simple, plan_skip rationale≥24): 监工模式 only—one supervisor, no roster, no meeting; every user-facing line MUST start with 监工: and MUST NOT contain Team:. COMPLEX (engineering ask that is not simple): the parent itself chooses team mode and which seats to call this wave. ONE_SEAT_ONE_AGENT (hard): each staffed seat MUST be a real host subagent (Task/equivalent)—FORBID the parent role-playing other seats by swapping Team: prefixes. Parent may utter ONLY Team:项目经理:; other Team:{席位}: lines are allowed solely as verbatim relays of that seat subagent report (e.g. relay Team:架构师:). PEER_TALK_VIA_CHANNEL (hard): seats converse via doc/开发/team/{slug}/channel/{thread}.md + resume of the peer real subagent; PM is switchboard only—FORBID inventing multi-seat dialogue. Persist roster.md with seat→agent_id. Waiting peer → result=waiting_peer + peer_to + channel_msg. FORBID plain paragraphs, fullwidth colons, or [架构师] in team mode. Content-ops (content_ops_skills_skip_mcp: 产品优化/详情优化/翻译优化/主图优化/新建文章/规格修复) MUST NOT use either prefix and keep their own squads. Load dev/ai-command/ai/工程团队.md or get_skill(engineering_team|weline-engineering-team). FRAMEWORK FIRST: requirements/design/build/review map to framework mechanisms/components before business patches. DUAL TRACK ALL specialty seats: each triggered seat has 施工 + 合规复审; fail → rework, never enter acceptance dirty. FLOW (team_flow_on_contracts): after 立项会, run 对齐冻结会 (测试主持) to freeze executable UC + contracts.md + deps.md BEFORE tech-scheme finalization and construction—FORBID designing main-path use cases only after development finishes; acceptance wave EXECUTES frozen UC only (gaps → back to align-freeze). Construction is wake-on-deps concurrency: start only seats whose deps are satisfied; FORBID whole-team idle waiting at the finish line. Core roster: 项目经理(parent)+需求分析+领域探查+架构师+后端+前端+主题+UI+原型+测试+安全+文档; framework seats by trigger matrix (扩展点/事件/查询/Taglib/Hook/Provider/i18n/ACL/Setup/合规)—seats join by wave. UI in_scope MUST staff 原型+前端+主题+UI; freeze components.md; insufficient components → 原型∥UI (±主题) negotiate into component-negotiate.md before inventing. Persist surfaces.md + contracts.md + deps.md + meetings/align-freeze.md + doc/开发/team/{slug}/. Concurrency only when files/extension points do not overlap AND contracts+UC are frozen. Escalation: result=escalate then 专题会; if nobody can decide OR a major architecture contradiction, 停工汇报 and FORBID PHP/phtml/CSS until the user confirms. ACCEPTANCE SIGN-OFF (UI in_scope): e2e green does NOT waive—UI writes acceptance-ui.md and 原型 writes acceptance-prototype.md with substantive live-page verdicts; either fail forbids 汇审/delivery. Subagent closed is not delivery. LOCAL DEV TEST ACCOUNTS (hard, see local_dev_test_accounts_self_serve): backend default admin/admin; frontend self-create—NEVER ask the user for credentials. Complements requirement_clarify_use_case_spec, host_plan_mode_for_planning, closeout_requires_huishen, forbid_user_manual_test_handoff.',
                'doc' => 'dev/ai-command/ai/工程团队.md',
            ],
            [
                'id' => 'local_dev_test_accounts_self_serve',
                'summary' => 'MANDATORY for local/dev acceptance (UT/RT/WB-OP/Playwright e2e) on this repo: Agent/engineering team MUST NOT ask the user for login credentials or “please log in and verify”. Backend DEFAULT username/password = admin / admin (same as tests/e2e loginAsAdmin and PLAYWRIGHT_ADMIN_* fallbacks). Frontend: create a customer yourself (register UI or CLI); recommended e2e.customer@weline.local / E2eTest!234—create if missing. Env vars override when set; when unset MUST use these defaults. Production/线上 is out of scope (backup/auth rules apply). Failed default login → self-heal (reset password / fix captcha bootstrap / check WLS) or report 「验收未完成」with technical evidence—still never solicit passwords from the user. Complements forbid_user_manual_test_handoff, engineering_team_for_new_requirements, agent_self_verify_before_done.',
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
                'summary' => 'MANDATORY engineering gate: when goal, requirements, implicit_requirements, scope_paths, user complaints, or 审图 context show visual/UX signals—.phtml/.css paths, view/templates|hooks, theme color/variable CSS, OR keywords/phrases such as 改UI/调样式/页面布局/布局调整/信息架构/审图/线稿/原型调整/CSS/主题/不够人性化/不人性化/被吐槽/难用/太乱/太丑/体验差/humanization—REJECT ui_skill_decision=skip and REQUIRE participate with skill_participation including prototype + frontend-design + weline-theme-development plus type=shentu. Prototype and frontend-design MUST produce concrete layout/interaction adjustments (not critique-only). Negation phrases like 无布局重设计 do not count as signals. Pure backend/API/Provider/MCP-gate work without those signals may still skip with rationale. Complements requirement_implicit_analysis_skill_decision, user_image_attachment_triggers_shentu, css_or_theme_requires_ui_prototype_theme_skills.',
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
                'summary' => 'Storefront Theme/widget/layout JS modules MUST register in weline.modules.js and load only via Weline.declare / data-weline-load / data-weline-declare (or head module-declarations hook). After any create/update/move/delete of module registrations or their paths, MUST run `php bin/w resource:compile welineModules` (or full resource:compile) before closeout—source registry alone does not update runtime base. Forbid widget/layout <script src="@static(...js)"> or bare <js> tags for module-level scripts (Cart/Checkout/Wishlist/Customer and equivalents). Reuse existing layout slots; do not invent parallel mounts.',
                'doc' => 'app/code/Weline/Theme/doc/前端JS模块加载规范.md',
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
                'summary' => 'MANDATORY for any Web/UI/.phtml/CSS/page change OR any requirement whose fe_be_scope includes frontend: AI MUST use the host’s real local Browser (WB-OP) on agreed use cases covering BOTH (a) visual acceptance (layout/spacing/theme; WB-VIS screenshots when capable) AND (b) real operator logic (click/fill/navigate expected flows)—cache disabled on every open. Playwright e2e (`php bin/w e2e:run`) is ADDITIONAL for non-simple features (ui_feature_requires_e2e); skipping e2e under simple classification does NOT waive WB-OP. Unit tests, curl, and CDP Runtime.evaluate / one-off IDE probes MUST NOT substitute for WB-OP. If the host has no interactive Browser, report only “代码已改，WebUI 验收未完成”—do not ask the user to test. Obey browser_cache_disabled_on_open and requirement_acceptance_always.',
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
                'summary' => 'MANDATORY for work_kind=feature (engineering team / 监工 alike): claiming done REQUIRES ≥1 REAL business-pathway acceptance that creates or mutates durable domain evidence—e.g. a real order_uuid / display number, paid/unpaid status flip, persisted row, or equivalent artifact the user can look up—NOT shell-only / CTA-copy / empty-query smoke. FORBIDDEN as sole closeout evidence: (a) opening `/checkout/success?outcome=cancel` without order_uuid and only asserting button text; (b) opening `#orders` and only asserting “no fatal”; (c) template-string UT alone for a payment/order UX feature; (d) e2e that never exercises the frozen main UC steps (submit→cancel/fail→continue-pay→resume, etc.). Shell/smoke specs MAY exist as supplements but MUST NOT replace the real pathway. Closeout report MUST name the concrete artifact ids (order_uuid / transaction_no / …). Incomplete real pathway → only 「代码已改，真实通路验收未完成」—never claim feature done. Complements plan_full_pathway_e2e_suite, agent_self_verify_before_done, requirement_acceptance_always, forbid_user_manual_test_handoff, engineering_team_for_new_requirements.',
                'doc' => self::AUTHORITATIVE_WORKFLOW_DOC,
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
                'summary' => 'MANDATORY widget placement (REQ-THEME-0036, 2026-09-21 user纠偏): (1) SAME module — if that module already inlines the widget in layout/partial via <w:widget> / fetch(.../widgets/...), FORBID also declaring the same module|code in JSON default_injections (layout XOR injection; delete the JSON; mark placement=layout). (2) CROSS module — FORBID layouts/partials from inlining or fetch-calling another module’s widgets; foreign widgets MUST enter ONLY via empty <w:slot> + the owning module’s JSON default_injections. Theme layouts/partials may inline <w:widget> only for Weline_Theme-owned widgets. Runtime Overlay soft-skips missing/duplicate fills (no storefront 500). Verify: php bin/w frontend:check-theme-layout-widgets (+ frontend:check-required-injection-sibling-fetch when applicable).',
                'doc' => 'app/code/Weline/Theme/doc/开发/Theme开发总指南.md',
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
                'summary' => 'Every module keeps zh_Hans_CN.csv and en_US.csv with aligned source keys; en_US translate column MUST be real English (never empty and never leave Chinese source as the en value). After CSV/string changes run php bin/w i18n:collect — claim translation done only after collect + locale spot-check. Ties to active_locale_must_show_target_language: placeholder Chinese in the en_US translate column is a hard delivery failure when the active locale is English.',
                'doc' => 'app/code/Weline/I18n/doc/模块翻译CSV规范.md',
            ],
            [
                'id' => 'module_i18n_chinese_source_default',
                'summary' => 'MANDATORY for ALL modules (admin + storefront): user-visible source phrases MUST be Simplified Chinese by default — templates (<lang>/@lang), PHP __(), menu.xml title, ACL labels, and other i18n sources. Chinese-in-source is CORRECT and expected. FORBIDDEN: English (or other non-Chinese) as the default source string in templates/menus (that causes zh locale to show English and mixes CSV columns). Bilingual support = Chinese source in code + zh_Hans_CN identity + en_US real English translate column (see module_i18n_csv_collect). Display language is separate — when the active/default locale is not Chinese, obey active_locale_must_show_target_language (do NOT “fix” Chinese source by rewriting templates to English). Proper nouns/tech tokens (ID, HTTP, Cron, SQL) may stay as-is when they are not prose UI copy. When fixing a module that used English sources, convert code sources to Chinese and rewrite CSV keys to Chinese — do not only patch zh CSV with English→Chinese while leaving English in templates.',
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
        $rules[] = 'Parent picks the mode (engineering_team_for_new_requirements): simple plan_skip → 监工 and every user-facing line starts with 监工: (no Team:); complex → parent=项目经理 only (Team:项目经理:), one_seat_one_agent (real subagent per seat; forbid parent roleplay), peer_talk_via_channel (channel/{thread}.md + resume peers; forbid forged multi-seat dialogue); relay other Team:{席位}: e.g. Team:架构师: only from real subagent reports. Content-ops (产品优化/新建文章) use neither prefix. Team: framework_first; dual_track_all triggered seats (施工+合规复审; fail→rework); component_reuse_or_negotiate (原型∥UI ±主题 → component-negotiate.md); team_flow_on_contracts—对齐冻结会 (测试主持) freezes executable UC+contracts.md+deps.md before build; wake-on-deps concurrency; forbid post-dev main-path use-case design; acceptance executes frozen UC only; surfaces.md+components.md+contracts.md+deps.md; acceptance UI+原型 substantive signoff (acceptance-ui.md / acceptance-prototype.md)—e2e green does not waive; parallel only on non-overlapping frozen tracks; 停工 and wait on architecture contradictions; persist doc/开发/team/{slug}/. Subagent closed is not delivery. Local/dev: NEVER ask user for credentials—backend admin/admin, frontend self-create (local_dev_test_accounts_self_serve).';
        $rules[] = 'Local/dev acceptance accounts (local_dev_test_accounts_self_serve): backend default admin/admin; frontend create e2e.customer@weline.local / E2eTest!234 if missing. FORBID asking the user for passwords or “please log in”. Production out of scope.';
        $rules[] = 'Plan body focus only (plan_content_focus_only): write 背景 (why/gap) + 方案 (what/approach) + 细节 (how/tasks/acceptance)—forbid unrelated essays, workflow dumps, parallel pitches, or decorative overviews that drift the topic.';
        $rules[] = 'EVERY coding/engineering ask MUST have real acceptance evidence before done (requirement_acceptance_always). Skipping Playwright e2e under simple classification still REQUIRES local Browser WB-OP for visual AND operator logic on any Web/UI touch (browser_operator_self_test)—curl/CDP alone are insufficient.';
        $rules[] = 'Feature closeout REQUIRES a real business pathway with durable artifacts (acceptance_real_business_pathway)—e.g. real order_uuid after continue-pay—NOT shell-only CTA/smoke pages without the frozen main UC. Incomplete → only 「代码已改，真实通路验收未完成」.';
        $rules[] = 'At requirement start analyze current-environment implicit/hidden requirements into plan.implicit_requirements, set ui_skill_decision=participate|skip from that analysis, and classify work_kind=feature|non_feature (requirement_implicit_analysis_skill_decision + requirement_feature_kind_gate)—force participate on page/layout/CSS/theme/.phtml OR humanization/吐槽/审图 signals (ui_skill_surface_signal_gate); never wrongly skip visual work; never force prototype on pure backend.';
        $rules[] = 'When ui_skill_decision=participate or 审图/布局调整/不够人性化/被吐槽: skill_participation MUST include prototype + frontend-design + weline-theme-development and they MUST adjust UI/IA (not critique-only; theme tokens win; no invented palettes).';
        $rules[] = 'When ui_skill_decision=participate (or adding features onto an existing Web UI), screenshot/审图 the CURRENT page before designing placement; if the current UI is messy, redesign that surface with the feature (feature_add_requires_current_ui_review).';
        $rules[] = 'Keep feature Web UI + prototype simple: ALL page groupings use TOP tabs; one job per pane; secondary content uses click-to-expand cards (default collapsed); redesign non-compliant dense pages to tabs—never stack list+form+dictionary on one long page (feature_ui_keep_simple_top_tabs).';
        $rules[] = 'During verify/acceptance when ui_skill_decision=participate or visual UI surfaces changed, run 审图 and record type=shentu evidence with 审图/线稿/checklist signals (acceptance_phase_requires_shentu).';
        $rules[] = 'Before closeout write huishen_notes containing 汇审 covering requirements/implicit_requirements/ui_skill_decision/acceptance (and participate→prototype/UI/审图); missing 汇审 blocks closeout_allowed (closeout_requires_huishen).';
        $rules[] = 'Any image / <w:file:image> / file-image node MUST set HTML width+height (or aspect_ratio) for CLS, then CSS max-width:100%;height:auto (image_explicit_width_height_css).';
        $rules[] = 'Media identity MUST use w_scope / window.w_scope (media_reference_identity_protocol); never hand-paste paths; resource.scope optional except CLI.';
        $rules[] = 'Shell+Provider isomorphism (shell_provider_business_isomorph): Payment/Dropship vendor business logic belongs in Extends Provider only; shell Controllers orchestrate and must not reimplement a specific provider.';
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
                'summary' => 'CRITICAL / MANDATORY: Preserve every pre-existing tracked, staged, untracked, and ignored dirty change across MCP repair, reload, generation refresh, validation rollback, crash recovery, and ordinary host edits. Host Agent Shell is equally forbidden from git checkout -- path, git restore, git reset, git clean, git stash (or equivalents) to “clean” a workspace, align HEAD, or redo an edit—that wipes local work and is a severe ban. Edit on current dirty files; never restore from HEAD/index then overwrite. MCP child processes may only inspect Git; every Git mutation, config/helper injection, pager helper, and force/discard path is forbidden.',
            ],
            [
                'id' => 'host_editor_rules_mcp_generated_only',
                'summary' => 'MANDATORY: Engineering/product rules are owned only by MCP hard-constraints.v1 and authoritative repo docs (Ai/Framework/module doc/). Cursor/Codex/Claude/VS Code host editor rule files (.cursor/rules/*.mdc, .cursorrules, CLAUDE.md, .codex/*, .github/copilot-instructions.md, editor-private AGENTS forks, etc.) MUST NOT be hand-authored or directly edited by the Agent as the rule source—switching projects makes those files disappear or diverge. Host editor rule artifacts (including .cursor/rules/weline-mcp-coldstart.mdc) MUST be generated only by MCP ensure/guidance HostEditorRulesGenerator; AGENTS.md remains pointer-only for MCP attach. Never treat editor-private rule files as authoritative over prepare_project hard_constraints.',
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
        ];
    }
}
