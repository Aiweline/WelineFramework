<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * MCP-owned host editor rule artifacts (Cursor alwaysApply .mdc).
 * Agents must not hand-author these; ensure-project-guidance regenerates them.
 */
final class HostEditorRulesGenerator
{
    public const COLDSTART_RULE_BASENAME = 'weline-mcp-coldstart.mdc';

    public const GENERATOR_MARKER = 'weline-mcp-host-editor-rules-generator';

    /**
     * Deterministic Cursor alwaysApply cold-start gate.
     * Kept short: alwaysApply budget; body points to hard_constraints.
     */
    public static function coldStartMdc(): string
    {
        return <<<'MDC'
---
description: Weline MCP 冷启动门禁（MCP 生成）：工程任务必须 prepare_project；内容运营技能跳过 MCP
alwaysApply: true
---

<!-- generated-by: weline-mcp-host-editor-rules-generator; do not hand-edit -->

# Weline MCP 冷启动（硬）

权威：`prepare_project.agent_guidance.hard_constraints`（hard-constraints.v1）与 `app/code/Weline/Ai/doc/AI硬规则索引.md`。  
本文件由 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php` 生成；禁止 Agent 手改当规则源。

## 内容运营技能（跳过 MCP · `content_ops_skills_skip_mcp`）

产品优化 / 详情优化 / 翻译优化 / 主图优化 / 新建文章 / 审查文章 / 规格修复 等：

1. **禁止** `prepare_project` / `resolve_skill` / `get_skill` / 拉 MCP 索引。
2. 宿主 **Read** 仓内 `dev/ai-command/**` + `app/code/*/doc/ai/skills/**/SKILL.md`（Store 薄镜像只指路）。
3. 同回合若另有框架 Theme/PHP 编码，仅编码切片走下方工程门禁。

## 需求分级（硬 · `requirement_engineering_grade_gate`）

每条用户需求提出后，**先**用专业工程视角（归属、扩展点、爆炸半径、耦合、验收面）判定 `request_grade`：

| 分级 | 去向 |
|------|------|
| `engineering` | 下方工程任务 + **架构级参与**（`architecture_grade_change_only`）；**禁止**当补丁/热修捷径 |
| `content_ops` | 上方内容运营（跳过 MCP） |
| `chat` | 非工程（可跳过 MCP） |

误把会改码/改耐久文档的需求判成 chat/content_ops → **必须重判为 engineering**。

## 需求扫码顺带架构隐患（硬 · `requirement_scan_architecture_smell_gate`）

工程需求讨论/分析与为此扫码时：**Cognition 须参与**相关结构认知；扫描需求路径时**顺带**标周边架构级 vs 补丁级隐患；疑点用 Weline MCP 读模块 `doc/` 索引对比正确做法；面向用户汇报「架构隐患/周边发现」，**由用户决定是否顺带处理**——禁止擅自扩大修范围。

## 工程任务（强制）

改代码 / 模块文档 / 诊断评审 / 部署规划 / 功能验收收口（不含上一节内容运营技能）：

1. 确认 MCP `weline_project_intelligence` 可用；否则先跑 ensure。
2. **本会话第一次动手前**必须调用 `prepare_project(repository=仓库绝对路径, client_session_id=稳定会话ID)`。
3. **阅读并遵守**返回的 `agent_guidance.hard_constraints`。
4. **使用 cognition 工具（仓库认知）**：prepare 之后、宿主原生编辑之前，先用 `cognition` 工具 `action=query`（按 module/path/query）取压缩条目；受管对象稳定后 `action=maintain` 一次。Cognition 已退役，不再安装或调用。
5. **架构级改动（`architecture_grade_change_only`，严重·上下文携带）**：任何 Write 前须有框架架构级方案（mechanism + owning_module + not_to_do）；**禁止**想怎么写就怎么写、在 `WlsRuntime`/编排层硬编码业务路径/slug（`runtime_orchestrator_no_business_hardcode`）。热修/性能压力不豁免归属。
6. **站/主题概念禁侵入模块（`website_concept_seed_not_in_modules`，严重·上下文携带·抽象）**：凡绑定某一网站/设计主题/品牌的开站货架、分类树、种子脚本、CatalogSeeder——**禁止**写入 `app/code` 模块（不是点名某几个站）；落点 `app/design/{Vendor}/{theme}/` 或站柜。Tax/Setup 等无站门禁的全局领域种子允许。未来任意新站/新主题同禁。
7. **可视化演示统一落 Demo 模块（`framework_demos_live_in_weline_demo`）**：肉眼验收/DEV 标记/机制演示页一律写在本机 `Weline_Demo`（**gitignored，禁止入库**）；**禁止**再开零散 `*Demo` 模块或塞进业务模块；正式 e2e 仍归归属模块 `test/`（豁免 WidgetDemo）。
8. **整机共享态须工作区范围（`machine_shared_side_effects_require_workspace_scope`，严重）**：系统/登录钥匙串 Local CA、`~/.cursor/mcp.json` 的 `LEARNING_MCP_BOUND_REPOSITORY` / `cognition --repo` 只服务**当前打开仓库根**；**禁止**把兄弟仓 `rootCA.pem` 写入钥匙串，**禁止**为兄弟仓把全局 MCP/Cognition 改绑离当前工作区；CN 同名≠同一把钥；冲突须停手问用户。未明示不得 `add-trusted-cert`。
9. 读 `agent_guidance.host_codex_delegation`（`host_delegate_explore_plan_review_to_codex_cli`）：**默认不委派 Codex**——用户本回合未提及 Codex/codex/Codex CLI 时，宿主自行探索/计划/审查，禁止因 CLI 存在而自动跑 `codex`。仅当用户显式提及 Codex 且 CLI 可用时，才委派探索 / 三节详细计划 / 编码后审查给 Codex（默认最新模型，禁 `-m`）；**启动任何委派 `codex` 前必须对用户聊天明示「Codex 正在工作：{阶段}…」**，完成后写「Codex 已完成」，回退写「Codex 不可用，已回退宿主：{原因}」——禁止静默委派。Opt-in 时 Plan Mode 只承载 Codex 计划，不另写第二套笼统计划；Cursor 只按该计划编码。Codex 原生宿主禁止嵌套再调 `codex`。Opt-in 但 CLI 不可用则回退宿主自身规划并记原因。内容运营与闲聊豁免。
10. **上下文丢失自愈（硬携带）**：本回合若已看不到 `hard_constraints` / `architecture_grade_change_only` / MCP 引导被压缩或摘要丢掉，工程任务须**重新** `prepare_project`，**禁止凭记忆继续改码**；Cognition 合同/完整认知不可靠时按第 4 条重跑 `cognition_rules` / `cognition_overview`。
11. 按需：`resolve_task_context` / `resolve_skill` / `get_skill`（检索仍可按需，**prepare 与强制 Cognition 不可跳**）。
12. MCP 挂不上＝**先自装再降级**（`mcp_call_scope` SELF-INSTALL，硬）：ensure 报未挂载时，Agent 按 `host_mcp_install` 用自己宿主的机制给自己装上（未知宿主自行定位 MCP 配置 + `known_targets`），宿主内启用后新会话验证工具；禁止让用户手改 Settings、禁止 Reload Window。只有证明本机装不上（如宿主以不可修改的 MCP 白名单拉起 Agent）才用宿主 Read 打开 `AI硬规则索引.md` 继续，并如实说明尝试与失败原因；不得编造规则，不得假装已遵守 MCP。
13. **根因修复（`no_error_masking_or_patch_workaround`，硬）**：错误必须真实暴露并保留原因链；**禁止**空/静默 `catch`、`catch` 后返回默认值当成功、`@` 抑制、静默降级/假数据/隐藏开关、删·弱化·跳过断言与测试、`--no-verify`、单站 `if`/白名单绕校验、绕 Provider·Taglib·Event·Service、改 `generated`·`vendor`、复制逻辑不修机制的补丁式修复。仍失败只能如实报「代码已改，验收未完成」+ 真实报错 + 阻塞原因。
14. **Google Chrome 先复用再连接（`google_chrome_reuse_before_connect`，硬）**：操作本机已登录 Google Chrome 之前，先读 `~/.cursor/chrome-devtools-grant.json`。记录有效则只走已挂 MCP `user-chrome-devtools`。**禁止** `puppeteer.connect`、直连 `9222`/`ws_endpoint`、再起 `chrome-devtools-mcp`、`--autoConnect` 或新的 `--wsEndpoint`。记录没有或主人已死，仍禁止自己开连接，只走复用启动器。MCP 失败禁止改走 CDP。编辑器内置 Browser 不走本条。
15. **CSS/JS/主题/前端改动必须走主题开发技能（`css_js_theme_work_requires_custom_theme_skill`，硬）**：任何店面/后台 CSS、JS、主题、布局、partial、组件观感、主题图片改动，动手前先 `get_skill(custom_theme_development|weline-custom-theme-development)` 并 Read `app/code/Weline/Theme/doc/开发/自定义主题开发路线.md`；骨架 CSS 先行、Token 实名、色盘/刻度/皮肤进 `theme-head-css-overlay`、图片 WebP 体积预算、JS 只走声明式加载。自定义主题验收一律新开本地站选该主题（`custom_theme_acceptance_new_local_website`，地址 `https://<池子域>/~site/<code>/`），**禁止**切默认站/既有站主题；改过 CSS/JS 的验收 URL 必带 `_wb_nc`。

## 非工程

闲聊 / 概念问答可跳过 MCP（须先经 `requirement_engineering_grade_gate` 判为 `chat`）。打招呼 `hi`/`你好`/`hello` 或「提取技能」须列 MCP 技能+指令。

## 编码路径

写文件只用宿主原生工具；MCP 无写仓工具。  
禁止为「记住引导」而手写/覆盖 `.cursor/rules`；只允许 ensure / 本生成器产出。  
禁止把本文件或其它手写 `.cursor/rules` 当成高于 `hard_constraints` 的权威。

MDC;
    }

