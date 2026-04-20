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

    /** AI 提供商回调传入的原始片段缓冲，按大小/时间刷入 weline_queue.result，供 operation-sse 轮询增量 */
    private string $aiRawStreamBuffer = '';

    /** 当前缓冲首字节时间（用于最长等待后强制刷盘） */
    private ?float $aiRawBufferStartedAt = null;

    private const AI_RAW_FLUSH_MIN_BYTES = 768;

    private const AI_RAW_FLUSH_MAX_WAIT_SEC = 0.18;

    private const AI_RAW_LINE_MAX_BYTES = 60000;

    public function __construct(
        private readonly int $sessionId,
        private readonly int $adminId,
        private readonly int $queueId = 0,
        private readonly string $stage = AiSiteAgentSession::STAGE_VISUAL_EDIT,
        private readonly string $operation = 'build'
    ) {
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
            // 先刷尽 AI 原始流缓冲，再写 log/progress，避免观察 SSE 侧顺序错乱
            $this->flushAiRawStreamBuffer(true);

            $payload = $this->normalizePayload($data);
            if (!isset($payload['operation']) || !\is_string($payload['operation']) || \trim($payload['operation']) === '') {
                $payload['operation'] = $this->operation;
            }
            if (!isset($payload['stage']) || !\is_string($payload['stage']) || \trim($payload['stage']) === '') {
                $payload['stage'] = $this->stage;
            }

            $message = \trim((string)($payload['message'] ?? ''));
            $this->appendQueueLog($event, $payload, $message);
            [$sessionEventType, $sessionEventPayload, $sessionEventLevel] = $this->resolveWorkspaceEventEnvelope($event, $payload);

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
     * 将模型流式回调中的原始片段写入队列日志（缓冲后落库，避免每 token 打一次 DB）。
     */
    public function recordRawAiStreamChunk(string $chunk): static
    {
        if (!$this->alive || $chunk === '' || $this->queueId <= 0) {
            return $this;
        }

        try {
            if ($this->aiRawBufferStartedAt === null) {
                $this->aiRawBufferStartedAt = \microtime(true);
            }
            $this->aiRawStreamBuffer .= $chunk;
            $now = \microtime(true);
            $bufLen = \strlen($this->aiRawStreamBuffer);
            $wait = $this->aiRawBufferStartedAt !== null ? ($now - $this->aiRawBufferStartedAt) : 0.0;
            if (
                $bufLen >= self::AI_RAW_FLUSH_MIN_BYTES
                || ($bufLen > 0 && $wait >= self::AI_RAW_FLUSH_MAX_WAIT_SEC)
            ) {
                $this->flushAiRawStreamBuffer(false);
            }
        } catch (\Throwable) {
            // 与 sendEvent 一致：不因落库失败中断队列任务
        }

        return $this;
    }

    /**
     * @param bool $force 为 true 时刷尽缓冲（任务结束/连接关闭）
     */
    private function flushAiRawStreamBuffer(bool $force): void
    {
        if ($this->queueId <= 0) {
            return;
        }
        if (!$force && $this->aiRawStreamBuffer === '') {
            return;
        }
        if ($this->aiRawStreamBuffer === '') {
            $this->aiRawBufferStartedAt = null;

            return;
        }

        $piece = $this->aiRawStreamBuffer;
        $this->aiRawStreamBuffer = '';
        $this->aiRawBufferStartedAt = null;

        if ($piece === '') {
            return;
        }

        if (\strlen($piece) > self::AI_RAW_LINE_MAX_BYTES) {
            $piece = \substr($piece, 0, self::AI_RAW_LINE_MAX_BYTES) . "\n…(truncated)";
        }

        $row = w_query('queue', 'get', ['queue_id' => $this->queueId]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $existing = (string)($row['result'] ?? '');
        $line = '[' . \date('H:i:s') . '] AI_STREAM ' . $piece;
        $patch = [
            'process' => (string)__('AI 流式生成中…') . ' (+' . \strlen($piece) . ' B)',
            'result' => $existing === '' ? $line : $existing . PHP_EOL . $line,
        ];

        w_query('queue', 'update', [
            'queue_id' => $this->queueId,
            'patch' => $patch,
        ]);

        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            $sessionService->appendEvent(
                $this->sessionId,
                $this->adminId,
                'ai_raw_chunk',
                [
                    'message' => $piece,
                    'chunk' => $piece,
                    'content' => $piece,
                    'operation' => $this->operation,
                    'stage' => $this->stage,
                    'stream_stage' => 'ai_raw_chunk',
                ],
                $this->stage,
                'info'
            );
        } catch (\Throwable) {
            // Ignore session-event side effects so queue execution keeps running.
        }
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
     * @param array<string, mixed> $payload
     */
    private function appendQueueLog(string $event, array $payload, string $message): void
    {
        if ($this->queueId <= 0) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $this->queueId]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $summary = $this->resolveSummary($event, $payload, $message);
        $line = $this->formatLogLine($event, $summary);
        $patch = [];
        if ($summary !== '') {
            $patch['process'] = $summary;
        }
        if ($line !== '') {
            $existing = (string)($row['result'] ?? '');
            $patch['result'] = $existing === '' ? $line : $existing . PHP_EOL . $line;
        }
        if ($patch === []) {
            return;
        }

        w_query('queue', 'update', [
            'queue_id' => $this->queueId,
            'patch' => $patch,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveSummary(string $event, array $payload, string $message): string
    {
        if ($message !== '') {
            return $message;
        }

        if ($event === 'chunk') {
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
        if ($event === 'chunk') {
            return $summary;
        }

        return '[' . \date('H:i:s') . '] ' . \strtoupper($event) . ' ' . $summary;
    }
}
