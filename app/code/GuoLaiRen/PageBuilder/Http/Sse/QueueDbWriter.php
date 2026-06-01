<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Http\Sse;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;

class QueueDbWriter extends SseWriter
{
    private bool $alive = true;

    private bool $queueResultCacheLoaded = false;

    private string $queueResultCache = '';

    private int $suppressedAiStreamChunks = 0;

    private int $suppressedAiStreamBytes = 0;

    private int $lastAiStreamNoticeChunks = 0;

    private int $lastAiStreamNoticeBytes = 0;

    /**
     * @var array<string, int>
     */
    private array $lastTelemetrySignatures = [];

    /**
     * Providers commonly report cumulative stream usage. Track the last value
     * seen in this queue worker so repeated callbacks do not inflate totals.
     *
     * @var array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null}
     */
    private array $lastRecordedTokenUsage = [
        'input_tokens' => null,
        'output_tokens' => null,
        'total_tokens' => null,
    ];

    private const AI_STREAM_NOTICE_EVERY_CHUNKS = 512;

    private const AI_STREAM_NOTICE_MIN_BYTES = 65536;

    private const QUEUE_RESULT_MAX_BYTES = 4096;

    private const QUEUE_PROCESS_MAX_BYTES = 1024;

    private const QUEUE_EVENT_PAYLOAD_MAX_BYTES = 4096;

    private const QUEUE_EVENT_TEXT_MAX_BYTES = 512;

    private const QUEUE_RESULT_TRUNCATION_MARKER = '[... queue log truncated ...]';

    private const TELEMETRY_DUPLICATE_SUPPRESSION_SECONDS = 5;

    private const HEAVY_QUEUE_EVENT_FIELDS = [
        'state',
        'workspace_state',
        'scope',
        'snapshot',
        'events',
        'top_logs',
        'result_log',
        'queue_result_delta',
        'queue_result',
        'plan_workbench',
        'build_plan_v2',
        'task_results',
        'pagebuilder_pages_by_type',
        'virtual_pages_by_type',
        'page_type_layouts',
        'blocks',
        'component',
        'html',
        'css',
        'content',
        'raw',
        'delta',
        'text',
        'chunk',
    ];

    public function __construct(
        private readonly int $sessionId,
        private readonly int $adminId,
        private readonly int $queueId = 0,
        private readonly string $stage = AiSiteAgentSession::STAGE_VISUAL_EDIT,
        private readonly string $operation = 'build',
        private readonly string $executionToken = '',
        private readonly string $jobKey = '',
        private readonly string $jobType = ''
    ) {
    }

