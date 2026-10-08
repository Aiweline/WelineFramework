<?php

declare(strict_types=1);

/**
 * Cursor MCP host-config stability helpers.
 *
 * Goals:
 * - Bounce orphan Helper without needlessly touching mcp.json (config_changed churn).
 * - Debounce repeated ensure bounce/touch within a short window.
 * - Mark write_json install steps as noop when disk registration is already equivalent.
 */

const WELINE_CURSOR_MCP_BOUNCE_DEBOUNCE_SECONDS = 60;

/**
 * Compare MCP server registration command/args (and optional env) for semantic equality.
 *
 * @param array<string,mixed>|null $expected
 * @param array<string,mixed>|null $actual
 */
function welineMcpRegistrationSemanticallyEqual(?array $expected, ?array $actual): bool
{
    if ($expected === null && $actual === null) {
        return true;
    }
    if (!is_array($expected) || !is_array($actual)) {
        return false;
    }

    $expectedCommand = trim((string) ($expected['command'] ?? ''));
    $actualCommand = trim((string) ($actual['command'] ?? ''));
    if ($expectedCommand === '' || $actualCommand === '' || $expectedCommand !== $actualCommand) {
        return false;
    }

    $expectedArgs = welineMcpNormalizeArgList($expected['args'] ?? null);
    $actualArgs = welineMcpNormalizeArgList($actual['args'] ?? null);
    if ($expectedArgs !== $actualArgs) {
        return false;
    }

    $expectedEnv = welineMcpNormalizeEnvMap($expected['env'] ?? null);
    $actualEnv = welineMcpNormalizeEnvMap($actual['env'] ?? null);
    if ($expectedEnv === [] && $actualEnv === []) {
        return true;
    }
    if ($expectedEnv === []) {
        // Desired registration did not pin env; extra env on disk is non-breaking.
        return true;
    }

    foreach ($expectedEnv as $key => $value) {
        if (!array_key_exists($key, $actualEnv) || $actualEnv[$key] !== $value) {
            return false;
        }
    }

    return true;
}

/**
 * @param mixed $args
 * @return list<string>
 */
function welineMcpNormalizeArgList(mixed $args): array
{
    if (!is_array($args)) {
        return [];
    }
    $normalized = [];
    foreach ($args as $arg) {
        if (is_scalar($arg) || $arg === null) {
            $normalized[] = (string) $arg;
        }
    }

    return $normalized;
}

/**
 * @param mixed $env
 * @return array<string,string>
 */
function welineMcpNormalizeEnvMap(mixed $env): array
{
    if (!is_array($env)) {
        return [];
    }
    $normalized = [];
    foreach ($env as $key => $value) {
        if (!is_string($key) || $key === '' || !is_scalar($value)) {
            continue;
        }
        $normalized[$key] = (string) $value;
    }
    ksort($normalized);

    return $normalized;
}

/**
 * Build a session write_json step; mark noop when on-disk mcpServers entries already match.
 *
 * @param array<string,mixed> $document Desired JSON document (must include mcpServers or servers).
 * @return array<string,mixed>
 */
/**
 * @param list<string> $dropServers Server names that must not remain on disk (e.g. stale project aoci).
 */
function welineMcpInstallBuildWriteJsonStep(
    string $path,
    array $document,
    ?string $absolutePath = null,
    array $dropServers = [],
): array
{
    $resolved = $absolutePath;
    if ($resolved === null || $resolved === '') {
        $resolved = welineMcpInstallResolveConfigPath($path);
    }

    $step = [
        'kind' => 'write_json',
        'path' => $path,
        'document' => $document,
        'noop' => false,
    ];
    if ($dropServers !== []) {
        $step['drop_servers'] = array_values($dropServers);
        $step['agent_instruction'] = 'Write document; also remove drop_servers keys from mcpServers if present (Cursor aoci single-mount: never leave aoci in project .cursor/mcp.json).';
    }
    if (is_string($resolved) && $resolved !== '') {
        $step['absolute_path'] = $resolved;
    }

    if (!is_string($resolved) || $resolved === '' || !is_file($resolved)) {
        return $step;
    }

    $raw = (string) file_get_contents($resolved);
    $existing = json_decode($raw, true);
    if (!is_array($existing)) {
        return $step;
    }

    $existingServers = welineMcpInstallExtractServers($existing);
    foreach ($dropServers as $name) {
        if (isset($existingServers[$name])) {
            $step['noop'] = false;
            $step['skip_reason'] = 'drop_stale_server:' . $name;

            return $step;
        }
    }

    if (welineMcpInstallDocumentSemanticallyEqual($document, $existing)) {
        $step['noop'] = true;
        $step['skip_reason'] = 'semantic_equivalent';
        $step['agent_instruction'] = 'Do not rewrite or touch this MCP config; registration is already equivalent. Open a new Agent turn only.';
    }

    return $step;
}

/**
 * Desired document equals disk for every server key present in desired (extra disk keys OK).
 *
 * @param array<string,mixed> $desired
 * @param array<string,mixed> $existing
 */
