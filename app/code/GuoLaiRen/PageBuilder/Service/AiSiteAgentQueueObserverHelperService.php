<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * AiSiteAgentQueueObserverHelperService
 *
 * 从 AiSiteAgent.php 抽出的 **队列观察子域纯辅助方法**。本轮只迁移三个无
 * Session / SSE / DB 依赖的判定/归一化函数：
 *  - 判断某 operation 是否应抑制 queue process 镜像下发；
 *  - 判断是否跳过一行 queue.result 日志（含时间戳 + 事件类型前缀的回放行）；
 *  - 将 queueRow + 成功标志解析成"结束文案"(process / result 尾行 / i18n 兜底)。
 *
 * 抽出动机：
 *  - 纯函数、无副作用，与控制器/会话解耦成本最低，可单元测试锁定；
 *  - 作为 R4.2 SOLID 拆分的第二块"安全样本"，范式沿用 AiSiteQueueStateService；
 *  - 让下一轮抽 `buildQueueObserverPanelPayload / emitQueueObserverQueueDetailEvents`
 *    等依赖 SSE 状态的方法时，可以直接复用这里的判定逻辑，避免跨层复制。
 *
 * 重要：方法签名、输入输出 shape 必须与 AiSiteAgent.php 原私有方法一致，
 * 以便前端/SSE 链路向后兼容；调整时同步更新 `AiSiteAgentQueueObserverHelperServiceTest`。
 */
class AiSiteAgentQueueObserverHelperService
{
    private const QUEUE_RESULT_TAIL_BYTES = 4096;
    private const QUEUE_RESULT_TAIL_LINES = 50;

    /**
     * 对 plan 这类"自带 SSE 事件流"的 operation，抑制 queue process 镜像转发；
     * 其它 operation 走默认 process 镜像。保持与原 AiSiteAgent 私有方法语义一致。
     */
    public function shouldSuppressProcessMirror(string $operation): bool
    {
        return false;
    }

    /**
     * 判断一行 queue.result 是否需要跳过：仅对 plan 两类 operation 生效，
     * 用于剔除"已由 SSE 事件流覆盖"的时间戳日志行，避免回放重复上屏。
     * 匹配示例：`[HH:MM:SS] LOG|START|INFO|WARNING|PROGRESS|ERROR|DATA|AI_STREAM|PLAN_xxx ...`
     */
    public function shouldSkipResultLine(string $operation, string $line): bool
    {
        if (
            \str_contains($line, '正文流不写入队列日志')
            || \str_contains($line, '正文流已从队列 SSE 中省略')
        ) {
            return true;
        }
        if ($operation !== 'plan') {
            return false;
        }

        if ((bool)\preg_match(
            '/^\[\d{2}:\d{2}:\d{2}\]\s+(?:LOG|START|INFO|WARNING|PROGRESS|ERROR|DATA|AI_STREAM|AI_PROGRESS|TOKEN_USAGE|PLAN_[A-Z0-9_]+)\b/u',
            $line
        )) {
            return true;
        }

        // 旧版本可能把 chunk 裸写成 markdown/json 行；规划类队列不再回放正文。
        return $line !== '';
    }

    /**
     * 根据 queueRow 解析"结束文案"：
     *  1) 优先使用结构化 `process` / `message` / `terminal_summary` 字段；
     *  2) 历史 `result` 仅作为先按字节裁剪后的兼容尾部摘要；
     *  3) 最终兜底 i18n 文案（成功 / 失败）。
     *
     * @param array<string, mixed>|null $queueRow `weline_queue` 一行原始数据；null 直接走 i18n 兜底
     */
    public function resolveMessage(?array $queueRow, bool $success): string
    {
        if (\is_array($queueRow)) {
            $message = $this->firstNonEmptyQueueText($queueRow, ['process', 'message', 'terminal_summary']);
            if ($message !== '' && ($success || !$this->isQueueStreamOmittedMessage($message))) {
                return $message;
            }

            $legacyResultMessage = $this->resolveLegacyResultMessage($queueRow);
            if ($legacyResultMessage !== '') {
                return $legacyResultMessage;
            }
        }

        return $success ? (string)__('操作执行完成') : (string)__('操作执行失败');
    }

