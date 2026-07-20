<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use Throwable;

/**
 * Adds low-latency, auditable routing pressure around Codex repository reads.
 *
 * Codex can deny supported Bash, apply_patch, and MCP calls from PreToolUse,
 * but unified_exec and other tool paths are not intercepted completely. This
 * guard hard-denies the supported direct-read paths, reinforces the remaining
 * routing contract, and records only metadata/digests for attempts and bypasses.
 */
final class ProjectRoutingGuard
{
    private const MODES = ['off', 'audit', 'enforce'];

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>|null
     */
    public function handle(array $payload): ?array
    {
        $mode = self::mode();
        if ($mode === 'off') {
            return null;
        }

        $event = self::eventName((string) ($payload['hook_event_name'] ?? ''));
        if ($event === 'user-prompt-submit') {
            if ($mode !== 'enforce') {
                return null;
            }
            $project = $this->currentProject($payload);
            if ($project === null || $project['freshness'] !== 'current') {
                return null;
            }

            return [
                'hookSpecificOutput' => [
                    'hookEventName' => 'UserPromptSubmit',
                    'additionalContext' => sprintf(
                        "[Weline Project Intelligence: enforced routing]\n"
                        . "The local index for %s is current at revision %d. Before repository discovery or code/document reads, call mcp__weline_project_intelligence__get_edit_bundle exactly once with the current task, every known path, and every affected symbol. Use only the returned regions and write through apply_compact_edit; do not list, search, or read repository files one by one.\n"
                        . "Direct reads are exceptions only for higher-priority mandatory AGENTS/Skill material, an explicitly supplied user file, excluded/binary/unindexed material, validation, or an MCP result that explicitly reports stale/unavailable/insufficient context. For a Shell exception, declare WELINE_MCP_DIRECT_READ_REASON=mandatory|user-file|unindexed|validation|mcp-fallback in the command so the bypass is auditable.",
                        $project['repository'],
                        $project['revision'],
                    ),
                ],
            ];
        }

        if ($event !== 'pre-tool-use') {
            return null;
        }

        $inspection = $this->inspectTool($payload);
        if ($inspection === null) {
            return null;
        }
        $project = $this->currentProject($payload);
        if ($project === null || $project['freshness'] !== 'current') {
            return null;
        }

        $this->writeAudit($payload, $project, $inspection, $mode);
        if (($inspection['bypass_reason'] ?? '') !== '' || $mode !== 'enforce') {
            return null;
        }

        $reason = sprintf(
            'Weline Project Intelligence routing guard denied %s because project index revision %d is current. '
            . 'Use mcp__weline_project_intelligence__get_edit_bundle once with the task, known paths, and affected symbols. '
            . 'For a legitimate exception, declare WELINE_MCP_DIRECT_READ_REASON=mandatory|user-file|unindexed|validation|mcp-fallback.',
            str_replace('_', ' ', (string) $inspection['reason']),
            $project['revision'],
        );

        return [
            'systemMessage' => $reason,
            'hookSpecificOutput' => [
                'hookEventName' => 'PreToolUse',
                'permissionDecision' => 'deny',
                'permissionDecisionReason' => $reason,
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, string>|null
     */
    private function inspectTool(array $payload): ?array
    {
        $tool = strtolower(trim((string) ($payload['tool_name'] ?? '')));
        $input = $payload['tool_input'] ?? [];
        if ($tool === '' || str_contains($tool, 'weline_project_intelligence')) {
            return null;
        }

        if ($tool === 'functions.exec' || $tool === 'mcp__node_repl__js') {
            $code = is_array($input)
                ? trim((string) ($input['code'] ?? $input['script'] ?? ''))
                : (is_string($input) ? trim($input) : '');
            if ($code === '') {
                return null;
            }
            if (str_contains($code, 'tools.apply_patch') && !self::taskRecordOnly($code)) {
                return self::inspection('direct_repository_edit', $code);
            }
            $commands = self::javascriptCommands($code);
            foreach ($commands as $command) {
                $inspection = self::inspectCommand($command);
                if ($inspection !== null) {
                    return $inspection;
                }
            }
            if (str_contains($code, 'tools.exec_command') && $commands === []) {
                return self::inspection('dynamic_shell_discovery', $code);
            }

            return null;
        }

        if ($tool === 'apply_patch' || str_ends_with($tool, '__apply_patch')) {
            $encoded = is_string($input) ? $input : Json::encode($input);
            return self::taskRecordOnly($encoded)
                ? null
                : self::inspection('direct_repository_edit', $encoded);
        }

        if ($tool === 'bash' || str_ends_with($tool, 'exec_command') || str_ends_with($tool, 'run_command')) {
            $command = is_array($input)
                ? trim((string) ($input['command'] ?? $input['cmd'] ?? $input['script'] ?? ''))
                : (is_string($input) ? trim($input) : '');

            return self::inspectCommand($command);
        }

        if (preg_match('/(?:read[_-]?file|search[_-]?files?|list[_-]?(?:files|directory)|glob)$/D', $tool) === 1) {
            $encoded = is_string($input) ? $input : Json::encode($input);

            return self::inspection('direct_repository_read_tool', $encoded);
        }

        return null;
    }

    /** @return array<string, string>|null */
    private static function inspectCommand(string $command): ?array
    {
        $command = trim($command);
        if ($command === '') {
            return null;
        }

        if (preg_match(
            '/(?:^|\s)WELINE_MCP_DIRECT_READ_REASON=(["\']?)(mandatory|user-file|unindexed|validation|mcp-fallback)\1(?:\s|$)/i',
            $command,
            $matches,
        ) === 1) {
            return self::inspection('declared_direct_read_exception', $command, strtolower((string) $matches[2]));
        }

        if (self::mandatoryInstructionRead($command) || self::knownValidationCommand($command)) {
            return null;
        }

        if (preg_match(
            '~(?:^|&&|\|\||[;|])\s*(?:env\s+\S+\s+)*(?:[^\s]+/)?(?:cat|sed|head|tail|less|rg|grep|find|fd|ls|wc|stat|jq|yq|shasum|sha256sum)\b~i',
            $command,
        ) === 1
            || preg_match('~(?:^|&&|\|\||[;|])\s*git(?:\s+-c\s+\S+)*\s+(?:grep|ls-files|show)\b~i', $command) === 1
            || preg_match('/\b(?:file_get_contents|readfile|readFileSync|read_text)\s*\(/i', $command) === 1) {
            return self::inspection('repository_file_discovery', $command);
        }

        return null;
    }

    private static function mandatoryInstructionRead(string $command): bool
    {
        return preg_match(
            '~(?:^|[\s\'"/])(?:AGENTS\.md|AI-ENTRY\.md|AI-RULES-PACK\.md|global-constraints\.md|SKILL\.md|_index\.md|00-INDEX\.txt)(?:[\s\'"]|$)'
            . '|/skills/(?:[^\s\'"]+/)*(?:references?/|SKILL\.md)'
            . '|openai-docs-cache/codex-manual(?:\.outline)?\.md~i',
            $command,
        ) === 1;
    }

    private static function knownValidationCommand(string $command): bool
    {
        return preg_match(
            '~^\s*(?:'
            . 'git(?:\s+-c\s+\S+)*\s+(?:status|diff|log|rev-parse)\b'
            . '|php\s+-l\b'
            . '|(?:vendor/bin/)?(?:phpstan|psalm|semgrep|eslint)\b'
            . '|composer\s+validate\b'
            . '|php\s+dev/ai/codex/scripts/init-task\.php\b'
            . '|python3?\s+\S*(?:validate_plugin|quick_validate|update_plugin_cachebuster|read_marketplace_name)\.py\b'
            . '|codex\s+plugin\b'
            . ')~i',
            $command,
        ) === 1;
    }

    /** @return list<string> */
    private static function javascriptCommands(string $code): array
    {
        $commands = [];
        foreach ([
            '/\bcmd\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s',
            "/\\bcmd\\s*:\\s*'((?:\\\\.|[^'\\\\])*)'/s",
        ] as $pattern) {
            preg_match_all($pattern, $code, $matches);
            foreach (is_array($matches[1] ?? null) ? $matches[1] : [] as $command) {
                $commands[] = stripcslashes((string) $command);
            }
        }

        return Text::uniqueStrings($commands);
    }

    /** @return array<string, string> */
    private static function inspection(string $reason, string $source, string $bypassReason = ''): array
    {
        return [
            'reason' => $reason,
            'source_digest' => Ids::hash($source),
            'bypass_reason' => $bypassReason,
        ];
    }

    private static function taskRecordOnly(string $text): bool
    {
        if (!str_contains($text, 'dev/ai/codex/tasks/')) {
            return false;
        }
        foreach (['app/code/', 'dev/ai/mcp/', 'AGENTS.md', 'AI-ENTRY.md', '.codex/skills/'] as $otherPath) {
            if (str_contains($text, $otherPath)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $payload
     *  @return array{project_id:string,repository:string,revision:int,freshness:string}|null
     */
    private function currentProject(array $payload): ?array
    {
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        $cwd = trim((string) ($payload['cwd'] ?? ''));
        if ($cwd === '') {
            return null;
        }

        $projectId = '';
        $repository = '';
        $store = null;
        if ($sessionId !== '') {
            try {
                $store = new Store($this->config);
                $session = $store->getSession($sessionId);
                $projectId = trim((string) ($session['project_id'] ?? ''));
                $repository = trim((string) (($session['worktree'] ?? '') ?: ($session['cwd'] ?? '')));
            } catch (Throwable) {
                $projectId = '';
                $repository = '';
            } finally {
                if ($store instanceof Store) {
                    $store->close();
                }
            }
        }

        if ($projectId === '' || $repository === '') {
            try {
                $resolved = ProjectResolver::resolve($cwd, false);
            } catch (Throwable) {
                return null;
            }
            $projectId = trim((string) ($resolved['project']['id'] ?? ''));
            $repository = trim((string) ($resolved['repository'] ?? ''));
        }

        $root = realpath($repository);
        if ($projectId === '' || $root === false) {
            return null;
        }
        $databasePath = rtrim($this->config->dataDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'indexes'
            . DIRECTORY_SEPARATOR . hash('sha256', $projectId . "\0" . $root)
            . DIRECTORY_SEPARATOR . 'project.sqlite';
        if (!is_file($databasePath)) {
            return null;
        }

        try {
            $database = new PDO('sqlite:' . $databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $database->exec('PRAGMA query_only = ON');
            $database->exec('PRAGMA busy_timeout = 100');
            $statement = $database->prepare(
                "SELECT metadata_key, value_json FROM metadata WHERE metadata_key IN ('state', 'revision')"
            );
            $statement->execute();
            $metadata = [];
            foreach ($statement->fetchAll() as $row) {
                $metadata[(string) $row['metadata_key']] = json_decode((string) $row['value_json'], true);
            }
            $state = is_array($metadata['state'] ?? null) ? $metadata['state'] : [];
            $freshness = strtolower(trim((string) ($state['freshness'] ?? 'unknown')));
            $revision = max(0, (int) ($metadata['revision'] ?? $state['revision'] ?? 0));
        } catch (Throwable) {
            return null;
        }

        return [
            'project_id' => $projectId,
            'repository' => $root,
            'revision' => $revision,
            'freshness' => $freshness,
        ];
    }

    /** @param array<string, mixed> $payload
     *  @param array{project_id:string,repository:string,revision:int,freshness:string} $project
     *  @param array<string, string> $inspection
     */
    private function writeAudit(array $payload, array $project, array $inspection, string $mode): void
    {
        $directory = $this->config->dataDir();
        if (!is_dir($directory)) {
            return;
        }
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'project-routing-guard.jsonl';
        $record = [
            'at' => Clock::now(),
            'mode' => $mode,
            'project_id' => $project['project_id'],
            'revision' => $project['revision'],
            'session_digest' => Ids::hash((string) ($payload['session_id'] ?? '')),
            'tool_name' => (string) ($payload['tool_name'] ?? ''),
            'reason' => $inspection['reason'],
            'bypass_reason' => $inspection['bypass_reason'] ?? '',
            'source_digest' => $inspection['source_digest'],
        ];
        if (@file_put_contents($path, Json::encode($record) . "\n", FILE_APPEND | LOCK_EX) !== false) {
            @chmod($path, 0600);
        }
    }

    private static function mode(): string
    {
        $configured = strtolower(trim((string) (getenv('WELINE_MCP_ROUTING_GUARD') ?: 'enforce')));

        return in_array($configured, self::MODES, true) ? $configured : 'enforce';
    }

    private static function eventName(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', trim($value)) ?? $value;

        return strtolower(str_replace(['_', ' '], '-', $value));
    }
}
