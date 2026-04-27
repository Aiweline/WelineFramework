<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverHelperService;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the freshly extracted
 * `AiSiteAgentQueueObserverHelperService`.
 *
 * These tests lock the observable behavior of 7 pure helper functions that were
 * previously private methods on `AiSiteAgent`:
 *  - shouldSuppressProcessMirror
 *  - shouldSkipResultLine
 *  - resolveMessage
 *  - buildPanelPayload
 *  - mapOperationEventName
 *  - isOperationEventRelevant
 *  - filterOperationEvents
 *
 * 目的：保证继续抽 panel / SSE 层方法时，本次"下层判定"依然严格对齐
 * 现有 SSE 回放 / 日志过滤 / 结束文案兜底 / panel payload 组装的行为
 * （前端与回放流水线的隐式契约）。
 */
final class AiSiteAgentQueueObserverHelperServiceTest extends TestCase
{
    public function testShouldSuppressProcessMirrorCoversStreamOwningOperations(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        self::assertTrue($service->shouldSuppressProcessMirror('plan'));
        self::assertFalse($service->shouldSuppressProcessMirror('task_plan'));
        self::assertFalse($service->shouldSuppressProcessMirror('build'));
        self::assertFalse($service->shouldSuppressProcessMirror(''));
        self::assertFalse($service->shouldSuppressProcessMirror('PLAN'), 'operation 比对是大小写敏感的，不能因为大写误命中');
    }

    public function testShouldSkipResultLineOnlyTargetsStreamOwningOperations(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        // 非 plan / task_plan 的 operation：任何 line 都不跳过。
        self::assertFalse($service->shouldSkipResultLine('build', '[12:34:56] LOG hello'));
        self::assertFalse($service->shouldSkipResultLine('', '[12:34:56] INFO hi'));

        // plan：带时间戳 + 预期事件类型前缀的回放日志需跳过。
        self::assertTrue($service->shouldSkipResultLine('plan', '[12:34:56] LOG hello'));
        self::assertTrue($service->shouldSkipResultLine('plan', '[00:00:01] PLAN_SAVED ok'));
        self::assertFalse($service->shouldSkipResultLine('task_plan', '[23:59:59] TASK_PLAN_BUILT done'));
        self::assertTrue($service->shouldSkipResultLine('plan', '[12:00:00] AI_STREAM chunk'));
        self::assertFalse($service->shouldSkipResultLine('task_plan', 'free text no prefix'));

        // plan：旧版本可能把正文裸写成 markdown/json 行；规划类队列不再回放任何 result 正文。
        self::assertTrue($service->shouldSkipResultLine('plan', 'free text no prefix'));
        self::assertFalse($service->shouldSkipResultLine('plan', ''));
        self::assertTrue(
            $service->shouldSkipResultLine('plan', '[12:34:56] UNKNOWN something'),
            '规划类队列 result 不再作为正文流回放'
        );
    }

    public function testResolveMessagePrefersProcessThenResultTailThenI18nFallback(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        // 1) process 非空 → 直接返回 process。
        self::assertSame(
            '正在生成首页组件',
            $service->resolveMessage(['process' => '  正在生成首页组件  '], true)
        );

        // 2) process 为空但 result 非空 → 取最后一个非空行。
        $resolved = $service->resolveMessage([
            'process' => '',
            'result' => "line-1\n\n  line-2  \nline-3\n",
        ], false);
        self::assertSame('line-3', $resolved);

        // 3) result 全空白 → 走 i18n 兜底：成功 / 失败分支均为非空字符串。
        $successFallback = $service->resolveMessage([
            'process' => '',
            'result' => "  \n\n",
        ], true);
        $failureFallback = $service->resolveMessage([
            'process' => '',
            'result' => '',
        ], false);
        self::assertNotSame('', $successFallback, 'success 分支必须返回非空文案');
        self::assertNotSame('', $failureFallback, 'failure 分支必须返回非空文案');
        self::assertNotSame($successFallback, $failureFallback, '成功与失败文案不应相同');

        // 4) queueRow 为 null → 等价走 i18n 兜底。
        self::assertSame($successFallback, $service->resolveMessage(null, true));
        self::assertSame($failureFallback, $service->resolveMessage(null, false));
    }

