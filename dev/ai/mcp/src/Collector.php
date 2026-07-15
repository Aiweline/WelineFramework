<?php

declare(strict_types=1);

namespace LearningMcp;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Collector
{
    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
    ) {
    }

    /** @param resource $input
     *  @return array<string, mixed>
     */
    public function ingest(string $commandEvent, $input): array
    {
        $maximum = (int) $this->config->get('collector.max_event_bytes', 8_388_608);
        $body = stream_get_contents($input, $maximum + 1);
        if ($body === false) {
            throw new RuntimeException('Unable to read Hook stdin');
        }
        if (strlen($body) > $maximum) {
            throw new RuntimeException('Hook payload exceeds configured maximum');
        }
        $payload = Json::object($body, 'Hook payload');
        if (self::bool($payload['do_not_learn'] ?? false)) {
            return [
                'inserted' => false,
                'skipped' => true,
                'skip_reason' => 'do_not_learn',
                'redaction_count' => 0,
                'quarantined' => false,
            ];
        }
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        $cwd = trim((string) ($payload['cwd'] ?? ''));
        if ($sessionId === '' || $cwd === '') {
            throw new RuntimeException('Hook payload session_id and cwd are required');
        }
        $eventName = self::normalizeHookName($commandEvent);
        $payloadEvent = self::normalizeHookName((string) ($payload['hook_event_name'] ?? ''));
        if ($payloadEvent !== '') {
            $eventName = $payloadEvent;
        }
        if ($eventName === '') {
            throw new RuntimeException('Hook event name is required');
        }
        $includeDirty = in_array($eventName, ['session-start', 'stop'], true);
        $existingSession = null;
        try {
            $existingSession = $this->store->getSession($sessionId);
        } catch (ToolException $exception) {
            if ($exception->errorCode !== 'NOT_FOUND') {
                throw $exception;
            }
        }
        if ($existingSession !== null && !$includeDirty) {
            $projectInfo = [
                'project' => ['id' => $existingSession['project_id']],
                'repository' => $existingSession['worktree'] ?: $existingSession['cwd'],
                'branch' => $existingSession['branch'],
                'head_commit' => $existingSession['head_commit'],
                'dirty' => false,
            ];
        } else {
            $projectInfo = ProjectResolver::resolve($cwd, $includeDirty);
            if ($existingSession !== null && $existingSession['project_id'] !== $projectInfo['project']['id']) {
                throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Hook session project does not match the current repository');
            }
            $this->store->upsertProject($projectInfo['project']);
        }
        [$redacted, $redactionCount] = Redactor::value($payload);
        if (!is_array($redacted)) {
            throw new RuntimeException('Redacted Hook payload is not an object');
        }
        $content = self::extractContent($redacted);
        $quarantined = Redactor::looksLikeInjection($content);
        $metadata = $redacted;
        unset($metadata['session_id'], $metadata['cwd'], $metadata['transcript_path']);
        $metadata['redaction_count'] = $redactionCount;
        $metadata['transcript_ref_available'] = trim((string) ($payload['transcript_path'] ?? '')) !== '';
        if ($quarantined) {
            $metadata['quarantined'] = true;
        }
        [$eventType, $role, $trust] = self::classifyEvent($eventName, $redacted);
        $observedAt = self::eventTime($redacted);
        $context = [
            'cwd' => $cwd,
            'repository' => $projectInfo['repository'],
            'branch' => $projectInfo['branch'] ?? '',
            'worktree' => $projectInfo['repository'],
            'base_commit' => $eventName === 'session-start' ? ($projectInfo['head_commit'] ?? '') : '',
            'head_commit' => $projectInfo['head_commit'] ?? '',
            'model' => (string) ($redacted['model'] ?? ''),
            'tool_name' => (string) ($redacted['tool_name'] ?? ''),
        ];
        $contentHash = Ids::hash("content\n" . $content);
        $dedupKey = Ids::hash(implode("\n", [
            $sessionId,
            $eventName,
            (string) ($redacted['turn_id'] ?? ''),
            (string) ($redacted['tool_use_id'] ?? ''),
            Json::canonical($redacted),
        ]));
        $eventId = 'event-' . substr(str_replace('sha256:', '', $dedupKey), 0, 26);
        $projectId = (string) $projectInfo['project']['id'];
        $status = $eventName === 'stop' ? 'closed' : 'active';
        $session = [
            'id' => $sessionId,
            'project_id' => $projectId,
            'agent' => 'codex',
            'cwd' => $cwd,
            'branch' => $projectInfo['branch'] ?? ($existingSession['branch'] ?? ''),
            'worktree' => $projectInfo['repository'],
            'base_commit' => $existingSession['base_commit'] ?? ($projectInfo['head_commit'] ?? ''),
            'head_commit' => $projectInfo['head_commit'] ?? ($existingSession['head_commit'] ?? ''),
            'dirty_at_start' => $existingSession['dirty_at_start'] ?? (!empty($projectInfo['dirty']) && $eventName === 'session-start'),
            'dirty_at_end' => !empty($projectInfo['dirty']) && $eventName === 'stop',
            'status' => $status,
            'outcome' => (string) ($redacted['outcome'] ?? ''),
            'consent' => [
                'allow_learning' => true,
                'allow_cross_project' => false,
            ],
            'started_at' => $existingSession['started_at'] ?? $observedAt,
            'last_activity_at' => $observedAt,
            'closed_at' => $eventName === 'stop' ? $observedAt : null,
        ];
        $this->store->upsertSession($session);
        $insert = $this->store->insertEvent([
            'schema_version' => 'event.v1',
            'event_id' => $eventId,
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'turn_id' => (string) ($redacted['turn_id'] ?? ''),
            'observed_at' => $observedAt,
            'source' => 'codex_hook',
            'type' => $eventType,
            'role' => $role,
            'content_redacted' => $content,
            'content_hash' => $contentHash,
            'dedup_key' => $dedupKey,
            'trust' => $trust,
            'context' => $context,
            'metadata' => $metadata,
        ]);
        $result = [
            'event_id' => $insert['id'],
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'inserted' => $insert['inserted'],
            'skipped' => false,
            'redaction_count' => $redactionCount,
            'quarantined' => $quarantined,
            'content_redacted' => $content,
        ];
        if ($eventName === 'post-tool-use') {
            $result['index_refresh'] = self::indexRefreshHint($redacted, (string) $projectInfo['repository']);
        }
        if ($eventName === 'stop') {
            $this->store->closeSession($sessionId, (string) $session['outcome'], $observedAt);
            $job = $this->store->enqueueAnalysisForSession($sessionId, $projectId);
            $result['job_id'] = $job['id'];
            $result['job_created'] = $job['created'];
        }

        return $result;
    }

    /** @param array<string, mixed> $payload
     *  @return array{required:bool,paths:list<string>,reason:string}
     */
    private static function indexRefreshHint(array $payload, string $repository): array
    {
        $tool = strtolower(trim((string) ($payload['tool_name'] ?? '')));
        $input = $payload['tool_input'] ?? [];
        if ($tool === '') {
            return ['required' => false, 'paths' => [], 'reason' => 'missing_tool_name'];
        }
        if (str_contains($tool, 'apply_compact_edit')
            || str_contains($tool, 'rollback_edit')
            || str_contains($tool, 'index_project')) {
            return ['required' => false, 'paths' => [], 'reason' => 'tool_reindexes_itself'];
        }
        if ($tool === 'mcp__node_repl__js' || $tool === 'functions.exec') {
            return self::nodeReplRefreshHint($input, $repository);
        }
        if (str_starts_with($tool, 'mcp__')) {
            foreach (['get_edit_bundle', 'get_edit_status', 'health'] as $readOnlyTool) {
                if (str_ends_with($tool, '__' . $readOnlyTool)) {
                    return ['required' => false, 'paths' => [], 'reason' => 'known_read_only_mcp_tool'];
                }
            }

            return ['required' => true, 'paths' => [], 'reason' => 'unknown_mcp_tool_full_refresh'];
        }

        $paths = [];
        $writeTool = preg_match(
            '/(?:^|[._-])(?:apply[_-]?patch|write[_-]?file|edit[_-]?file|create[_-]?file|delete[_-]?file|move[_-]?file|rename[_-]?file)$/D',
            $tool,
        ) === 1;
        if ($writeTool) {
            self::collectToolPaths($input, $repository, $paths);
            self::collectPatchPaths($input, $repository, $paths);

            return [
                'required' => true,
                'paths' => array_slice(Text::uniqueStrings($paths), 0, 100),
                'reason' => $paths === [] ? 'write_tool_full_refresh' : 'write_tool_targeted_refresh',
            ];
        }

        if ($tool === 'bash' || str_ends_with($tool, 'exec_command') || str_ends_with($tool, 'run_command')) {
            if (self::strictReadOnlyShell($input)) {
                return ['required' => false, 'paths' => [], 'reason' => 'strict_read_only_shell'];
            }
            return ['required' => true, 'paths' => [], 'reason' => 'shell_command_full_refresh'];
        }
        if ($tool === 'write_stdin' || str_ends_with($tool, '__write_stdin')) {
            $chars = is_array($input) ? (string) ($input['chars'] ?? '') : '';
            return $chars === ''
                ? ['required' => false, 'paths' => [], 'reason' => 'terminal_poll_only']
                : ['required' => true, 'paths' => [], 'reason' => 'terminal_input_full_refresh'];
        }

        return ['required' => false, 'paths' => [], 'reason' => 'read_only_or_unrelated_tool'];
    }

    /** @param mixed $input
     *  @return array{required:bool,paths:list<string>,reason:string}
     */
    private static function nodeReplRefreshHint(mixed $input, string $repository): array
    {
        $code = is_array($input)
            ? trim((string) ($input['code'] ?? $input['script'] ?? ''))
            : (is_string($input) ? trim($input) : '');
        if ($code === '') {
            return ['required' => true, 'paths' => [], 'reason' => 'node_repl_missing_code_full_refresh'];
        }

        preg_match_all('/\\btools\\.([A-Za-z0-9_]+)\\s*\\(/', $code, $matches);
        $calls = array_map('strtolower', is_array($matches[1] ?? null) ? $matches[1] : []);
        if ($calls === []) {
            if (str_contains($code, 'tools.') || str_contains($code, 'tools[')) {
                return ['required' => true, 'paths' => [], 'reason' => 'node_repl_dynamic_tool_full_refresh'];
            }

            return ['required' => false, 'paths' => [], 'reason' => 'sandboxed_node_repl_without_local_tools'];
        }

        $readOnlyCalls = [
            'wait', 'get_goal', 'update_plan', 'view_image', 'list_mcp_resources',
            'list_mcp_resource_templates', 'read_mcp_resource', 'codex_app__load_workspace_dependencies',
            'codex_app__read_thread_terminal', 'web__run',
        ];
        $patchCount = 0;
        $commandCount = 0;
        $terminalCount = 0;
        foreach ($calls as $call) {
            if ($call === 'apply_patch') {
                ++$patchCount;
                continue;
            }
            if ($call === 'exec_command') {
                ++$commandCount;
                continue;
            }
            if ($call === 'write_stdin') {
                ++$terminalCount;
                continue;
            }
            if (in_array($call, $readOnlyCalls, true)
                || str_ends_with($call, '__get_edit_bundle')
                || str_ends_with($call, '__get_edit_status')
                || str_ends_with($call, '__health')
                || str_ends_with($call, '__apply_compact_edit')
                || str_ends_with($call, '__rollback_edit')
                || str_ends_with($call, '__index_project')) {
                continue;
            }

            return ['required' => true, 'paths' => [], 'reason' => 'node_repl_unknown_nested_tool_full_refresh'];
        }

        if ($commandCount > 0) {
            $commands = self::javascriptStringProperties($code, 'cmd');
            if (count($commands) !== $commandCount) {
                return ['required' => true, 'paths' => [], 'reason' => 'node_repl_dynamic_command_full_refresh'];
            }
            foreach ($commands as $command) {
                if (!self::strictReadOnlyShell(['cmd' => $command])) {
                    return ['required' => true, 'paths' => [], 'reason' => 'node_repl_shell_command_full_refresh'];
                }
            }
        }

        if ($terminalCount > 0) {
            $inputs = self::javascriptStringProperties($code, 'chars');
            if (count($inputs) !== $terminalCount || array_filter($inputs, static fn (string $chars): bool => $chars !== '') !== []) {
                return ['required' => true, 'paths' => [], 'reason' => 'node_repl_terminal_input_full_refresh'];
            }
        }

        if ($patchCount > 0) {
            $paths = [];
            self::collectPatchPaths($code, $repository, $paths);
            if ($paths === []) {
                foreach (self::javascriptStringLiterals($code) as $literal) {
                    self::collectPatchPaths($literal, $repository, $paths);
                }
            }
            $paths = array_slice(Text::uniqueStrings($paths), 0, 100);
            return [
                'required' => true,
                'paths' => $paths,
                'reason' => $paths === [] ? 'node_repl_patch_full_refresh' : 'node_repl_patch_targeted_refresh',
            ];
        }

        return ['required' => false, 'paths' => [], 'reason' => 'node_repl_read_only_or_self_indexing'];
    }

    /** @return list<string> */
    private static function javascriptStringProperties(string $code, string $property): array
    {
        preg_match_all(
            '/\\b' . preg_quote($property, '/') . '\\s*:\\s*("(?:\\\\.|[^"\\\\])*")/s',
            $code,
            $matches,
        );
        $values = [];
        foreach ($matches[1] ?? [] as $literal) {
            $decoded = json_decode((string) $literal, true);
            if (is_string($decoded)) {
                $values[] = $decoded;
            }
        }

        return $values;
    }

    /** @return list<string> */
    private static function javascriptStringLiterals(string $code): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"/s', $code, $matches);
        $values = [];
        foreach ($matches[0] ?? [] as $literal) {
            $decoded = json_decode((string) $literal, true);
            if (is_string($decoded)) {
                $values[] = $decoded;
            }
        }

        return $values;
    }

    /** @param mixed $value
     *  @param list<string> $paths
     */
    private static function collectToolPaths(mixed $value, string $repository, array &$paths, string $key = ''): void
    {
        if (is_string($value)) {
            if (preg_match('/^(?:path|paths|file|files|file_path|target|target_file|destination|new_path|old_path)$/iD', $key) !== 1) {
                return;
            }
            $path = self::normalizeRefreshPath($value, $repository);
            if ($path !== null) {
                $paths[] = $path;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $childKey => $child) {
            $effectiveKey = is_string($childKey) ? $childKey : $key;
            self::collectToolPaths($child, $repository, $paths, $effectiveKey);
        }
    }

    /** @param mixed $value
     *  @param list<string> $paths
     */
    private static function collectPatchPaths(mixed $value, string $repository, array &$paths): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                self::collectPatchPaths($child, $repository, $paths);
            }
            return;
        }
        if (!is_string($value) || !str_contains($value, '*** ')) {
            return;
        }
        preg_match_all(
            '/^\*\*\* (?:(?:Add|Update|Delete) File:|Move to:)\s*(.+?)\s*$/m',
            $value,
            $matches,
        );
        foreach ($matches[1] ?? [] as $candidate) {
            $path = self::normalizeRefreshPath((string) $candidate, $repository);
            if ($path !== null) {
                $paths[] = $path;
            }
        }
    }

    private static function normalizeRefreshPath(string $candidate, string $repository): ?string
    {
        $candidate = trim(str_replace('\\', '/', $candidate), " \t\n\r\0\x0B\"'`");
        $repository = rtrim(str_replace('\\', '/', $repository), '/');
        if ($candidate === '' || str_contains($candidate, "\0") || preg_match('/[\r\n*?\[\]{}]/', $candidate) === 1) {
            return null;
        }
        if (str_starts_with($candidate, '/')) {
            if ($repository === '' || !str_starts_with($candidate, $repository . '/')) {
                return null;
            }
            $candidate = substr($candidate, strlen($repository) + 1);
        }
        $candidate = preg_replace('~^(?:\./)+~', '', $candidate) ?? $candidate;
        if ($candidate === '' || str_starts_with($candidate, '/')
            || preg_match('~(?:^|/)\.\.(?:/|$)~D', $candidate) === 1) {
            return null;
        }

        return $candidate;
    }

    private static function strictReadOnlyShell(mixed $input): bool
    {
        if (is_string($input)) {
            $command = trim($input);
        } elseif (is_array($input)) {
            $command = trim((string) ($input['command'] ?? $input['cmd'] ?? $input['script'] ?? ''));
        } else {
            return false;
        }
        if ($command === '' || str_contains($command, '`') || str_contains($command, '$(')
            || preg_match('/(?<![<>=])>(?![=])|(?<![<>=])<(?![=])/', $command) === 1) {
            return false;
        }
        $segments = self::shellSegments($command);
        if ($segments === []) {
            return false;
        }
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', $segment) ?: [];
            $binary = strtolower(basename(trim((string) ($tokens[0] ?? ''), "\"'")));
            if (in_array($binary, [
                'rg', 'grep', 'head', 'tail', 'cat', 'less', 'ls', 'pwd', 'wc', 'jq', 'yq',
                'fd', 'stat', 'du', 'sort', 'uniq', 'tr', 'cut', 'true', 'false', 'test', '[',
            ], true)) {
                if (($binary === 'yq' && preg_match('/\s(?:-i|--inplace)\b/', $segment) === 1)
                    || ($binary === 'sort' && preg_match('/\s(?:-o|--output(?:=|\s))/', $segment) === 1)) {
                    return false;
                }
                continue;
            }
            if ($binary === 'sed'
                && preg_match('/^sed\s+-n\s+["\']?[0-9,$]+(?:,[0-9,$]+)?p["\']?\s+/i', $segment) === 1) {
                continue;
            }
            if ($binary === 'find'
                && preg_match('/\s-(?:delete|exec|execdir|ok|okdir|fprint|fprint0|fprintf)\b/i', $segment) !== 1) {
                continue;
            }
            if ($binary === 'git'
                && !str_contains($segment, '--output')
                && preg_match('/^git(?:\s+-c\s+\S+)*\s+(?:diff|status|log|show|rev-parse|ls-files|grep)\b/i', $segment) === 1) {
                continue;
            }
            if ($binary === 'sqlite3'
                && preg_match('/\b(?:insert|update|delete|replace|drop|alter|create|vacuum|attach|detach|reindex)\b|\.(?:read|once|output|import|restore)\b/i', $segment) !== 1) {
                continue;
            }
            if ($binary === 'php'
                && (preg_match('/^php\s+-l\s+\S+/i', $segment) === 1
                    || preg_match('/^php\s+bin\/w\s+server:(?:status|reload|restart)\b/i', $segment) === 1)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @return list<string> */
    private static function shellSegments(string $command): array
    {
        $segments = [];
        $current = '';
        $quote = '';
        $escaped = false;
        $length = strlen($command);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $command[$offset];
            if ($escaped) {
                $current .= $character;
                $escaped = false;
                continue;
            }
            if ($character === '\\' && $quote !== "'") {
                $current .= $character;
                $escaped = true;
                continue;
            }
            if ($quote !== '') {
                $current .= $character;
                if ($character === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $current .= $character;
                continue;
            }
            $next = $offset + 1 < $length ? $command[$offset + 1] : '';
            if ($character === ';' || $character === "\n" || $character === "\r"
                || $character === '|' || $character === '&') {
                $segment = trim($current);
                if ($segment !== '') {
                    $segments[] = $segment;
                }
                $current = '';
                if (($character === '|' || $character === '&') && $next === $character) {
                    ++$offset;
                }
                continue;
            }
            $current .= $character;
        }
        if ($quote !== '' || $escaped) {
            return [];
        }
        $current = trim($current);
        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    private static function normalizeHookName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value) ?? $value;
        $value = str_replace(['_', ' '], '-', $value);

        return strtolower($value);
    }

    /** @param array<string, mixed> $payload
     *  @return array{0:string,1:string,2:array{class:string,score:float}}
     */
    private static function classifyEvent(string $name, array $payload): array
    {
        return match ($name) {
            'session-start' => ['session_started', 'system', ['class' => 'agent_runtime', 'score' => 0.9]],
            'user-prompt-submit', 'user-prompt' => ['user_message', 'user', ['class' => 'human_feedback', 'score' => 1.0]],
            'pre-tool-use' => ['tool_call', 'assistant', ['class' => 'agent_action', 'score' => 0.5]],
            'post-tool-use' => [self::classifyToolResult($payload), 'tool', ['class' => 'tool_observation', 'score' => 0.9]],
            'pre-compact', 'post-compact' => ['compaction_snapshot', 'system', ['class' => 'agent_runtime', 'score' => 0.8]],
            'stop' => ['session_stopped', 'system', ['class' => 'agent_runtime', 'score' => 0.9]],
            default => ['manual_annotation', 'system', ['class' => 'unclassified', 'score' => 0.4]],
        };
    }

    /** @param array<string, mixed> $payload */
    private static function classifyToolResult(array $payload): string
    {
        $tool = strtolower((string) ($payload['tool_name'] ?? ''));
        $input = Json::encode($payload['tool_input'] ?? []);
        $command = strtolower($tool . ' ' . $input);
        if (self::containsAny($command, [
            'go test', 'phpunit', 'bin/pest', ' pytest', 'vitest', 'playwright test',
            'npm test', 'npm run test', 'pnpm test', 'yarn test', 'cargo test', 'dotnet test',
        ])) {
            return 'test_result';
        }
        if (str_contains($tool, 'browser') || str_contains($tool, 'chrome')) {
            return 'browser_result';
        }
        if (self::containsAny($command, ['go build', 'npm run build', 'pnpm build', 'yarn build', 'cargo build', 'dotnet build'])) {
            return 'build_result';
        }
        if (self::containsAny($command, ['go vet', 'golangci-lint', 'semgrep', 'eslint', 'npm run lint', 'pnpm lint', 'phpstan'])) {
            return 'lint_result';
        }

        return 'tool_result';
    }

    /** @param array<string, mixed> $payload */
    private static function extractContent(array $payload): string
    {
        foreach (['prompt', 'user_prompt', 'last_assistant_message', 'tool_response', 'tool_result', 'tool_input', 'reason', 'message'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if ($value !== null && !is_string($value)) {
                return Json::encode($value);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $payload */
    private static function eventTime(array $payload): string
    {
        foreach (['observed_at', 'timestamp', 'created_at'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            try {
                $time = new DateTimeImmutable($value);
                return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
            } catch (\Throwable) {
            }
        }

        return Clock::now();
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || (is_string($value) && strtolower($value) === 'true');
    }

    /** @param list<string> $needles */
    private static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