function welineMcpInstallDocumentSemanticallyEqual(array $desired, array $existing): bool
{
    $desiredServers = welineMcpInstallExtractServers($desired);
    $existingServers = welineMcpInstallExtractServers($existing);
    if ($desiredServers === []) {
        return false;
    }
    foreach ($desiredServers as $name => $expectedEntry) {
        if (!isset($existingServers[$name]) || !is_array($existingServers[$name])) {
            return false;
        }
        if (!welineMcpRegistrationSemanticallyEqual($expectedEntry, $existingServers[$name])) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string,mixed> $document
 * @return array<string,array<string,mixed>>
 */
function welineMcpInstallExtractServers(array $document): array
{
    foreach (['mcpServers', 'servers'] as $key) {
        if (!isset($document[$key]) || !is_array($document[$key])) {
            continue;
        }
        $out = [];
        foreach ($document[$key] as $name => $entry) {
            if (is_string($name) && is_array($entry)) {
                $out[$name] = $entry;
            }
        }

        return $out;
    }

    return [];
}

function welineMcpInstallResolveConfigPath(string $path): ?string
{
    $trimmed = trim($path);
    if ($trimmed === '') {
        return null;
    }
    if (str_starts_with($trimmed, '~/')) {
        $home = getenv('HOME') ?: '';
        if ($home === '') {
            return null;
        }

        return $home . DIRECTORY_SEPARATOR . substr($trimmed, 2);
    }
    if (str_starts_with($trimmed, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1) {
        return $trimmed;
    }

    // Relative project paths are resolved by the Agent against repo root; no absolute here.
    return null;
}

function welineGuidanceCursorMcpBounceStampPath(): ?string
{
    $home = getenv('HOME') ?: '';
    if ($home === '') {
        return null;
    }

    return $home . DIRECTORY_SEPARATOR . '.learning-mcp' . DIRECTORY_SEPARATOR . 'cursor-mcp-bounce.stamp';
}

/**
 * @param array<string,mixed> $cursorMcpProcess
 * @param callable|null $killFn function(int $pid): bool
 * @return array<string,mixed>
 */
function welineGuidanceBounceCursorMcpProcess(
    array $cursorMcpProcess,
    ?string $userMcpPath,
    ?string $debounceStampPath = null,
    int $debounceSeconds = WELINE_CURSOR_MCP_BOUNCE_DEBOUNCE_SECONDS,
    ?callable $killFn = null,
): array
{
    $pid = (int) ($cursorMcpProcess['pid'] ?? 0);
    $reason = (string) ($cursorMcpProcess['reason'] ?? 'cursor_mcp_process_stale');
    $result = [
        'attempted' => true,
        'bounced' => false,
        'pid' => $pid,
        'signal' => 'SIGTERM',
        'touched_user_mcp' => false,
        'kill_ok' => false,
        'deferred' => false,
        'reason' => $reason,
    ];

    $stampPath = $debounceStampPath ?? welineGuidanceCursorMcpBounceStampPath();
    if (is_string($stampPath) && $stampPath !== '' && $debounceSeconds > 0) {
        $deferred = welineGuidanceCursorMcpBounceIsDebounced($stampPath, $reason, $debounceSeconds);
        if ($deferred) {
            $result['deferred'] = true;
            $result['reason'] = 'debounce_' . $reason;

            return $result;
        }
    }

    $killer = $killFn ?? static function (int $targetPid): bool {
        return $targetPid > 1 && @posix_kill($targetPid, SIGTERM) === true;
    };

    if ($pid > 1) {
        $killed = (bool) $killer($pid);
        $result['kill_ok'] = $killed;
        $result['bounced'] = $killed;
    }

    // Prefer kill-only repair. Touch mcp.json only when kill failed/unavailable —
    // otherwise Cursor sees config_changed and reloads even when cfg bytes are unchanged.
    $needsTouch = $result['kill_ok'] !== true
        && is_string($userMcpPath)
        && $userMcpPath !== ''
        && is_file($userMcpPath);
    if ($needsTouch) {
        $result['touched_user_mcp'] = @touch($userMcpPath) === true;
        if ($result['touched_user_mcp']) {
            $result['bounced'] = true;
        }
    }

    if (($result['bounced'] ?? false) === true && is_string($stampPath) && $stampPath !== '') {
        welineGuidanceCursorMcpBounceWriteStamp($stampPath, $reason);
    }

    return $result;
}

function welineGuidanceCursorMcpBounceIsDebounced(string $stampPath, string $reason, int $debounceSeconds): bool
{
    if (!is_file($stampPath) || $debounceSeconds <= 0) {
        return false;
    }
    $raw = (string) file_get_contents($stampPath);
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return false;
    }
    $stampReason = (string) ($payload['reason'] ?? '');
    $epoch = (int) ($payload['epoch'] ?? 0);
    if ($stampReason === '' || $epoch <= 0 || $stampReason !== $reason) {
        return false;
    }

    return (time() - $epoch) < $debounceSeconds;
}

function welineGuidanceCursorMcpBounceWriteStamp(string $stampPath, string $reason): void
{
    $directory = dirname($stampPath);
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    @file_put_contents($stampPath, json_encode([
        'reason' => $reason,
        'epoch' => time(),
    ], JSON_UNESCAPED_SLASHES));
}
