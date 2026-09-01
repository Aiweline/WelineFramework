<?php

declare(strict_types=1);

/**
 * Host-visible MCP tools that every attached Cursor/Codex session must expose.
 * Missing plan tools cause PLAN_REQUIRED dead-ends that look like "MCP ready".
 *
 * @return list<string>
 */
function welineGuidanceRequiredMcpTools(): array
{
    return [
        'prepare_project',
        'resolve_task_context',
        'submit_task_plan',
        'get_task_plan',
        'update_task_plan_progress',
        'review_task_plan',
        'get_edit_bundle',
        'apply_compact_edit',
        'health',
    ];
}

/**
 * @param list<string> $tools
 * @param list<string>|null $required
 * @return list<string>
 */
function welineGuidanceMissingRequiredMcpTools(array $tools, ?array $required = null): array
{
    $required ??= welineGuidanceRequiredMcpTools();
    $present = [];
    foreach ($tools as $name) {
        $trimmed = trim((string) $name);
        if ($trimmed !== '') {
            $present[$trimmed] = true;
        }
    }
    $missing = [];
    foreach ($required as $name) {
        if (!isset($present[$name])) {
            $missing[] = $name;
        }
    }

    return $missing;
}