    public function testResolveMessagePrefersResultTailForTerminalQueueStatuses(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $resolved = $service->resolveMessage([
            'status' => 'error',
            'process' => 'AI 正在生成内容，正文流不写入队列日志',
            'result' => "[02:33:05] ERROR 阶段一方案生成失败\n第一阶段方案生成失败：AI流式生成完成但未返回任何内容",
        ], false);

        self::assertSame('第一阶段方案生成失败：AI流式生成完成但未返回任何内容', $resolved);
    }

    public function testResolveMessagePrefersErrorLineOverTerminalAiProgressTail(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $resolved = $service->resolveMessage([
            'status' => 'error',
            'process' => 'AI 正在生成内容，正文流不写入队列日志',
            'content' => \json_encode(['operation' => 'plan'], \JSON_THROW_ON_ERROR),
            'result' => "[02:31:58] PROGRESS 需求扩写已完成，正在生成总主题设计\n"
                . "[02:33:05] ERROR 第一阶段方案生成失败：AI流式生成失败: AI 流式生成完成但未返回任何内容\n"
                . "[02:33:05] AI_PROGRESS AI 正在生成内容，正文流不写入队列日志（已接收 446 段 / 1965 B）。",
        ], false);

        self::assertSame('第一阶段方案生成失败：AI流式生成失败: AI 流式生成完成但未返回任何内容', $resolved);
    }

    public function testBuildPanelPayloadShapesQueueRowIntoPublicStructure(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $snapshot = ['queue_id' => 4321, 'status' => 'running'];
        $payload = $service->buildPanelPayload(
            [
                'process' => '  正在执行  ',
                'result' => "line-a\nline-b",
            ],
            $snapshot
        );

        self::assertSame(
            ['queue_id', 'snapshot', 'process', 'result_log'],
            \array_keys($payload),
            'panel payload 必须严格包含四个字段且顺序稳定（前端依赖）'
        );
        self::assertSame(4321, $payload['queue_id']);
        self::assertSame($snapshot, $payload['snapshot']);
        self::assertSame('正在执行', $payload['process']);
        self::assertSame("line-a\nline-b", $payload['result_log']);

        // 超长 result_log 必须截断到末尾 24000 字符，并在开头附 i18n 提示。
        $longResult = \str_repeat('A', 24005);
        $truncated = $service->buildPanelPayload(
            ['process' => '', 'result' => $longResult],
            ['queue_id' => 1],
        );
        $resultLog = (string)$truncated['result_log'];
        $tail = \substr($longResult, -24000);
        self::assertStringEndsWith($tail, $resultLog, '截断后必须是末尾 24000 字符');
        self::assertStringContainsString('24000', $resultLog, '截断提示里应带上 24000 字符数');
        self::assertStringStartsNotWith($tail, $resultLog, '截断时开头应是 i18n 提示而非原始内容');

        // queue_id 缺失 → 回退为 0，保持数值型。
        $emptySnap = $service->buildPanelPayload(['process' => '', 'result' => ''], []);
        self::assertSame(0, $emptySnap['queue_id']);
        self::assertSame('', $emptySnap['process']);
        self::assertSame('', $emptySnap['result_log']);
    }

    public function testMapOperationEventNameCoversAllKnownTypes(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        self::assertSame('start', $service->mapOperationEventName('start'));
        self::assertSame('start', $service->mapOperationEventName('operation_started'));
        self::assertSame('progress', $service->mapOperationEventName('ai_raw_chunk'));
        self::assertSame('info', $service->mapOperationEventName('plan_refined'));
        self::assertSame('error', $service->mapOperationEventName('operation_failed'));
        self::assertSame('task_completed', $service->mapOperationEventName('task_completed'));

        self::assertSame('', $service->mapOperationEventName('unknown'));
        self::assertSame('', $service->mapOperationEventName(''));
    }

