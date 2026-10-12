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
 * mcp-process that owns a live learning-mcp child is older than MCP source,
 * ToolService already refuses work with MCP_RUNTIME_STALE. ensure may
 * *recommend* a new Agent turn, but MUST NOT SIGTERM-bounce a healthy live
 * process solely because source mtime advanced — that tears down the current
 * session pipe (Not connected) and races sibling MCP leases.
 *
 * Auto-bounce (SIGTERM) remains only when:
 * - WELINE_MCP_FORCE_BOUNCE=1, or
 * - the recorded learning-mcp pid is no longer alive (dead pipe).
 * Missing required tools stay a separate blocked status and do not alone bounce.
 *
 * An idle mcp-process with no persistent learning-mcp child is normal under
 * Cursor shared-process MCP routing; do NOT bounce it (MessagePort thrash).
 * Bounce does NOT require touching mcp.json when kill succeeds; debounce avoids
 * repeated kill/touch. Never advise Developer: Reload Window (kills all agents).
 *
 * @param array<string,mixed> $hostRuntime
 * @param array<string,mixed>|null $cursorMcpProcess
 * @param list<string>|null $missingRequiredTools
 * @return array{
 *   reload_required:bool,
 *   plugin_refresh_deferred:bool,
 *   cursor_mcp_bounce_required:bool,
 *   cursor_mcp_bounce_recommended:bool,
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
    // Idle helpers (shared_process_idle_helper / orphan_no_learning_mcp_child legacy)
    // must not bounce — that tears down Renderer↔McpProcess MessagePort.
    $idleHelper = is_array($cursorMcpProcess) && in_array(
        (string) ($cursorMcpProcess['reason'] ?? ''),
        ['shared_process_idle_helper', 'orphan_no_learning_mcp_child', 'not_running'],
        true,
    );
    $cursorBounceRecommended = (!$isCodexHost) && $cursorStale && !$idleHelper;

    $forceBounce = (string) (getenv('WELINE_MCP_FORCE_BOUNCE') ?: '') === '1';
    $pid = is_array($cursorMcpProcess) ? (int) ($cursorMcpProcess['pid'] ?? 0) : 0;
    if (is_array($cursorMcpProcess) && array_key_exists('alive', $cursorMcpProcess)) {
        $processAlive = (bool) $cursorMcpProcess['alive'];
    } else {
        $processAlive = $pid > 1
            && function_exists('posix_kill')
            && @posix_kill($pid, 0);
    }
    // Auto-kill only when transport is dead or operator forces repair — never for mere source stale.
    $cursorBounceRequired = $cursorBounceRecommended && ($forceBounce || !$processAlive);

    $reason = 'not_required';
    if ($missing !== []) {
        $reason = 'required_tools_missing';
    } elseif ($cursorBounceRequired) {
        $reason = $forceBounce ? 'cursor_mcp_force_bounce' : 'cursor_mcp_process_dead';
    } elseif ($cursorBounceRecommended) {
        $reason = 'cursor_mcp_process_stale_soft';
    } elseif ($pluginRefreshDeferred) {
        $reason = 'plugin_refresh_non_blocking';
    }

    return [
        'reload_required' => $reloadRequired,
        'plugin_refresh_deferred' => $pluginRefreshDeferred,
        'cursor_mcp_bounce_required' => $cursorBounceRequired,
        'cursor_mcp_bounce_recommended' => $cursorBounceRecommended,
        'reason' => $reason,
    ];
}