    /**
     * Write/update MCP-owned Cursor rules under {repo}/.cursor/rules/.
     *
     * @return array{
     *   schema_version: string,
     *   ready: bool,
     *   changed: bool,
     *   written: list<string>,
     *   paths: array<string, string>,
     *   reason: string
     * }
     */
    public static function syncCursorRules(string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, "/\\");
        $rulesDir = $repoRoot . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'rules';
        $coldPath = $rulesDir . DIRECTORY_SEPARATOR . self::COLDSTART_RULE_BASENAME;
        $content = self::coldStartMdc();

        if (!is_dir($rulesDir) && !mkdir($rulesDir, 0775, true) && !is_dir($rulesDir)) {
            return [
                'schema_version' => 'host-editor-rules-sync.v1',
                'ready' => false,
                'changed' => false,
                'written' => [],
                'paths' => ['coldstart' => $coldPath],
                'reason' => 'mkdir_failed',
            ];
        }

        $previous = is_file($coldPath) ? (string) file_get_contents($coldPath) : null;
        $changed = $previous !== $content;
        if ($changed) {
            $written = file_put_contents($coldPath, $content);
            if ($written === false) {
                return [
                    'schema_version' => 'host-editor-rules-sync.v1',
                    'ready' => false,
                    'changed' => false,
                    'written' => [],
                    'paths' => ['coldstart' => $coldPath],
                    'reason' => 'write_failed',
                ];
            }
        }

