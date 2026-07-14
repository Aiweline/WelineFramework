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
        ];
        if ($eventName === 'stop') {
            $this->store->closeSession($sessionId, (string) $session['outcome'], $observedAt);
            $job = $this->store->enqueueAnalysisForSession($sessionId, $projectId);
            $result['job_id'] = $job['id'];
            $result['job_created'] = $job['created'];
        }

        return $result;
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
