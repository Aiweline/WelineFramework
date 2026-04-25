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

    private const AI_STREAM_NOTICE_EVERY_CHUNKS = 64;

    private const AI_STREAM_NOTICE_MIN_BYTES = 65536;

    private const QUEUE_RESULT_MAX_BYTES = 262144;

    private const QUEUE_RESULT_TRUNCATION_MARKER = '[... queue log truncated ...]';

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
            $payload = $this->enrichOperationCorrelationPayload($payload);
            $payload = $this->sanitizePayloadForQueueEvent($event, $payload);

            $message = \trim((string)($payload['message'] ?? ''));
            $this->appendQueueLog($event, $payload, $message);
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
        } catch (\Throwable) {
            // Ignore writer side-effects so queue execution keeps running.
        }

        return $this;
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

            $current = $this->normalizeTokenUsage($content);
            $merged = $this->mergeTokenUsage($current, $incoming);
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

            $summary = $this->formatTokenUsageSummary($incoming, $merged);
            $line = '[' . \date('H:i:s') . '] TOKEN_USAGE ' . $summary;
            $patch = $this->buildQueueResultPatch($line, $summary);
            $patch['content'] = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

            w_query('queue', 'update', [
                'queue_id' => $this->queueId,
                'patch' => $patch,
            ]);
            $this->mirrorToCli($line);

            try {
                /** @var AiSiteAgentSessionService $sessionService */
                $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
                $sessionService->appendEvent(
                    $this->sessionId,
                    $this->adminId,
                    'token_usage',
                    [
                        'operation' => $this->operation,
                        'stage' => $this->stage,
                        'token_usage' => $merged,
                        'token_usage_delta' => $incoming,
                    ],
                    $this->stage,
                    'info'
                );
            } catch (\Throwable) {
                // Token accounting should never fail the queue job.
            }
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
        $patch = $this->buildQueueResultPatch($line, $message);

        w_query('queue', 'update', [
            'queue_id' => $this->queueId,
            'patch' => $patch,
        ]);
        $this->mirrorToCli($line);

        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            $sessionService->appendEvent(
                $this->sessionId,
                $this->adminId,
                'operation_progress',
                [
                    'message' => $message,
                    'operation' => $this->operation,
                    'stage' => $this->stage,
                    'stream_stage' => 'ai_stream_progress',
                    'suppressed_content' => true,
                    'ai_stream_chunks' => $this->suppressedAiStreamChunks,
                    'ai_stream_bytes' => $this->suppressedAiStreamBytes,
                ],
                $this->stage,
                'info'
            );
        } catch (\Throwable) {
            // Ignore session-event side effects so queue execution keeps running.
        }

        $this->lastAiStreamNoticeChunks = $this->suppressedAiStreamChunks;
        $this->lastAiStreamNoticeBytes = $this->suppressedAiStreamBytes;
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
            return $payload;
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

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isContentBearingStreamPayload(string $event, array $payload): bool
    {
        $eventType = \strtolower(\trim((string)($payload['event_type'] ?? $event)));
        if (\in_array($eventType, ['chunk', 'ai_raw_chunk', 'ai_chunk', 'plan_chunk'], true)) {
            return true;
        }

        $streamStage = \strtolower(\trim((string)($payload['stream_stage'] ?? '')));
        if (\in_array($streamStage, ['ai_raw_chunk', 'ai_chunk', 'plan_chunk', 'markdown_stream'], true)) {
            return true;
        }

        $format = \strtolower(\trim((string)($payload['format'] ?? '')));
        if ($format === 'markdown_stream') {
            return true;
        }

        $nestedPayload = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $nestedFormat = \strtolower(\trim((string)($nestedPayload['format'] ?? '')));
        $nestedStreamStage = \strtolower(\trim((string)($nestedPayload['stream_stage'] ?? '')));
        return $nestedFormat === 'markdown_stream'
            || \in_array($nestedStreamStage, ['ai_raw_chunk', 'ai_chunk', 'plan_chunk', 'markdown_stream'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendQueueLog(string $event, array $payload, string $message): void
    {
        if ($this->queueId <= 0) {
            return;
        }

        $summary = $this->resolveSummary($event, $payload, $message);
        $line = $this->formatLogLine($event, $summary);
        $patch = $this->buildQueueResultPatch($line, $summary);
        if ($patch === []) {
            return;
        }

        w_query('queue', 'update', [
            'queue_id' => $this->queueId,
            'patch' => $patch,
        ]);
        // queue:run 等 CLI 场景：除 AI_STREAM 外，progress/log/error 等也应实时出现在终端（与落库 result 一致）。
        if ($line !== '') {
            $this->mirrorToCli($line);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQueueResultPatch(string $line = '', string $process = ''): array
    {
        $patch = [];
        if ($process !== '') {
            $patch['process'] = $process;
        }
        if ($line !== '') {
            $this->ensureQueueResultCacheLoaded();
            $this->queueResultCache = $this->appendLineToQueueResultCache($this->queueResultCache, $line);
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

        $next = $existing === '' ? $line : $existing . \PHP_EOL . $line;

        return $this->trimQueueResultCache($next);
    }

    private function trimQueueResultCache(string $result): string
    {
        if (\strlen($result) <= self::QUEUE_RESULT_MAX_BYTES) {
            return $result;
        }

        $marker = self::QUEUE_RESULT_TRUNCATION_MARKER;
        $tailBudget = self::QUEUE_RESULT_MAX_BYTES - \strlen($marker) - \strlen(\PHP_EOL);
        if ($tailBudget <= 0) {
            return \substr($result, -self::QUEUE_RESULT_MAX_BYTES);
        }

        $tail = (string)\substr($result, -$tailBudget);
        $newlinePos = \strpos($tail, \PHP_EOL);
        if ($newlinePos !== false && ($newlinePos + \strlen(\PHP_EOL)) < \strlen($tail)) {
            $tail = (string)\substr($tail, $newlinePos + \strlen(\PHP_EOL));
        }

        return $marker . \PHP_EOL . $tail;
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