        return [
            'schema_version' => 'host-editor-rules-sync.v1',
            'ready' => true,
            'changed' => $changed,
            'written' => $changed ? [self::COLDSTART_RULE_BASENAME] : [],
            'paths' => ['coldstart' => $coldPath],
            'reason' => $changed ? 'updated' : 'unchanged',
        ];
    }

    /**
     * Also regenerate Cursor learning hooks with ensure.
     *
     * @return array<string, mixed>
     */
    public static function syncCursorRulesAndHooks(string $repoRoot, string $mcpRoot, string $configPath = ''): array
    {
        $rules = self::syncCursorRules($repoRoot);
        $hooks = HostCursorHooksGenerator::sync($repoRoot, $mcpRoot, $configPath);

        return [
            'schema_version' => 'host-editor-rules-and-hooks-sync.v1',
            'ready' => (bool) ($rules['ready'] ?? false) && (bool) ($hooks['ready'] ?? false),
            'changed' => (bool) ($rules['changed'] ?? false) || (bool) ($hooks['changed'] ?? false),
            'written' => array_values(array_filter(array_merge(
                is_array($rules['written'] ?? null) ? $rules['written'] : [],
                is_array($hooks['written'] ?? null) ? $hooks['written'] : [],
            ))),
            'paths' => array_merge(
                is_array($rules['paths'] ?? null) ? $rules['paths'] : [],
                is_array($hooks['paths'] ?? null) ? $hooks['paths'] : [],
            ),
            'rules' => $rules,
            'hooks' => $hooks,
            'reason' => trim((string) ($rules['reason'] ?? '') . ';' . (string) ($hooks['reason'] ?? ''), ';'),
        ];
    }
}
