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
3. **阅读并遵守**返回的 `agent_guidance.hard_constraints`，再开始宿主原生编辑。
4. 读 `agent_guidance.host_codex_delegation`（`host_delegate_explore_plan_review_to_codex_cli`）：**默认不委派 Codex**——用户本回合未提及 Codex/codex/Codex CLI 时，宿主自行探索/计划/审查，禁止因 CLI 存在而自动跑 `codex`。仅当用户显式提及 Codex 且 CLI 可用时，才委派探索 / 三节详细计划 / 编码后审查给 Codex（默认最新模型，禁 `-m`）；**启动任何委派 `codex` 前必须对用户聊天明示「Codex 正在工作：{阶段}…」**，完成后写「Codex 已完成」，回退写「Codex 不可用，已回退宿主：{原因}」——禁止静默委派。Opt-in 时 Plan Mode 只承载 Codex 计划，不另写第二套笼统计划；Cursor 只按该计划编码。Codex 原生宿主禁止嵌套再调 `codex`。Opt-in 但 CLI 不可用则回退宿主自身规划并记原因。内容运营与闲聊豁免。
5. **上下文丢失自愈**：本回合若已看不到 `hard_constraints` / MCP 引导被压缩或摘要丢掉，工程任务须**重新** `prepare_project`，不得凭记忆编造规则。
6. 按需：`resolve_task_context` / `resolve_skill` / `get_skill`（检索仍可按需，**prepare 不可跳**）。
7. MCP 挂不上：用宿主 Read 打开 `AI硬规则索引.md` 继续；不得编造规则，不得假装已遵守 MCP。

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