    /**
     * @param array<string, mixed> $queueRow
     * @param array<string, mixed> $currentState
     *
     * @return array<string, mixed>
     */
    public function buildPanelPayload(array $queueRow, array $currentState): array
    {
        $process = \trim((string)($queueRow['process'] ?? ''));
        $processMax = 4000;
        if (\strlen($process) > $processMax) {
            $process = '...(showing last ' . $processMax . ' chars)' . "\n" . \substr($process, -$processMax);
        }
        $result = $this->resolveResultLogForPanel($queueRow);

        return [
            'queue_id' => (int)($currentState['queue_id'] ?? 0),
            'name' => (string)($currentState['name'] ?? ''),
            'module' => (string)($currentState['module'] ?? ''),
            'biz_key' => (string)($currentState['biz_key'] ?? ''),
            'status' => (string)($currentState['status'] ?? ''),
            'queue_status' => (string)($currentState['status'] ?? ''),
            'job_status' => (string)($currentState['job_status'] ?? ($currentState['status'] ?? '')),
            'semantic_status' => (string)($currentState['semantic_status'] ?? ($currentState['job_status'] ?? ($currentState['status'] ?? ''))),
            'pid' => (int)($currentState['pid'] ?? 0),
            'type_id' => (int)($currentState['type_id'] ?? 0),
            'finished' => (int)($currentState['finished'] ?? 0),
            'start_at' => (string)($currentState['start_at'] ?? ''),
            'end_at' => (string)($currentState['end_at'] ?? ''),
            'job_key' => (string)($currentState['job_key'] ?? ''),
            'job_type' => (string)($currentState['job_type'] ?? ''),
            'token' => (string)($currentState['token'] ?? ''),
            'token_usage' => \is_array($currentState['token_usage'] ?? null) ? $currentState['token_usage'] : [],
            'stage1_page_progress' => \is_array($currentState['stage1_page_progress'] ?? null) ? $currentState['stage1_page_progress'] : [],
            'process' => $process,
            'result_log' => $result,
        ];
    }

    /**
     * 将底层队列事件类型归一化成前端 SSE 监听的事件名。未知类型返回空串，
     * 调用方据此决定是否跳过（与原 `AiSiteAgent::mapObservedOperationEventName` 对齐）。
     */
    public function mapOperationEventName(string $eventType): string
    {
        return match ($eventType) {
            'start' => 'start',
            'info' => 'info',
            'warning' => 'warning',
            'progress' => 'progress',
            'chunk' => 'progress',
            'error' => 'error',
            'operation_started' => 'start',
            'operation_progress' => 'progress',
            'ai_raw_chunk' => 'progress',
            'plan_chunk' => 'progress',
            'plan_saved', 'plan_generated', 'plan_refined', 'plan_rebuilt' => 'info',
            'ai_chunk' => 'progress',
            'shared_component_generated' => 'shared_component_generated',
            'page_generated' => 'page_generated',
            'build_plan_block_completed' => 'build_plan_block_completed',
            'task_completed' => 'task_completed',
            // Build-plan block lifecycle events are forwarded with the same public SSE names.
            'build_plan_block_failed' => 'build_plan_block_failed',
            'operation_failed' => 'error',
            default => '',
        };
    }

    /**
     * 判断某条 queue event 是否与当前 operation / 起始时间匹配，供 SSE 转发链路过滤。
     *
     * @param array<string, mixed> $event
     */
    public function isOperationEventRelevant(array $event, string $operation, int $startedAtTs, array $correlation = []): bool
    {
        $eventType = \trim((string)($event['event_type'] ?? ''));
        if (!\in_array($eventType, [
            'start',
            'info',
            'warning',
            'progress',
            'chunk',
            'error',
            'operation_started',
            'operation_progress',
            'ai_raw_chunk',
            'plan_chunk',
            'plan_saved',
            'plan_generated',
            'plan_refined',
            'plan_rebuilt',
            'ai_chunk',
            'shared_component_generated',
            'page_generated',
            'build_plan_block_completed',
            'task_completed',
            // build_plan_block_failed must be forwardable by forwardObservedOperationEvents.
            // 否则 mapOperationEventName 即便有映射也会被 isOperationEventRelevant 的白名单挡掉。
            'build_plan_block_failed',
            'operation_failed',
        ], true)) {
            return false;
        }

        $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (\trim((string)($payload['operation'] ?? '')) !== $operation) {
            return false;
        }

        if ($startedAtTs > 0) {
            $eventTs = \strtotime(\trim((string)($event['create_time'] ?? '')));
            if ($eventTs !== false && $eventTs < $startedAtTs) {
                return false;
            }
        }

        return $this->isOperationEventCorrelationRelevant($event, $correlation);
    }

