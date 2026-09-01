<?php

declare(strict_types=1);

/**
 * Decide whether the current Codex host must reload before project work continues.
 *
 * Updating the Hook-only Codex plugin registration does not invalidate the explicit
 * STDIO MCP process. A current, healthy runtime can therefore keep serving this
 * session while the refreshed plugin artifact is picked up by a future host start.
 *
 * Cursor IDE Agent snapshots tools/list at chat start. When the Cursor Helper
 * mcp-process is older than MCP source, or the STDIO probe is missing required
 * tools (especially submit_task_plan), agents must bounce the helper and open a
 * new Agent turn — continuing on a stale catalog yields HOST_MCP_NOT_ATTACHED.
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
function welineGuidanceReloadDecision(
    array $hostRuntime,
    bool $mcpConfigChanged,
    bool $pluginChanged,
    ?array $cursorMcpProcess = null,
    ?array $missingRequiredTools = null,
): array {
    $isCodexHost = ($hostRuntime['kind'] ?? 'other') === 'codex_app_server';
    $runtimeStale = ($hostRuntime['current'] ?? false) !== true;
    $reloadRequired = $isCodexHost && ($mcpConfigChanged || $runtimeStale);
    $pluginRefreshDeferred = $isCodexHost && $pluginChanged && !$reloadRequired;
    $missing = is_array($missingRequiredTools) ? array_values($missingRequiredTools) : [];
    $cursorStale = is_array($cursorMcpProcess)
        && ($cursorMcpProcess['current'] ?? false) !== true
        && (int) ($cursorMcpProcess['pid'] ?? 0) > 1;
    // Missing required tools are a STDIO definition failure (blocked separately).
    // Cursor bounce only when the Helper mcp-process is older than MCP source.
    $cursorBounceRequired = (!$isCodexHost) && $cursorStale;

    $reason = 'not_required';
    if ($reloadRequired) {
        $reason = $runtimeStale ? 'runtime_generation_stale' : 'mcp_registration_changed';
    } elseif ($missing !== []) {
        $reason = 'required_tools_missing';
    } elseif ($cursorBounceRequired) {
        $reason = 'cursor_mcp_process_stale';
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