    public function getQueueId(): int
    {
        return $this->queueId;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getExecutionToken(): string
    {
        return $this->executionToken;
    }

    public function getJobKey(): string
    {
        return $this->jobKey;
    }

    public function getJobType(): string
    {
        return $this->jobType;
    }

    public function start(): static
    {
        return $this;
    }

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): static
    {
        if (!$this->alive) {
            return $this;
        }

        try {
            $this->flushAiRawStreamBuffer(false);

            $payload = $this->normalizePayload($data);
            if (!isset($payload['operation']) || !\is_string($payload['operation']) || \trim($payload['operation']) === '') {
                $payload['operation'] = $this->operation;
            }
            if (!isset($payload['stage']) || !\is_string($payload['stage']) || \trim($payload['stage']) === '') {
                $payload['stage'] = $this->stage;
            }
            if ($this->isContentBearingStreamPayload($event, $payload)) {
                $this->recordSuppressedStreamPayload($payload);
                return $this;
            }
            $payload = $this->enrichOperationCorrelationPayload($payload);
            $payload = $this->sanitizePayloadForQueueEvent($event, $payload);

            $message = \trim((string)($payload['message'] ?? ''));
            if (!$this->appendQueueLog($event, $payload, $message)) {
                return $this;
            }
            if ($this->shouldPersistSessionEvent($event, $payload)) {
                [$sessionEventType, $sessionEventPayload, $sessionEventLevel] = $this->resolveWorkspaceEventEnvelope($event, $payload);
                $sessionEventPayload = $this->sanitizePayloadForQueueEvent($sessionEventType, $sessionEventPayload);

                /** @var AiSiteAgentSessionService $sessionService */
                $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
                $sessionService->appendEvent(
                    $this->sessionId,
                    $this->adminId,
                    $sessionEventType,
                    $sessionEventPayload,
                    $this->stage,
                    $sessionEventLevel
                );
            }

            unset($payload, $sessionEventPayload);
        } catch (\Throwable) {
            // Ignore writer side-effects so queue execution keeps running.
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordSuppressedStreamPayload(array $payload): void
    {
        if (!$this->alive || $this->queueId <= 0) {
            return;
        }

        try {
            $this->suppressedAiStreamChunks++;
            $this->suppressedAiStreamBytes += $this->calculateContentPayloadBytes($payload);
            $this->flushAiRawStreamBuffer(false);
        } catch (\Throwable) {
            // Content stream telemetry must never interrupt queue execution.
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function calculateContentPayloadBytes(array $payload): int
    {
        $bytes = 0;
        foreach (['message', 'chunk', 'content', 'raw', 'delta', 'text'] as $field) {
            if (isset($payload[$field]) && \is_string($payload[$field])) {
                $bytes += \strlen($payload[$field]);
            }
        }
        if (\is_array($payload['payload'] ?? null)) {
            foreach (['message', 'chunk', 'content', 'raw', 'delta', 'text'] as $field) {
                if (isset($payload['payload'][$field]) && \is_string($payload['payload'][$field])) {
                    $bytes += \strlen($payload['payload'][$field]);
                }
            }
        }

        return \max(0, $bytes);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function enrichOperationCorrelationPayload(array $payload): array
    {
        if ($this->queueId > 0 && (int)($payload['queue_id'] ?? 0) <= 0) {
            $payload['queue_id'] = $this->queueId;
        }

        $executionToken = \trim($this->executionToken);
        if ($executionToken !== '' && \trim((string)($payload['execution_token'] ?? '')) === '') {
            $payload['execution_token'] = $executionToken;
        }

        $jobKey = \trim($this->jobKey);
        if ($jobKey !== '' && \trim((string)($payload['job_key'] ?? '')) === '') {
            $payload['job_key'] = $jobKey;
        }

        $jobType = \trim($this->jobType);
        if ($jobType !== '' && \trim((string)($payload['job_type'] ?? '')) === '') {
            $payload['job_type'] = $jobType;
        }

        return $payload;
    }

    public function sendData(mixed $data): static
    {
        return $this->sendEvent('data', $data);
    }

    public function sendComment(string $comment = ''): static
    {
        return $this;
    }

    public function sendHeartbeat(): static
    {
        return $this;
    }

    public function maybeHeartbeat(): static
    {
        return $this;
    }

    public function yieldAfterSend(): static
    {
        return $this;
    }

    public function sendError(string $message, int $code = 500): static
    {
        return $this->sendEvent('error', [
            'message' => $message,
            'code' => $code,
        ]);
    }

    public function complete(mixed $data = null): void
    {
        $this->flushAiRawStreamBuffer(true);
        $this->alive = false;
    }

    public function close(): void
    {
        $this->flushAiRawStreamBuffer(true);
        $this->alive = false;
    }

    /**
     * 记录模型流式回调进度，不再把 AI 正文片段写入 queue.result / session event。
     */
    public function recordRawAiStreamChunk(string $chunk): static
    {
        if (!$this->alive || $chunk === '' || $this->queueId <= 0) {
            return $this;
        }

        try {
            $this->suppressedAiStreamChunks++;
            $this->suppressedAiStreamBytes += \strlen($chunk);
            $this->flushAiRawStreamBuffer(false);
        } catch (\Throwable) {
            // 与 sendEvent 一致：不因落库失败中断队列任务
        }

        return $this;
    }


    /**
     * Persist AI token usage onto the queue row content so queue observers and
     * later audit screens can read usage without querying provider-specific logs.
     *
     * @param array<string, mixed> $usage
     * @param array<string, mixed> $meta
     */
    public function recordTokenUsage(array $usage, array $meta = []): static
    {
        if ($this->queueId <= 0 || $usage === []) {
            return $this;
        }

        try {
            $incoming = $this->normalizeTokenUsage($usage);
            if (!$this->hasAnyTokenCount($incoming)) {
                return $this;
            }

            $row = w_query('queue', 'get', ['queue_id' => $this->queueId]);
            if (!\is_array($row) || $row === []) {
                return $this;
            }

            $content = \json_decode((string)($row['content'] ?? ''), true);
            if (!\is_array($content)) {
                $content = [];
            }

            $increment = $this->calculateTokenUsageIncrement($incoming);
            if (!$this->hasPositiveTokenCount($increment)) {
                return $this;
            }

            $current = $this->normalizeTokenUsage($content);
            $merged = $this->mergeTokenUsage($current, $increment);
            $mergedMeta = $this->mergeTokenCostMeta(
                \is_array($current['token_cost_meta'] ?? null) ? $current['token_cost_meta'] : [],
                \is_array($incoming['token_cost_meta'] ?? null) ? $incoming['token_cost_meta'] : [],
                $meta
            );
            if ($mergedMeta !== []) {
                $merged['token_cost_meta'] = $mergedMeta;
            }

            $content['token_usage'] = $merged;
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
                if ($merged[$tokenKey] !== null) {
                    $content[$tokenKey] = $merged[$tokenKey];
                }
            }

            $summary = $this->formatTokenUsageSummary($increment, $merged);
            $line = '[' . \date('H:i:s') . '] TOKEN_USAGE ' . $summary;
            $patch = $this->buildQueueResultPatch('', $summary);
            $patch['content'] = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

            w_query('queue', 'update', [
                'queue_id' => $this->queueId,
                'patch' => $patch,
            ]);
            $this->mirrorToCli($line);
        } catch (\Throwable) {
            // Token accounting is diagnostic/audit data and must not interrupt queue execution.
        }

        return $this;
    }

    /**
     * @param bool $force 为 true 时写入最终节流进度
     */
    private function flushAiRawStreamBuffer(bool $force): void
    {
        if ($this->queueId <= 0 || $this->suppressedAiStreamChunks <= 0) {
            return;
        }
        if (!$this->shouldEmitSuppressedStreamTelemetry()) {
            $this->lastAiStreamNoticeChunks = $this->suppressedAiStreamChunks;
            $this->lastAiStreamNoticeBytes = $this->suppressedAiStreamBytes;
            return;
        }
        $chunkDelta = $this->suppressedAiStreamChunks - $this->lastAiStreamNoticeChunks;
        $byteDelta = $this->suppressedAiStreamBytes - $this->lastAiStreamNoticeBytes;
        if (
            !$force
            && $chunkDelta < self::AI_STREAM_NOTICE_EVERY_CHUNKS
            && $byteDelta < self::AI_STREAM_NOTICE_MIN_BYTES
        ) {
            return;
        }
        if ($chunkDelta <= 0 && $byteDelta <= 0) {
            return;
        }

        $message = (string)__('AI 正在生成内容，正文流不写入队列日志（已接收 %{chunks} 段 / %{bytes} B）。', [
            'chunks' => (string)$this->suppressedAiStreamChunks,
            'bytes' => (string)$this->suppressedAiStreamBytes,
        ]);
        $line = '[' . \date('H:i:s') . '] AI_PROGRESS ' . $message;
        $patch = $this->buildQueueResultPatch('', $message);

        w_query('queue', 'update', [
            'queue_id' => $this->queueId,
            'patch' => $patch,
        ]);
        $this->mirrorToCli($line);

        $this->lastAiStreamNoticeChunks = $this->suppressedAiStreamChunks;
        $this->lastAiStreamNoticeBytes = $this->suppressedAiStreamBytes;
    }

    private function shouldEmitSuppressedStreamTelemetry(): bool
    {
        return $this->operation === 'plan';
    }


    /**
     * @param array<string, mixed> $source
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null}
     */
    private function normalizeTokenUsage(array $source): array
    {
        $nested = \is_array($source['token_usage'] ?? null) ? $source['token_usage'] : [];
        $input = $this->normalizeTokenCount(
            $nested['input_tokens']
            ?? $source['input_tokens']
            ?? $nested['prompt_tokens']
            ?? $source['prompt_tokens']
            ?? $nested['prompt_eval_count']
            ?? $source['prompt_eval_count']
            ?? null
        );
        $output = $this->normalizeTokenCount(
            $nested['output_tokens']
            ?? $source['output_tokens']
            ?? $nested['completion_tokens']
            ?? $source['completion_tokens']
            ?? $nested['eval_count']
            ?? $source['eval_count']
            ?? null
        );
        $total = $this->normalizeTokenCount(
            $nested['total_tokens']
            ?? $source['total_tokens']
            ?? null
        );
        if ($total === null && $input !== null && $output !== null) {
            $total = $input + $output;
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'token_cost_meta' => $this->extractTokenCostMeta($source, $nested),
        ];
    }

    private function normalizeTokenCount(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (\is_float($value)) {
            return $value >= 0 ? (int)\round($value) : null;
        }
        if (\is_string($value)) {
            $trimmed = \trim($value);
            if ($trimmed !== '' && \preg_match('/^\d+$/', $trimmed) === 1) {
                return (int)$trimmed;
            }
        }

        return null;
    }

    /**
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $usage
     */
    private function hasAnyTokenCount(array $usage): bool
    {
        return $usage['input_tokens'] !== null
            || $usage['output_tokens'] !== null
            || $usage['total_tokens'] !== null;
    }

    /**
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $incoming
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null}
     */
    private function calculateTokenUsageIncrement(array $incoming): array
    {
        $increment = [
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'token_cost_meta' => $incoming['token_cost_meta'],
        ];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
            $count = $incoming[$tokenKey];
            if ($count === null) {
                continue;
            }
            $previous = $this->lastRecordedTokenUsage[$tokenKey] ?? null;
            $increment[$tokenKey] = $previous === null || $count < $previous
                ? $count
                : $count - $previous;
            $this->lastRecordedTokenUsage[$tokenKey] = $count;
        }

        if (
            $increment['total_tokens'] === null
            && $increment['input_tokens'] !== null
            && $increment['output_tokens'] !== null
        ) {
            $increment['total_tokens'] = $increment['input_tokens'] + $increment['output_tokens'];
        }

        return $increment;
    }

    /**
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $usage
     */
    private function hasPositiveTokenCount(array $usage): bool
    {
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
            if (($usage[$tokenKey] ?? null) !== null && (int)$usage[$tokenKey] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $current
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $incoming
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null}
     */
    private function mergeTokenUsage(array $current, array $incoming): array
    {
        $merged = $current;
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
            if ($incoming[$tokenKey] === null) {
                continue;
            }
            $merged[$tokenKey] = ($merged[$tokenKey] ?? 0) + $incoming[$tokenKey];
        }
        if ($merged['total_tokens'] === null && $merged['input_tokens'] !== null && $merged['output_tokens'] !== null) {
            $merged['total_tokens'] = $merged['input_tokens'] + $merged['output_tokens'];
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $nested
     * @return array<string, mixed>|null
     */
    private function extractTokenCostMeta(array $source, array $nested): ?array
    {
        $meta = \is_array($nested['token_cost_meta'] ?? null)
            ? $nested['token_cost_meta']
            : (\is_array($source['token_cost_meta'] ?? null) ? $source['token_cost_meta'] : []);
        foreach ([
            'prompt_tokens_details',
            'completion_tokens_details',
            'input_token_details',
            'output_token_details',
            'cost',
        ] as $key) {
            if (\array_key_exists($key, $source)) {
                $meta[$key] = $source[$key];
            } elseif (\array_key_exists($key, $nested)) {
                $meta[$key] = $nested[$key];
            }
        }

        return $meta !== [] ? $meta : null;
    }

    /**
     * @param array<string, mixed> ...$items
     * @return array<string, mixed>
     */
    private function mergeTokenCostMeta(array ...$items): array
    {
        $merged = [];
        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $merged[(string)$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $delta
     * @param array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null} $total
     */
    private function formatTokenUsageSummary(array $delta, array $total): string
    {
        return 'input=' . (string)($total['input_tokens'] ?? 0)
            . ' output=' . (string)($total['output_tokens'] ?? 0)
            . ' total=' . (string)($total['total_tokens'] ?? 0)
            . ' (delta input=' . (string)($delta['input_tokens'] ?? 0)
            . ' output=' . (string)($delta['output_tokens'] ?? 0)
            . ' total=' . (string)($delta['total_tokens'] ?? 0) . ')';
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $data): array
    {
        if (\is_array($data)) {
            return $data;
        }
        if (\is_string($data)) {
            return ['message' => $data];
        }

        return ['raw' => $data];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0:string,1:array<string, mixed>,2:string}
     */
    private function resolveWorkspaceEventEnvelope(string $event, array $payload): array
    {
        $eventType = \trim($event) !== '' ? \trim($event) : 'info';
        $eventPayload = $payload;

        if ($eventType === 'log') {
            $resolvedEventType = \trim((string)($payload['event_type'] ?? ''));
            if ($resolvedEventType !== '') {
                $eventType = $resolvedEventType;
            }
            $eventPayload = $this->normalizeWorkspaceLogPayload($payload);
        }

        $level = \trim((string)($payload['level'] ?? $eventPayload['level'] ?? ''));
        if ($level === '') {
            $level = match (\strtolower($eventType)) {
                'warning' => 'warning',
                'error', 'operation_failed' => 'error',
                default => 'info',
            };
        }

        return [$eventType, $eventPayload, $level];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeWorkspaceLogPayload(array $payload): array
    {
        $nestedPayload = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $normalized = \array_replace($nestedPayload, $payload);

        unset(
            $normalized['payload'],
            $normalized['event_type'],
            $normalized['level'],
            $normalized['stage_code'],
            $normalized['event_id'],
            $normalized['created_at']
        );

        $message = \trim((string)($payload['message'] ?? $nestedPayload['message'] ?? ''));
        if ($message !== '') {
            $normalized['message'] = $message;
        }
        if (\trim((string)($normalized['operation'] ?? '')) === '') {
            $normalized['operation'] = \trim((string)($nestedPayload['operation'] ?? $payload['operation'] ?? $this->operation));
        }
        if (\trim((string)($normalized['stage'] ?? '')) === '') {
            $normalized['stage'] = \trim((string)($nestedPayload['stage'] ?? $payload['stage'] ?? $this->stage));
        }

        return $normalized;
    }

    /**
     * Queue rows and session events should only carry lifecycle telemetry.
     * Generated markdown/JSON/content chunks are persisted in the final stage
     * draft by business services, not duplicated into queue.result.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForQueueEvent(string $event, array $payload): array
    {
        if (!$this->isContentBearingStreamPayload($event, $payload)) {
            return $this->capQueueEventPayloadBytes($this->pruneHeavyQueuePayloadFields($payload));
        }

        $suppressedBytes = 0;
        foreach (['message', 'chunk', 'content', 'raw', 'delta', 'text'] as $field) {
            if (isset($payload[$field]) && \is_string($payload[$field])) {
                $suppressedBytes += \strlen($payload[$field]);
            }
        }
        if (\is_array($payload['payload'] ?? null)) {
            foreach (['message', 'chunk', 'content', 'raw', 'delta', 'text'] as $field) {
                if (isset($payload['payload'][$field]) && \is_string($payload['payload'][$field])) {
                    $suppressedBytes += \strlen($payload['payload'][$field]);
                    unset($payload['payload'][$field]);
                }
            }
        }

        unset(
            $payload['chunk'],
            $payload['content'],
            $payload['raw'],
            $payload['delta'],
            $payload['text']
        );

        $payload['message'] = (string)__('AI 内容已生成并写入阶段草案，正文流已从队列 SSE 中省略。');
        $payload['suppressed_content'] = true;
        $payload['suppressed_content_bytes'] = \max(0, $suppressedBytes);
        if (\trim((string)($payload['stream_stage'] ?? '')) === '') {
            $payload['stream_stage'] = 'content_suppressed';
        }

        return $this->capQueueEventPayloadBytes($this->pruneHeavyQueuePayloadFields($payload));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function pruneHeavyQueuePayloadFields(array $payload): array
    {
        foreach (self::HEAVY_QUEUE_EVENT_FIELDS as $field) {
            if (!\array_key_exists($field, $payload) || !$this->isHeavyQueuePayloadValue($payload[$field])) {
                continue;
            }
            $this->replaceHeavyPayloadValueWithRef($payload, $field, $payload[$field]);
        }

        foreach (['payload', 'details', 'checkpoint', 'terminal_summary'] as $nestedField) {
            if (!\is_array($payload[$nestedField] ?? null)) {
                continue;
            }
            $nested = $payload[$nestedField];
            foreach (self::HEAVY_QUEUE_EVENT_FIELDS as $field) {
                if (\array_key_exists($field, $nested) && $this->isHeavyQueuePayloadValue($nested[$field])) {
                    $this->replaceHeavyPayloadValueWithRef($nested, $field, $nested[$field]);
                }
            }
            $payload[$nestedField] = $nested;
        }

        foreach (['message', 'failure_reason', 'error_message'] as $textField) {
            if (\is_string($payload[$textField] ?? null)) {
                $payload[$textField] = $this->strcutQueueTail($payload[$textField], self::QUEUE_EVENT_TEXT_MAX_BYTES);
            }
        }

        return $payload;
    }

    private function isHeavyQueuePayloadValue(mixed $value): bool
    {
        if (\is_array($value)) {
            return $value !== [];
        }
        if (\is_string($value)) {
            return \strlen($value) > self::QUEUE_EVENT_TEXT_MAX_BYTES;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function replaceHeavyPayloadValueWithRef(array &$payload, string $field, mixed $value): void
    {
        $encoded = $this->encodePayloadForSize($value);
        unset($payload[$field]);
        $payload[$field . '_loaded'] = false;
        $payload[$field . '_ref'] = $field;
        $payload[$field . '_bytes'] = \strlen($encoded);
        $payload[$field . '_hash'] = \sha1($encoded);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function capQueueEventPayloadBytes(array $payload): array
    {
        if ($this->payloadBytes($payload) <= self::QUEUE_EVENT_PAYLOAD_MAX_BYTES) {
            return $payload;
        }

        foreach (['details', 'payload', 'checkpoint', 'task_runtime_context', 'queue_snapshot', 'token_cost_meta'] as $field) {
            if (!\array_key_exists($field, $payload)) {
                continue;
            }
            $this->replaceHeavyPayloadValueWithRef($payload, $field, $payload[$field]);
            if ($this->payloadBytes($payload) <= self::QUEUE_EVENT_PAYLOAD_MAX_BYTES) {
                return $payload;
            }
        }

        $compact = [];
        foreach ([
            'message',
            'operation',
            'stage',
            'page_type',
            'progress_percent',
            'queue_id',
            'execution_token',
            'job_key',
            'job_type',
            'queue_status',
            'queue_process',
            'stage1_page_progress',
            'status',
            'task_key',
            'task_type',
            'shared_region',
            'region',
            'section_code',
            'task_session_id',
            'task_sse_channel',
            'terminal',
            'terminal_summary',
            'token_usage',
        ] as $field) {
            if (\array_key_exists($field, $payload)) {
                $compact[$field] = $payload[$field];
            }
        }
        foreach ($payload as $field => $value) {
            $field = (string)$field;
            if (\str_ends_with($field, '_loaded')
                || \str_ends_with($field, '_ref')
                || \str_ends_with($field, '_bytes')
                || \str_ends_with($field, '_hash')
            ) {
                $compact[$field] = $value;
            }
        }
        $compact['payload_compacted'] = true;

        return $compact;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadBytes(array $payload): int
    {
        return \strlen($this->encodePayloadForSize($payload));
    }

    private function encodePayloadForSize(mixed $value): string
    {
        try {
            return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return (string)\serialize($value);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isContentBearingStreamPayload(string $event, array $payload): bool
    {
        $eventType = \strtolower(\trim((string)($payload['event_type'] ?? $event)));
        if (\in_array($eventType, [
            'chunk',
            'ai_raw_chunk',
            'ai_chunk',
            'plan_chunk',
            'thinking',
            'reasoning',
            'reasoning_chunk',
        ], true)) {
            return true;
        }

        $streamStage = \strtolower(\trim((string)($payload['stream_stage'] ?? '')));
        if (\in_array($streamStage, [
            'ai_raw_chunk',
            'ai_chunk',
            'plan_chunk',
            'markdown_stream',
            'reasoning',
            'reasoning_chunk',
            'thinking',
        ], true)) {
            return true;
        }

        $format = \strtolower(\trim((string)($payload['format'] ?? '')));
        if (\in_array($format, ['markdown_stream', 'markdown_block', 'reasoning_stream'], true)) {
            return true;
        }

        $nestedPayload = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $nestedFormat = \strtolower(\trim((string)($nestedPayload['format'] ?? '')));
        $nestedStreamStage = \strtolower(\trim((string)($nestedPayload['stream_stage'] ?? '')));
        return $nestedFormat === 'markdown_stream'
            || $nestedFormat === 'markdown_block'
            || $nestedFormat === 'reasoning_stream'
            || \in_array($nestedStreamStage, [
                'ai_raw_chunk',
                'ai_chunk',
                'plan_chunk',
                'markdown_stream',
                'reasoning',
                'reasoning_chunk',
                'thinking',
            ], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendQueueLog(string $event, array $payload, string $message): bool
    {
        if ($this->queueId <= 0) {
            return true;
        }

        $summary = $this->resolveSummary($event, $payload, $message);
        if ($this->shouldSuppressDuplicateTelemetry($event, $payload, $summary)) {
            return false;
        }
        $line = $this->formatLogLine($event, $summary);
        $resultLine = $this->shouldPersistQueueResultLine($event, $payload) ? $line : '';
        $patch = $this->buildQueueResultPatch($resultLine, $summary);
        $this->mergeStageOnePageProgressIntoQueueContentPatch($patch, $payload);
        if ($patch === []) {
            return true;
        }

        w_query('queue', 'update', [
            'queue_id' => $this->queueId,
            'patch' => $patch,
        ]);
        // queue:run 等 CLI 场景：除 AI_STREAM 外，progress/log/error 等也应实时出现在终端（与落库 result 一致）。
        if ($line !== '') {
            $this->mirrorToCli($line);
        }

        return true;
    }

    /**
     * Persist only the latest compact Stage-1 page fanout snapshot so a browser
     * refresh can recover total/done/remaining progress without replaying SSE logs.
     *
     * @param array<string, mixed> $patch
     * @param array<string, mixed> $payload
     */
    private function mergeStageOnePageProgressIntoQueueContentPatch(array &$patch, array $payload): void
    {
        if ($this->queueId <= 0 || !\is_array($payload['stage1_page_progress'] ?? null)) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $this->queueId]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $content = \json_decode((string)($row['content'] ?? ''), true);
        if (!\is_array($content)) {
            $content = [];
        }
        $content['stage1_page_progress'] = $this->compactStageOnePageProgress($payload['stage1_page_progress']);
        $encoded = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (\is_string($encoded) && $encoded !== '') {
            $patch['content'] = $encoded;
        }
    }

    /**
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    private function compactStageOnePageProgress(array $progress): array
    {
        $compact = [];
        foreach ([
            'total',
            'done_count',
            'failed_count',
            'running_count',
            'pending_count',
            'remaining_count',
            'updated_at',
        ] as $key) {
            if (\array_key_exists($key, $progress)) {
                $compact[$key] = $progress[$key];
            }
        }
        foreach (['running', 'done', 'failed', 'pending'] as $key) {
            $compact[$key] = \array_values(\array_slice(
                \array_filter(\array_map('strval', \is_array($progress[$key] ?? null) ? $progress[$key] : [])),
                0,
                32
            ));
        }
        $details = [];
        foreach (\is_array($progress['details'] ?? null) ? $progress['details'] : [] as $detail) {
            if (!\is_array($detail)) {
                continue;
            }
            $details[] = [
                'page_type' => $this->strcutQueueTail((string)($detail['page_type'] ?? ''), 80),
                'status' => $this->strcutQueueTail((string)($detail['status'] ?? ''), 40),
                'message' => $this->strcutQueueTail((string)($detail['message'] ?? ''), 180),
                'error_message' => $this->strcutQueueTail((string)($detail['error_message'] ?? ''), 180),
                'updated_at' => $this->strcutQueueTail((string)($detail['updated_at'] ?? ''), 40),
            ];
            if (\count($details) >= 32) {
                break;
            }
        }
        $compact['details'] = $details;

        return $compact;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldPersistSessionEvent(string $event, array $payload): bool
    {
        return $this->shouldPersistQueueResultLine($event, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldPersistQueueResultLine(string $event, array $payload): bool
    {
        $eventType = \strtolower(\trim((string)($payload['event_type'] ?? $event)));
        if (\in_array($eventType, [
            'start',
            'started',
            'status',
            'status_transition',
            'build_plan_block_completed',
            'build_plan_block_failed',
            'complete',
            'completed',
            'done',
            'success',
            'error',
            'failed',
            'failure',
            'stop',
            'stopped',
            'cancelled',
            'canceled',
        ], true)) {
            return true;
        }
        if (\str_ends_with($eventType, '_saved') || \str_ends_with($eventType, '_persisted')) {
            return true;
        }

        if (!empty($payload['checkpoint']) || !empty($payload['terminal']) || !empty($payload['terminal_summary'])) {
            return true;
        }

        foreach (['queue_status', 'status', 'job_status', 'task_status', 'semantic_status'] as $statusKey) {
            $status = \strtolower(\trim((string)($payload[$statusKey] ?? '')));
            if (\in_array($status, [
                'done',
                'complete',
                'completed',
                'success',
                'error',
                'failed',
                'failure',
                'stop',
                'stopped',
                'cancelled',
                'canceled',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldSuppressDuplicateTelemetry(string $event, array $payload, string $summary): bool
    {
        $summary = \trim($summary);
        if ($summary === '') {
            return false;
        }

        $eventType = \strtolower(\trim((string)($payload['event_type'] ?? $event)));
        if (!\in_array($eventType, ['progress', 'operation_progress', 'ai_progress'], true)) {
            return false;
        }

        $operation = \trim((string)($payload['operation'] ?? $this->operation));
        $stage = \trim((string)($payload['stage'] ?? $this->stage));
        $signature = \sha1($eventType . '|' . $operation . '|' . $stage . '|' . $summary);
        $now = \time();
        $lastSeenAt = (int)($this->lastTelemetrySignatures[$signature] ?? 0);
        if ($lastSeenAt > 0 && ($now - $lastSeenAt) < self::TELEMETRY_DUPLICATE_SUPPRESSION_SECONDS) {
            return true;
        }

        $this->lastTelemetrySignatures[$signature] = $now;
        if (\count($this->lastTelemetrySignatures) > 512) {
            $this->lastTelemetrySignatures = \array_slice($this->lastTelemetrySignatures, -256, null, true);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQueueResultPatch(string $line = '', string $process = ''): array
    {
        $patch = [];
        if ($process !== '') {
            $patch['process'] = $this->trimQueueProcess($process);
        }
        if ($line !== '') {
            $this->queueResultCache = $this->trimQueueResultCache($this->normalizeUtf8QueueText($line));
            $this->queueResultCacheLoaded = true;
            $patch['result'] = $this->queueResultCache;
        }

        return $patch;
    }

    private function ensureQueueResultCacheLoaded(): void
    {
        if ($this->queueResultCacheLoaded || $this->queueId <= 0) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $this->queueId]);
        $this->queueResultCache = \is_array($row) ? $this->trimQueueResultCache((string)($row['result'] ?? '')) : '';
        $this->queueResultCacheLoaded = true;
    }

    private function appendLineToQueueResultCache(string $existing, string $line): string
    {
        if ($line === '') {
            return $this->trimQueueResultCache($existing);
        }

        return $this->trimQueueResultCache($line);
    }

    private function trimQueueProcess(string $process): string
    {
        return $this->strcutQueueTail($this->normalizeUtf8QueueText($process), self::QUEUE_PROCESS_MAX_BYTES);
    }

    private function trimQueueResultCache(string $result): string
    {
        $result = $this->normalizeUtf8QueueText($result);
        if (\strlen($result) <= self::QUEUE_RESULT_MAX_BYTES) {
            return $result;
        }

        $marker = self::QUEUE_RESULT_TRUNCATION_MARKER;
        $tailBudget = self::QUEUE_RESULT_MAX_BYTES - \strlen($marker) - \strlen(\PHP_EOL);
        if ($tailBudget <= 0) {
            return $this->strcutQueueTail($result, self::QUEUE_RESULT_MAX_BYTES);
        }

        $tail = $this->strcutQueueTail($result, $tailBudget);
        $newlinePos = \strpos($tail, \PHP_EOL);
        if ($newlinePos !== false && ($newlinePos + \strlen(\PHP_EOL)) < \strlen($tail)) {
            $tail = (string)\substr($tail, $newlinePos + \strlen(\PHP_EOL));
        }

        return $marker . \PHP_EOL . $this->normalizeUtf8QueueText($tail);
    }

    private function strcutQueueTail(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (\strlen($text) <= $maxBytes) {
            return $this->normalizeUtf8QueueText($text);
        }
        if (\function_exists('mb_strcut')) {
            return $this->normalizeUtf8QueueText((string)\mb_strcut($text, -$maxBytes, null, 'UTF-8'));
        }

        $tail = (string)\substr($text, -$maxBytes);
        while ($tail !== '' && !\preg_match('//u', $tail)) {
            $tail = (string)\substr($tail, 1);
        }

        return $this->normalizeUtf8QueueText($tail);
    }

    private function normalizeUtf8QueueText(string $text): string
    {
        if ($text === '' || \preg_match('//u', $text)) {
            return $text;
        }

        $converted = \function_exists('iconv') ? @\iconv('UTF-8', 'UTF-8//IGNORE', $text) : false;
        if (\is_string($converted) && \preg_match('//u', $converted)) {
            return $converted;
        }
        if (\function_exists('mb_convert_encoding')) {
            $converted = @\mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            if (\is_string($converted) && \preg_match('//u', $converted)) {
                return $converted;
            }
        }

        return (string)\preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveSummary(string $event, array $payload, string $message): string
    {
        if ($message !== '') {
            return $message;
        }

        if ($event === 'chunk' && !((bool)($payload['suppressed_content'] ?? false))) {
            $chunk = \trim((string)($payload['chunk'] ?? $payload['content'] ?? ''));
            if ($chunk !== '') {
                return $chunk;
            }
        }

        $encoded = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) && $encoded !== '[]' ? $event . ': ' . $encoded : $event;
    }

    private function formatLogLine(string $event, string $summary): string
    {
        if ($summary === '') {
            return '';
        }

        return '[' . \date('H:i:s') . '] ' . \strtoupper($event) . ' ' . $summary;
    }

    private function mirrorToCli(string $line): void
    {
        if ($line === '' || \PHP_SAPI !== 'cli') {
            return;
        }

        echo $line . \PHP_EOL;
        if (\function_exists('flush')) {
            \flush();
        }
    }
}
