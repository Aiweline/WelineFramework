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
description: Weline MCP 冷启动门禁（MCP 生成）：工程任务必须 prepare_project 并遵守 hard_constraints
alwaysApply: true
---

<!-- generated-by: weline-mcp-host-editor-rules-generator; do not hand-edit -->

# Weline MCP 冷启动（硬）

权威：`prepare_project.agent_guidance.hard_constraints`（hard-constraints.v1）与 `app/code/Weline/Ai/doc/AI硬规则索引.md`。  
本文件由 `php app/code/Weline/Ai/Mcp/scripts/ensure-project-guidance.php` 生成；禁止 Agent 手改当规则源。

## 工程任务（强制）

改代码 / 模块文档 / 诊断评审 / 部署规划 / 功能验收收口：

1. 确认 MCP `weline_project_intelligence` 可用；否则先跑 ensure。
2. **本会话第一次动手前**必须调用 `prepare_project(repository=仓库绝对路径, client_session_id=稳定会话ID)`。
3. **阅读并遵守**返回的 `agent_guidance.hard_constraints`，再开始宿主原生编辑。
4. 按需：`resolve_task_context` / `resolve_skill` / `get_skill`（检索仍可按需，**prepare 不可跳**）。
5. MCP 挂不上：用宿主 Read 打开 `AI硬规则索引.md` 继续；不得编造规则，不得假装已遵守 MCP。

## 非工程

闲聊 / 概念问答可跳过 MCP。打招呼 `hi`/`你好`/`hello` 或「提取技能」须列 MCP 技能+指令。

## 编码路径

写文件只用宿主原生工具；MCP 无写仓工具。  
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
}
