<?php

declare(strict_types=1);

/**
 * Report MCP refresh needs without restarting the shared Codex app-server.
 *
 * Updating Codex plugin files does not establish the state of an already loaded
 * tool catalog. A healthy STDIO server remains runnable while the host picks up
 * the updated configuration through its supported MCP refresh mechanism.
 *
 * Cursor IDE Agent snapshots tools/list at chat start. When the Cursor Helper
 * mcp-process is older than MCP source, has no learning-mcp child (orphan
 * Transport), or the STDIO probe is missing required tools (especially
 * submit_task_plan), agents must bounce the helper and open a new Agent turn —
 * continuing on a stale catalog yields HOST_MCP_NOT_ATTACHED.
 *
 * @param array<string,mixed> $hostRuntime
 * @param array<string,mixed>|null $cursorMcpProcess
 * @param list<string>|null $missingRequiredTools
 * @return array{
 *   reload_required:bool,
 *   plugin_refresh_deferred:bool,
 *   cursor_mcp_bounce_required:bool,
 *   reason:string
 * }
 */
function welineGuidanceCodexPluginNeedsRefresh(
    mixed $manifestPayload,
    string $artifactGeneration,
    string $sourceGeneration,
): bool {
    if (!is_array($manifestPayload) || array_key_exists('mcpServers', $manifestPayload)) {
        return true;
    }
    if ($artifactGeneration === '' || $sourceGeneration === '') {
        return true;
    }

    return !hash_equals($sourceGeneration, $artifactGeneration);
}

function welineGuidanceReloadDecision(
    array $hostRuntime,
    bool $mcpConfigChanged,
    bool $pluginChanged,
    ?array $cursorMcpProcess = null,
    ?array $missingRequiredTools = null,
): array {
    $isCodexHost = ($hostRuntime['kind'] ?? 'other') === 'codex_app_server';
    // The app-server's start time says nothing about its independently spawned
    // PHP MCP process or the tool catalog held by this conversation.
    $reloadRequired = false;
    $pluginRefreshDeferred = $isCodexHost && ($pluginChanged || $mcpConfigChanged);
    $missing = is_array($missingRequiredTools) ? array_values($missingRequiredTools) : [];
    $cursorStale = is_array($cursorMcpProcess)
        && ($cursorMcpProcess['current'] ?? false) !== true
        && (int) ($cursorMcpProcess['pid'] ?? 0) > 1;
    // Missing required tools are a STDIO definition failure (blocked separately).
    // Cursor bounce when Helper mcp-process is stale or orphaned (no STDIO child).
    $cursorBounceRequired = (!$isCodexHost) && $cursorStale;

    $reason = 'not_required';
    if ($missing !== []) {
        $reason = 'required_tools_missing';
    } elseif ($cursorBounceRequired) {
        $reason = (($cursorMcpProcess['reason'] ?? '') === 'orphan_no_learning_mcp_child')
            ? 'cursor_mcp_process_orphan'
            : 'cursor_mcp_process_stale';
    } elseif ($pluginRefreshDeferred) {
        $reason = 'plugin_refresh_non_blocking';
    }

    return [
        'reload_required' => $reloadRequired,
        'plugin_refresh_deferred' => $pluginRefreshDeferred,
        'cursor_mcp_bounce_required' => $cursorBounceRequired,
        'reason' => $reason,
    ];
}
