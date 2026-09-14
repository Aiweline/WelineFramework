<?php

declare(strict_types=1);

/**
 * Host-visible MCP tools that every attached Cursor/Codex session must expose.
 * Index/code-map + skill tools; missing tools look like "MCP ready" but cannot guide.
 *
 * @return list<string>
 */
function welineGuidanceRequiredMcpTools(): array
{
    return [
        'prepare_project',
        'repair_project_docs',
        'resolve_task_context',
        'search_project_knowledge',
        'get_indexed_document',
        'resolve_skill',
        'get_skill',
        'project_index_status',
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