    public function testIsOperationEventRelevantFiltersByTypeAndOperation(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        // type 不在白名单 → false。
        self::assertFalse($service->isOperationEventRelevant(
            ['event_type' => 'heartbeat', 'payload' => ['operation' => 'plan']],
            'plan',
            0
        ));

        // payload.operation 不匹配 → false。
        self::assertFalse($service->isOperationEventRelevant(
            ['event_type' => 'info', 'payload' => ['operation' => 'task_plan']],
            'plan',
            0
        ));

        // startedAtTs <= 0 → 不再按时间过滤，白名单 + operation 匹配即 true。
        self::assertTrue($service->isOperationEventRelevant(
            ['event_type' => 'info', 'payload' => ['operation' => 'plan']],
            'plan',
            0
        ));

        // event.create_time 早于 startedAtTs → false。
        $startedAt = \strtotime('2026-01-01 10:00:00');
        self::assertIsInt($startedAt);
        self::assertFalse($service->isOperationEventRelevant(
            [
                'event_type' => 'info',
                'payload' => ['operation' => 'plan'],
                'create_time' => '2026-01-01 09:59:59',
            ],
            'plan',
            (int)$startedAt
        ));

        // create_time >= startedAt → true。
        self::assertTrue($service->isOperationEventRelevant(
            [
                'event_type' => 'info',
                'payload' => ['operation' => 'plan'],
                'create_time' => '2026-01-01 10:00:00',
            ],
            'plan',
            (int)$startedAt
        ));
    }

    public function testFilterOperationEventsRespectsAfterEventIdAndOperation(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $events = [
            [
                'event_id' => 10,
                'event_type' => 'info',
                'payload' => ['operation' => 'plan'],
                'create_time' => '',
            ],
            [
                'event_id' => 20,
                'event_type' => 'info',
                'payload' => ['operation' => 'task_plan'],
                'create_time' => '',
            ],
            [
                'event_id' => 30,
                'event_type' => 'info',
                'payload' => ['operation' => 'plan'],
                'create_time' => '',
            ],
        ];

        $filtered = $service->filterOperationEvents($events, 'plan', '', 15);

        self::assertCount(1, $filtered, '只应保留 operation=plan 且 event_id>15 的唯一一条');
        self::assertSame(30, $filtered[0]['event_id']);
    }

    public function testFilterOperationEventsRejectsMismatchedCorrelation(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $events = [
            [
                'event_id' => 31,
                'event_type' => 'progress',
                'payload' => ['operation' => 'plan', 'execution_token' => 'old-token', 'queue_id' => 10],
                'create_time' => '',
            ],
            [
                'event_id' => 32,
                'event_type' => 'progress',
                'payload' => ['operation' => 'plan', 'execution_token' => 'new-token', 'queue_id' => 20],
                'create_time' => '',
            ],
        ];

        $filtered = $service->filterOperationEvents($events, 'plan', '', 0, [
            'execution_token' => 'new-token',
            'queue_id' => 20,
        ]);

        self::assertCount(1, $filtered);
        self::assertSame(32, $filtered[0]['event_id']);
    }

    public function testFilterOperationEventsSuppressesUncorrelatedErrorWhenQueueContextExists(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $events = [
            [
                'event_id' => 41,
                'event_type' => 'operation_failed',
                'payload' => ['operation' => 'plan'],
                'create_time' => '',
            ],
            [
                'event_id' => 42,
                'event_type' => 'operation_failed',
                'payload' => ['operation' => 'plan', 'execution_token' => 'new-token', 'queue_id' => 20],
                'create_time' => '',
            ],
        ];

        $filtered = $service->filterOperationEvents($events, 'plan', '', 0, [
            'execution_token' => 'new-token',
            'queue_id' => 20,
            'require_error_correlation' => true,
        ]);

        self::assertCount(1, $filtered);
        self::assertSame(42, $filtered[0]['event_id']);
    }

    public function testFilterOperationEventsSuppressesUncorrelatedProgressWhenStrictCorrelationEnabled(): void
    {
        $service = new AiSiteAgentQueueObserverHelperService();

        $events = [
            [
                'event_id' => 51,
                'event_type' => 'operation_progress',
                'payload' => ['operation' => 'plan', 'message' => 'legacy event without queue linkage'],
                'create_time' => '',
            ],
            [
                'event_id' => 52,
                'event_type' => 'operation_progress',
                'payload' => ['operation' => 'plan', 'execution_token' => 'new-token', 'queue_id' => 20],
                'create_time' => '',
            ],
        ];

        $filtered = $service->filterOperationEvents($events, 'plan', '', 0, [
            'execution_token' => 'new-token',
            'queue_id' => 20,
            'require_event_correlation' => true,
        ]);

        self::assertCount(1, $filtered);
        self::assertSame(52, $filtered[0]['event_id']);
    }
}
