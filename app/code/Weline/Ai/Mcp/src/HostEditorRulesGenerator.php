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

## 工程任务（强制）

改代码 / 模块文档 / 诊断评审 / 部署规划 / 功能验收收口（不含上一节内容运营技能）：

1. 确认 MCP `weline_project_intelligence` 可用；否则先跑 ensure。
2. **本会话第一次动手前**必须调用 `prepare_project(repository=仓库绝对路径, client_session_id=稳定会话ID)`。
3. **阅读并遵守**返回的 `agent_guidance.hard_constraints`。
4. **强制使用 AOCI（`aoci_complements_weline_mcp` HARD DEFAULT USE）**：prepare 之后、宿主原生编辑之前，必须调用 `aoci_rules`；会话无与当前仓库根/索引版本匹配的可靠完整认知时，必须再普通 `aoci_overview`（压缩恢复按 AOCI `context_compaction`）。受管对象达最终稳定后按合同 `aoci_maintain`。缺 `aoci` 工具须按 `aoci_installation.mcp_registration` 挂载并可见汇报。挂载须**语义幂等**：command/args 已等价则**禁止** rewrite/`touch` MCP 配置，只新开 Agent 回合。Cursor 的 `aoci` **只写** `~/.cursor/mcp.json`，**禁止**同会话再写项目 `.mcp.json` 的 `aoci`。**禁止** Developer: Reload Window（杀光智能体）。**禁止**把 AOCI 当成可选、跳过认知却假装已遵守。**本机无 AOCI 程序时，ensure/`prepare_project` 必须自动安装**。内容运营与闲聊豁免。**禁止**用 AOCI 替代本表工程门禁（Weline 仍管硬规则/技能/`prepare_project`）。
5. **架构级改动（`architecture_grade_change_only`，严重·上下文携带）**：任何 Write 前须有框架架构级方案（mechanism + owning_module + not_to_do）；**禁止**想怎么写就怎么写、在 `WlsRuntime`/编排层硬编码业务路径/slug（`runtime_orchestrator_no_business_hardcode`）。热修/性能压力不豁免归属。
6. **站/主题概念禁侵入模块（`website_concept_seed_not_in_modules`，严重·上下文携带·抽象）**：凡绑定某一网站/设计主题/品牌的开站货架、分类树、种子脚本、CatalogSeeder——**禁止**写入 `app/code` 模块（不是点名某几个站）；落点 `app/design/{Vendor}/{theme}/` 或站柜。Tax/Setup 等无站门禁的全局领域种子允许。未来任意新站/新主题同禁。
7. **整机共享态须工作区范围（`machine_shared_side_effects_require_workspace_scope`，严重）**：系统/登录钥匙串 Local CA、`~/.cursor/mcp.json` 的 `LEARNING_MCP_BOUND_REPOSITORY` / `aoci --repo` 只服务**当前打开仓库根**；**禁止**把兄弟仓 `rootCA.pem` 写入钥匙串，**禁止**为兄弟仓把全局 MCP/AOCI 改绑离当前工作区；CN 同名≠同一把钥；冲突须停手问用户。未明示不得 `add-trusted-cert`。
8. 读 `agent_guidance.host_codex_delegation`（`host_delegate_explore_plan_review_to_codex_cli`）：**默认不委派 Codex**——用户本回合未提及 Codex/codex/Codex CLI 时，宿主自行探索/计划/审查，禁止因 CLI 存在而自动跑 `codex`。仅当用户显式提及 Codex 且 CLI 可用时，才委派探索 / 三节详细计划 / 编码后审查给 Codex（默认最新模型，禁 `-m`）；**启动任何委派 `codex` 前必须对用户聊天明示「Codex 正在工作：{阶段}…」**，完成后写「Codex 已完成」，回退写「Codex 不可用，已回退宿主：{原因}」——禁止静默委派。Opt-in 时 Plan Mode 只承载 Codex 计划，不另写第二套笼统计划；Cursor 只按该计划编码。Codex 原生宿主禁止嵌套再调 `codex`。Opt-in 但 CLI 不可用则回退宿主自身规划并记原因。内容运营与闲聊豁免。
9. **上下文丢失自愈（硬携带）**：本回合若已看不到 `hard_constraints` / `architecture_grade_change_only` / MCP 引导被压缩或摘要丢掉，工程任务须**重新** `prepare_project`，**禁止凭记忆继续改码**；AOCI 合同/完整认知不可靠时按第 4 条重跑 `aoci_rules` / `aoci_overview`。
10. 按需：`resolve_task_context` / `resolve_skill` / `get_skill`（检索仍可按需，**prepare 与强制 AOCI 不可跳**）。
11. MCP 挂不上：用宿主 Read 打开 `AI硬规则索引.md` 继续；不得编造规则，不得假装已遵守 MCP。

## 非工程

闲聊 / 概念问答可跳过 MCP。打招呼 `hi`/`你好`/`hello` 或「提取技能」须列 MCP 技能+指令。

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