    /**
     * 过滤 queue event 列表：丢弃 event_id 小于等于 afterEventId、与当前 operation
     * 无关、或早于 startedAtRaw 的条目。保持与原控制器私有方法语义一致。
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    public function filterOperationEvents(
        array $events,
        string $operation,
        string $startedAtRaw,
        int $afterEventId,
        array $correlation = []
    ): array {
        $startedAtTs = $startedAtRaw !== '' ? (\strtotime($startedAtRaw) ?: 0) : 0;
        $filtered = [];
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $eventId = (int)($event['event_id'] ?? 0);
            if ($eventId <= $afterEventId) {
                continue;
            }
            if (!$this->isOperationEventRelevant($event, $operation, $startedAtTs, $correlation)) {
                continue;
            }
            $filtered[] = $event;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $correlation
     */
    private function isOperationEventCorrelationRelevant(array $event, array $correlation): bool
    {
        if ($correlation === []) {
            return true;
        }

        $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $details = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $eventToken = $this->firstNonEmptyString($payload, $details, ['execution_token', 'token']);
        $expectedToken = \trim((string)($correlation['execution_token'] ?? $correlation['token'] ?? ''));
        if ($eventToken !== '' && $expectedToken !== '' && !$this->executionTokensMatch($eventToken, $expectedToken)) {
            return false;
        }

        $eventQueueId = $this->firstPositiveInt($payload, $details, ['queue_id']);
        $expectedQueueId = (int)($correlation['queue_id'] ?? 0);
        if ($eventQueueId > 0 && $expectedQueueId > 0 && $eventQueueId !== $expectedQueueId) {
            return false;
        }

        $eventJobKey = $this->firstNonEmptyString($payload, $details, ['job_key']);
        $expectedJobKey = \trim((string)($correlation['job_key'] ?? ''));
        if ($eventJobKey !== '' && $expectedJobKey !== '' && $eventJobKey !== $expectedJobKey) {
            return false;
        }

        $eventJobType = $this->firstNonEmptyString($payload, $details, ['job_type']);
        $expectedJobType = \trim((string)($correlation['job_type'] ?? ''));
        if ($eventJobType !== '' && $expectedJobType !== '' && $eventJobType !== $expectedJobType) {
            return false;
        }

        $hasEventCorrelation = $eventToken !== '' || $eventQueueId > 0 || $eventJobKey !== '' || $eventJobType !== '';
        $eventType = \trim((string)($event['event_type'] ?? ''));
        if ((bool)($correlation['require_event_correlation'] ?? false)
            && !$hasEventCorrelation
            && \in_array($eventType, ['progress', 'chunk', 'operation_progress', 'ai_raw_chunk', 'plan_chunk', 'ai_chunk', 'operation_failed', 'error'], true)) {
            return false;
        }
        if ((bool)($correlation['require_error_correlation'] ?? false)
            && \in_array($eventType, ['error', 'operation_failed'], true)) {
            if ($expectedToken !== '' && $eventToken === '') {
                return false;
            }

            return $hasEventCorrelation;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $details
     * @param list<string> $keys
     */
    private function firstNonEmptyString(array $payload, array $details, array $keys): string
    {
        foreach ($keys as $key) {
            $value = \trim((string)($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        foreach ($keys as $key) {
            $value = \trim((string)($details[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $details
     * @param list<string> $keys
     */
    private function firstPositiveInt(array $payload, array $details, array $keys): int
    {
        foreach ($keys as $key) {
            $value = (int)($payload[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
        foreach ($keys as $key) {
            $value = (int)($details[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private function executionTokensMatch(string $actualToken, string $expectedToken): bool
    {
        $actualToken = \trim($actualToken);
        $expectedToken = \trim($expectedToken);
        if ($actualToken === '' || $expectedToken === '') {
            return false;
        }
        if ($actualToken === $expectedToken) {
            return true;
        }

        $actualBase = \explode('-force-', $actualToken, 2)[0] ?? $actualToken;
        $expectedBase = \explode('-force-', $expectedToken, 2)[0] ?? $expectedToken;

        return $actualBase !== '' && $actualBase === $expectedBase;
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function resolveResultLogForPanel(array $queueRow): string
    {
        $structured = $this->firstNonEmptyQueueText($queueRow, ['message', 'terminal_summary']);
        if ($structured !== '') {
            return $this->trimQueueResultCompatibilityTail($structured);
        }

        return $this->trimQueueResultCompatibilityTail((string)($queueRow['result'] ?? ''));
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function resolveLegacyResultMessage(array $queueRow): string
    {
        $result = $this->trimQueueResultCompatibilityTail((string)($queueRow['result'] ?? ''));
        if ($result === '') {
            return '';
        }

        $lines = $this->extractNonEmptyTailLines($result, 0, self::QUEUE_RESULT_TAIL_LINES);
        if ($lines === []) {
            return '';
        }

        return (string)\end($lines);
    }

    /**
     * @param array<string, mixed> $queueRow
     * @param list<string> $keys
     */
    private function firstNonEmptyQueueText(array $queueRow, array $keys): string
    {
        foreach ($keys as $key) {
            $value = \trim((string)($queueRow[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function trimQueueResultCompatibilityTail(string $result): string
    {
        $result = \trim($result);
        if ($result === '') {
            return '';
        }

        if (\strlen($result) <= self::QUEUE_RESULT_TAIL_BYTES) {
            return $result;
        }

        return (string)__('...(仅显示末尾约 %{n} 字节)', ['n' => (string)self::QUEUE_RESULT_TAIL_BYTES])
            . "\n"
            . $this->strcutTail($result, self::QUEUE_RESULT_TAIL_BYTES);
    }

    /**
     * @return list<string>
     */
    private function extractNonEmptyTailLines(
        string $text,
        int $maxBytes = self::QUEUE_RESULT_TAIL_BYTES,
        int $maxLines = self::QUEUE_RESULT_TAIL_LINES
    ): array {
        $text = \trim($text);
        if ($text === '') {
            return [];
        }
        if ($maxBytes > 0 && \strlen($text) > $maxBytes) {
            $text = \substr($text, -$maxBytes);
            $firstLineBreak = \strpos($text, "\n");
            if ($firstLineBreak !== false) {
                $text = \substr($text, $firstLineBreak + 1);
            }
        }
        $text = \str_replace(["\r\n", "\r"], "\n", $text);
        $lines = [];
        foreach (\explode("\n", $text) as $line) {
            $line = \trim((string)$line);
            if ($line === '') {
                continue;
            }
            $lines[] = $line;
            if ($maxLines > 0 && \count($lines) > $maxLines) {
                \array_shift($lines);
            }
        }

        return $lines;
    }

    private function isQueueStreamOmittedMessage(string $message): bool
    {
        $message = \trim($message);
        if ($message === '') {
            return false;
        }

        return \str_contains($message, 'AI body stream is intentionally omitted from queue logs')
            || \str_contains($message, '正文流不写入队列日志')
            || \str_contains($message, '正文流已从队列 SSE 中省略');
    }

    private function strcutTail(string $text, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (\strlen($text) <= $maxBytes) {
            return $text;
        }
        if (\function_exists('mb_strcut')) {
            return (string)\mb_strcut($text, -$maxBytes, null, 'UTF-8');
        }

        $tail = (string)\substr($text, -$maxBytes);
        while ($tail !== '' && !\preg_match('//u', $tail)) {
            $tail = (string)\substr($tail, 1);
        }

        return $tail;
    }

}
