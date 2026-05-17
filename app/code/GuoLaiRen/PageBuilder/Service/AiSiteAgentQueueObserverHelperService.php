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
 *  - 作为 R4.2 SOLID 拆分的第二块"安全样本"，范式沿用 AiSiteQueueSnapshotService；
 *  - 让下一轮抽 `buildQueueObserverPanelPayload / emitQueueObserverQueueDetailEvents`
 *    等依赖 SSE 状态的方法时，可以直接复用这里的判定逻辑，避免跨层复制。
 *
 * 重要：方法签名、输入输出 shape 必须与 AiSiteAgent.php 原私有方法一致，
 * 以便前端/SSE 链路向后兼容；调整时同步更新 `AiSiteAgentQueueObserverHelperServiceTest`。
 */
class AiSiteAgentQueueObserverHelperService
{
    private const MAX_CONTENT_JSON_DECODE_BYTES = 262144;

    /**
     * 对 plan 这类"自带 SSE 事件流"的 operation，抑制 queue process 镜像转发；
     * 其它 operation 走默认 process 镜像。保持与原 AiSiteAgent 私有方法语义一致。
     */
    public function shouldSuppressProcessMirror(string $operation): bool
    {
        return $operation === 'plan';
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
     *  1) 优先使用 `process` 字段（非空）；
     *  2) 否则取 `result` 最后一个非空行；
     *  3) 最终兜底 i18n 文案（成功 / 失败）。
     *
     * @param array<string, mixed>|null $queueRow `weline_queue` 一行原始数据；null 直接走 i18n 兜底
     */
    public function resolveMessage(?array $queueRow, bool $success): string
    {
        if (\is_array($queueRow)) {
            $status = \trim((string)($queueRow['status'] ?? ''));
            $process = \trim((string)($queueRow['process'] ?? ''));
            $result = \trim($this->sanitizePlanningQueueResultLog($queueRow));
            if ($result !== '') {
                $lines = \preg_split("/\\r\\n|\\n|\\r/", $result) ?: [];
                $lines = \array_values(\array_filter(\array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
                if ($lines !== []) {
                    $resultTail = (string)\end($lines);
                    if (\in_array($status, ['done', 'error', 'stop', 'cancelled'], true)) {
                        if ($status === 'error') {
                            for ($i = \count($lines) - 1; $i >= 0; $i--) {
                                $line = (string)$lines[$i];
                                if ($this->isTerminalErrorMessageLine($line)) {
                                    return $this->normalizeTerminalErrorMessageLine($line);
                                }
                            }
                        }
                        return $resultTail;
                    }
                }
            }
            if ($process !== '') {
                return $process;
            }
            if ($result !== '') {
                $lines = \preg_split("/\\r\\n|\\n|\\r/", $result) ?: [];
                $lines = \array_values(\array_filter(\array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
                if ($lines !== []) {
                    return (string)\end($lines);
                }
            }
        }

        return $success ? (string)__('操作执行完成') : (string)__('操作执行失败');
    }

    /**
     * 将 queueRow + 已计算好的 public snapshot 组装成前端 panel payload。
     *
     * 约定：调用方（控制器）先通过 `AiSiteQueueSnapshotService` 计算 snapshot，
     * 再把 snapshot 传进来；这样 Helper 不用引入新依赖。
     *
     * 截断规则：`result_log` 超过 24000 字符时，仅保留末尾 24000 字符，并在开头
     * 附 i18n 提示；保持与原 `AiSiteAgent::buildQueueObserverPanelPayload` 一致。
     *
     * @param array<string, mixed> $queueRow
     * @param array<string, mixed> $snapshot
     *
     * @return array{queue_id:int,snapshot:array<string,mixed>,process:string,result_log:string}
     */
    public function buildPanelPayload(array $queueRow, array $snapshot): array
    {
        $process = \trim((string)($queueRow['process'] ?? ''));
        $processMax = 4000;
        if (\strlen($process) > $processMax) {
            $process = '...(showing last ' . $processMax . ' chars)' . "\n" . \substr($process, -$processMax);
        }
        $result = $this->sanitizePlanningQueueResultLog($queueRow);
        $max = 24000;
        if (\strlen($result) > $max) {
            $result = (string)__('…（以下仅显示末尾约 %{n} 字符）', ['n' => (string)$max]) . "\n" . \substr($result, -$max);
        }

        return [
            'queue_id' => (int)($snapshot['queue_id'] ?? 0),
            'status' => (string)($snapshot['status'] ?? ''),
            'queue_status' => (string)($snapshot['status'] ?? ''),
            'job_status' => (string)($snapshot['job_status'] ?? ''),
            'snapshot' => $snapshot,
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
            'task_completed' => 'task_completed',
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
            'task_completed',
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
    private function sanitizePlanningQueueResultLog(array $queueRow): string
    {
        $result = (string)($queueRow['result'] ?? '');
        $operation = $this->resolveQueueRowOperation($queueRow);
        if ($operation !== 'plan' || \trim($result) === '') {
            return $result;
        }

        $kept = [];
        $suppressed = 0;
        $lines = \preg_split("/\\r\\n|\\n|\\r/", $result) ?: [];
        foreach ($lines as $line) {
            $line = \trim((string)$line);
            if ($line === '') {
                continue;
            }
            if ($this->isSafePlanningQueueLogLine($line)) {
                $kept[] = $line;
            } else {
                $suppressed++;
            }
        }
        if ($suppressed > 0) {
            \array_unshift($kept, (string)__('已省略 %{n} 行 AI 正文输出。', ['n' => (string)$suppressed]));
        }

        return \implode("\n", $kept);
    }

    private function isSafePlanningQueueLogLine(string $line): bool
    {
        if ((bool)\preg_match(
            '/^\[\d{2}:\d{2}:\d{2}\]\s+(?:START|INFO|WARNING|PROGRESS|ERROR|AI_PROGRESS|TOKEN_USAGE)\b/u',
            $line
        )) {
            return true;
        }

        return \str_contains($line, '正文流不写入队列日志')
            || \str_contains($line, '正文流已从队列 SSE 中省略');
    }

    private function isTerminalErrorMessageLine(string $line): bool
    {
        return (bool)\preg_match('/^\[\d{2}:\d{2}:\d{2}\]\s+(?:ERROR|WARNING)\b/u', $line)
            || \str_contains($line, '失败')
            || \str_contains($line, '异常')
            || \stripos($line, 'error') !== false
            || \stripos($line, 'failed') !== false
            || \stripos($line, 'exception') !== false;
    }

    private function normalizeTerminalErrorMessageLine(string $line): string
    {
        $normalized = \preg_replace('/^\[\d{2}:\d{2}:\d{2}\]\s+(?:ERROR|WARNING)\s+/u', '', $line);
        return \trim(\is_string($normalized) ? $normalized : $line);
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function resolveQueueRowOperation(array $queueRow): string
    {
        $operationFromBizKey = $this->resolveQueueRowOperationFromBizKey($queueRow);
        if ($operationFromBizKey !== '') {
            return $operationFromBizKey;
        }
        $content = $queueRow['content'] ?? null;
        if (\is_string($content) && \trim($content) !== '') {
            if (\strlen($content) > self::MAX_CONTENT_JSON_DECODE_BYTES) {
                return $this->normalizeQueueOperationHint(
                    $this->extractJsonStringValue($content, 'operation')
                    ?: $this->extractJsonStringValue($content, 'job_type')
                );
            }
            $decoded = \json_decode($content, true);
            if (\is_array($decoded)) {
                return $this->normalizeQueueOperationHint(\trim((string)($decoded['operation'] ?? $decoded['job_type'] ?? '')));
            }
        }
        if (\is_array($content)) {
            return $this->normalizeQueueOperationHint(\trim((string)($content['operation'] ?? $content['job_type'] ?? '')));
        }

        return '';
    }

    private function resolveQueueRowOperationFromBizKey(array $queueRow): string
    {
        $bizKey = \trim((string)($queueRow['biz_key'] ?? ''));
        if ($bizKey === '') {
            return '';
        }
        if (\preg_match('/(?:^|:)queue_slot:([^:]+)/', $bizKey, $slotMatch) === 1) {
            $slot = \trim((string)($slotMatch[1] ?? ''));
            return $slot === 'planning' ? 'plan' : $slot;
        }
        if (\preg_match('/(?:^|:)operation:([^:]+)/', $bizKey, $operationMatch) === 1) {
            return \trim((string)($operationMatch[1] ?? ''));
        }

        return '';
    }

    private function extractJsonStringValue(string $json, string $key): string
    {
        if (\preg_match('/"' . \preg_quote($key, '/') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $json, $match) !== 1) {
            return '';
        }
        $decoded = \json_decode('"' . $match[1] . '"');

        return \is_string($decoded) ? \trim($decoded) : '';
    }

    private function normalizeQueueOperationHint(string $value): string
    {
        if ($value === 'plan' || \str_starts_with($value, 'stage1.')) {
            return 'plan';
        }
        return $value;
    }
}
