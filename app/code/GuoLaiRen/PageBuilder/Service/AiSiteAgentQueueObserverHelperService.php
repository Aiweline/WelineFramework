<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * AiSiteAgentQueueObserverHelperService
 *
 * 浠?AiSiteAgent.php 鎶藉嚭鐨?**闃熷垪瑙傚療瀛愬煙绾緟鍔╂柟娉?*銆傛湰杞彧杩佺Щ涓変釜鏃?
 * Session / SSE / DB 渚濊禆鐨勫垽瀹?褰掍竴鍖栧嚱鏁帮細
 *  - 鍒ゆ柇鏌?operation 鏄惁搴旀姂鍒?queue process 闀滃儚涓嬪彂锛?
 *  - 鍒ゆ柇鏄惁璺宠繃涓€琛?queue.result 鏃ュ織锛堝惈鏃堕棿鎴?+ 浜嬩欢绫诲瀷鍓嶇紑鐨勫洖鏀捐锛夛紱
 *  - 灏?queueRow + 鎴愬姛鏍囧織瑙ｆ瀽鎴?缁撴潫鏂囨"(process / result 灏捐 / i18n 鍏滃簳)銆?
 *
 * 鎶藉嚭鍔ㄦ満锛?
 *  - 绾嚱鏁般€佹棤鍓綔鐢紝涓庢帶鍒跺櫒/浼氳瘽瑙ｈ€︽垚鏈渶浣庯紝鍙崟鍏冩祴璇曢攣瀹氾紱
 *  - 浣滀负 R4.2 SOLID 鎷嗗垎鐨勭浜屽潡"瀹夊叏鏍锋湰"锛岃寖寮忔部鐢?AiSiteQueueStateService锛?
 *  - 璁╀笅涓€杞娊 `buildQueueObserverPanelPayload / emitQueueObserverQueueDetailEvents`
 *    绛変緷璧?SSE 鐘舵€佺殑鏂规硶鏃讹紝鍙互鐩存帴澶嶇敤杩欓噷鐨勫垽瀹氶€昏緫锛岄伩鍏嶈法灞傚鍒躲€?
 *
 * 閲嶈锛氭柟娉曠鍚嶃€佽緭鍏ヨ緭鍑?shape 蹇呴』涓?AiSiteAgent.php 鍘熺鏈夋柟娉曚竴鑷达紝
 * 浠ヤ究鍓嶇/SSE 閾捐矾鍚戝悗鍏煎锛涜皟鏁存椂鍚屾鏇存柊 `AiSiteAgentQueueObserverHelperServiceTest`銆?
 */
class AiSiteAgentQueueObserverHelperService
{
    private const QUEUE_RESULT_TAIL_BYTES = 4096;
    private const QUEUE_RESULT_TAIL_LINES = 50;

    /**
     * 瀵?plan 杩欑被"鑷甫 SSE 浜嬩欢娴?鐨?operation锛屾姂鍒?queue process 闀滃儚杞彂锛?
     * 鍏跺畠 operation 璧伴粯璁?process 闀滃儚銆備繚鎸佷笌鍘?AiSiteAgent 绉佹湁鏂规硶璇箟涓€鑷淬€?
     */
    public function shouldSuppressProcessMirror(string $operation): bool
    {
        return false;
    }

    /**
     * 鍒ゆ柇涓€琛?queue.result 鏄惁闇€瑕佽烦杩囷細浠呭 plan 涓ょ被 operation 鐢熸晥锛?
     * 鐢ㄤ簬鍓旈櫎"宸茬敱 SSE 浜嬩欢娴佽鐩?鐨勬椂闂存埑鏃ュ織琛岋紝閬垮厤鍥炴斁閲嶅涓婂睆銆?
     * 鍖归厤绀轰緥锛歚[HH:MM:SS] LOG|START|INFO|WARNING|PROGRESS|ERROR|DATA|AI_STREAM|PLAN_xxx ...`
     */
    public function shouldSkipResultLine(string $operation, string $line): bool
    {
        if (
            \str_contains($line, 'stream body omitted from queue log')
            || \str_contains($line, 'stream body omitted from queue SSE')
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

        return $line !== '';
    }

    /**
     * 鏍规嵁 queueRow 瑙ｆ瀽"缁撴潫鏂囨"锛?
     *  1) 浼樺厛浣跨敤缁撴瀯鍖?`process` / `message` / `terminal_summary` 瀛楁锛?     *  2) 鍘嗗彶 `result` 浠呬綔涓哄厛鎸夊瓧鑺傝鍓悗鐨勫吋瀹瑰熬閮ㄦ憳瑕侊紱
     *  3) 鏈€缁堝厹搴?i18n 鏂囨锛堟垚鍔?/ 澶辫触锛夈€?     *
     * @param array<string, mixed>|null $queueRow `weline_queue` 涓€琛屽師濮嬫暟鎹紱null 鐩存帴璧?i18n 鍏滃簳
     */
    public function resolveMessage(?array $queueRow, bool $success): string
    {
        if (\is_array($queueRow)) {
            $message = $this->firstNonEmptyQueueText($queueRow, ['process', 'message', 'terminal_summary']);
            if ($message !== '' && ($success || !$this->isQueueStreamOmittedMessage($message))) {
                return $message;
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
            'status' => (string)($currentState['queue_status'] ?? ''),
            'queue_status' => (string)($currentState['queue_status'] ?? ''),
            'semantic_status' => (string)($currentState['semantic_status'] ?? ''),
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
     * 灏嗗簳灞傞槦鍒椾簨浠剁被鍨嬪綊涓€鍖栨垚鍓嶇 SSE 鐩戝惉鐨勪簨浠跺悕銆傛湭鐭ョ被鍨嬭繑鍥炵┖涓诧紝
     * 璋冪敤鏂规嵁姝ゅ喅瀹氭槸鍚﹁烦杩囷紙涓庡師 `AiSiteAgent::mapObservedOperationEventName` 瀵归綈锛夈€?
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
            'plan_json_block_completed' => 'plan_json_block_completed',
            'task_completed' => 'task_completed',
            // Plan-json block lifecycle events are forwarded with the same public SSE names.
            'plan_json_block_failed' => 'plan_json_block_failed',
            'operation_failed' => 'error',
            default => '',
        };
    }

    /**
     * 鍒ゆ柇鏌愭潯 queue event 鏄惁涓庡綋鍓?operation / 璧峰鏃堕棿鍖归厤锛屼緵 SSE 杞彂閾捐矾杩囨护銆?
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
            'plan_json_block_completed',
            'task_completed',
            // plan_json_block_failed must be forwardable by forwardObservedOperationEvents.
            // 鍚﹀垯 mapOperationEventName 鍗充究鏈夋槧灏勪篃浼氳 isOperationEventRelevant 鐨勭櫧鍚嶅崟鎸℃帀銆?
            'plan_json_block_failed',
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
     * 杩囨护 queue event 鍒楄〃锛氫涪寮?event_id 灏忎簬绛変簬 afterEventId銆佷笌褰撳墠 operation
     * 鏃犲叧銆佹垨鏃╀簬 startedAtRaw 鐨勬潯鐩€備繚鎸佷笌鍘熸帶鍒跺櫒绉佹湁鏂规硶璇箟涓€鑷淬€?
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
            || \str_contains($message, 'stream body omitted from queue log')
            || \str_contains($message, 'stream body omitted from queue SSE');
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
