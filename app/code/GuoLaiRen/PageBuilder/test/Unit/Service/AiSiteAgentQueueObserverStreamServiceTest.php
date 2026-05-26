<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverStreamService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueSnapshotService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * 内部 Spy：记录 SseWriter 的 sendEvent / isAlive 调用以锁定事件序列与 payload。
 *
 * 绕过父类构造（SSE 真实写入依赖 Runtime / Env），通过 override 保持测试侧 100% 纯。
 */
final class SpySseWriterForQueueObserverStream extends SseWriter
{
    /**
     * @var list<array{event:string,data:mixed,id:?int}>
     */
    public array $calls = [];

    public bool $aliveFlag;

    public function __construct(bool $alive = true)
    {
        $this->aliveFlag = $alive;
    }

    public function isAlive(): bool
    {
        return $this->aliveFlag;
    }

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        $this->calls[] = ['event' => $event, 'data' => $data, 'id' => $id];
        return $this;
    }
}

final class AiSiteAgentQueueObserverStreamServiceTest extends TestCase
{
    private function service(): AiSiteAgentQueueObserverStreamService
    {
        return new AiSiteAgentQueueObserverStreamService(
            new AiSiteQueueSnapshotService(),
            new AiSiteAgentQueueObserverHelperService()
        );
    }
    // ------------------------------------------------------------------
    // reconcileActiveOperationWithQueueInfo
    // ------------------------------------------------------------------

    public function testReconcileReturnsOriginalWhenOperationMismatchesOrQueueSnapshotMissing(): void
    {
        $service = $this->service();

        $active = [
            'operation' => 'plan',
            'status' => 'running',
            'queue_waiting_for_scheduler' => true,
            'can_close_stream' => true,
            'continue_other_operations' => true,
        ];

        self::assertSame(
            $active,
            $service->reconcileActiveOperationWithQueueInfo($active, ['snapshot' => ['status' => 'done']], 'task_plan'),
            'operation 不匹配应原样返回'
        );

        self::assertSame(
            $active,
            $service->reconcileActiveOperationWithQueueInfo($active, null, 'plan'),
            'queueInfo null 应原样返回'
        );

        $notActive = ['operation' => 'plan', 'status' => 'done'];
        self::assertSame(
            $notActive,
            $service->reconcileActiveOperationWithQueueInfo($notActive, ['snapshot' => ['status' => 'error']], 'plan'),
            'active 非 queued/running 应原样返回'
        );

        self::assertSame(
            $active,
            $service->reconcileActiveOperationWithQueueInfo($active, ['snapshot' => 'not-an-array'], 'plan'),
            'snapshot 非数组应原样返回'
        );
    }

    public function testReconcileMapsErrorStatusToErrorWithProcessFallbackChain(): void
    {
        $service = $this->service();
        $active = ['operation' => 'plan', 'status' => 'running'];

        $withProcess = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            [
                'snapshot' => ['status' => 'error'],
                'process' => 'AI 拒绝请求：超过配额',
                'result_log' => '',
            ],
            'plan'
        );
        self::assertSame('error', $withProcess['status']);
        self::assertSame('AI 拒绝请求：超过配额', $withProcess['message'], 'process 非空时优先');
        self::assertFalse((bool)$withProcess['queue_waiting_for_scheduler']);
        self::assertFalse((bool)$withProcess['can_close_stream']);
        self::assertFalse((bool)$withProcess['continue_other_operations']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$withProcess['updated_at']);

        $withResultTail = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            [
                'queue_id' => 553,
                'snapshot' => ['queue_id' => 553, 'status' => 'error'],
                'process' => 'AI body stream is intentionally omitted from queue logs',
                'result_log' => "line one\nstage-one terminal failure detail",
            ],
            'plan'
        );
        self::assertSame('error', $withResultTail['status']);
        self::assertSame(553, $withResultTail['queue_id']);
        self::assertSame('stage-one terminal failure detail', $withResultTail['message']);

        $withOnlyResult = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            [
                'snapshot' => ['status' => 'error'],
                'process' => '',
                'result_log' => 'stack trace ...',
            ],
            'plan'
        );
        self::assertSame('error', $withOnlyResult['status']);
        self::assertNotSame('', (string)$withOnlyResult['message'], 'result_log 非空时使用 i18n 兜底文案');

        $withNothing = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            ['snapshot' => ['status' => 'error']],
            'plan'
        );
        self::assertSame('error', $withNothing['status']);
        self::assertNotSame('', (string)$withNothing['message'], '兜底 i18n 文案应非空');
    }

    public function testReconcileMapsInProgressQueueStatusOverStaleError(): void
    {
        $service = $this->service();

        $running = $service->reconcileActiveOperationWithQueueInfo(
            [
                'operation' => 'plan',
                'status' => 'error',
                'message' => 'stale frontend failure',
                'queue_id' => 12,
                'queue_waiting_for_scheduler' => true,
                'can_close_stream' => true,
                'continue_other_operations' => true,
            ],
            ['queue_id' => 344, 'pid' => 987, 'snapshot' => ['status' => 'running', 'queue_id' => 344, 'pid' => 987], 'process' => 'AI body is still streaming'],
            'plan'
        );
        self::assertSame('running', $running['status']);
        self::assertSame(344, $running['queue_id']);
        self::assertSame('AI body is still streaming', $running['message']);
        self::assertFalse((bool)$running['queue_waiting_for_scheduler']);
        self::assertFalse((bool)$running['can_close_stream']);
        self::assertFalse((bool)$running['continue_other_operations']);

        $queued = $service->reconcileActiveOperationWithQueueInfo(
            ['operation' => 'task_plan', 'status' => 'error', 'message' => 'stale frontend failure'],
            ['snapshot' => ['status' => 'pending']],
            'task_plan'
        );
        self::assertSame('queued', $queued['status']);
        self::assertNotSame('', (string)$queued['message']);
        self::assertTrue((bool)$queued['queue_waiting_for_scheduler']);
        self::assertTrue((bool)$queued['can_close_stream']);
        self::assertTrue((bool)$queued['continue_other_operations']);
    }

    public function testReconcileTrustsRunningQueueStatusWithoutPidProbe(): void
    {
        $service = $this->service();

        $running = $service->reconcileActiveOperationWithQueueInfo(
            [
                'operation' => 'build',
                'status' => 'error',
                'message' => 'stale frontend failure',
                'retry_allowed' => 1,
                'queue_terminal_recovered' => 1,
            ],
            [
                'queue_id' => 889,
                'pid' => 0,
                'process' => 'queue is running in the scheduler',
                'snapshot' => [
                    'queue_id' => 889,
                    'pid' => 0,
                    'status' => 'running',
                ],
            ],
            'build'
        );

        self::assertSame('running', $running['status']);
        self::assertSame('running', $running['semantic_status']);
        self::assertSame(889, $running['queue_id']);
        self::assertSame('queue is running in the scheduler', $running['message']);
        self::assertSame(0, $running['retry_allowed']);
        self::assertSame(0, $running['queue_terminal_recovered']);
        self::assertFalse((bool)$running['queue_waiting_for_scheduler']);
        self::assertFalse((bool)$running['can_close_stream']);
        self::assertFalse((bool)$running['continue_other_operations']);
    }

    public function testReconcileMapsTerminalQueueStatusesWithoutFoldingCancelledIntoDone(): void
    {
        $service = $this->service();

        foreach ([
            'done' => 'done',
            'stop' => 'cancelled',
            'stopped' => 'cancelled',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ] as $queueStatus => $expectedActiveStatus) {
            $result = $service->reconcileActiveOperationWithQueueInfo(
                [
                    'operation' => 'task_plan',
                    'status' => 'running',
                    'message' => '',
                    'queue_waiting_for_scheduler' => true,
                    'can_close_stream' => true,
                    'continue_other_operations' => true,
                ],
                ['snapshot' => ['status' => $queueStatus]],
                'task_plan'
            );
            self::assertSame($expectedActiveStatus, $result['status'], "queue={$queueStatus} 应映射为 {$expectedActiveStatus}");
            self::assertNotSame('', (string)$result['message'], 'task_plan 兜底 message 非空');
            self::assertFalse((bool)$result['queue_waiting_for_scheduler']);
            self::assertFalse((bool)$result['can_close_stream']);
            self::assertFalse((bool)$result['continue_other_operations']);
        }

        $buildResult = $service->reconcileActiveOperationWithQueueInfo(
            [
                'operation' => 'build',
                'status' => 'queued',
                'message' => '',
                'queue_waiting_for_scheduler' => true,
            ],
            ['snapshot' => ['status' => 'done']],
            'build'
        );
        self::assertSame('done', $buildResult['status']);
        self::assertNotSame('', (string)$buildResult['message']);
        self::assertFalse((bool)$buildResult['queue_waiting_for_scheduler']);

        $recovered = $service->reconcileActiveOperationWithQueueInfo(
            ['operation' => 'plan', 'status' => 'error', 'message' => 'stale frontend failure'],
            ['snapshot' => ['status' => 'done'], 'process' => 'queue completed successfully'],
            'plan'
        );
        self::assertSame('done', $recovered['status']);
        self::assertSame('queue completed successfully', $recovered['message']);

        $doneWithStaleRecoveryFlags = $service->reconcileActiveOperationWithQueueInfo(
            [
                'operation' => 'plan',
                'status' => 'cancelled',
                'message' => 'Linked queue process ended without a terminal queue status; retry is allowed.',
                'retry_allowed' => 1,
                'queue_terminal_recovered' => 1,
                'queue_waiting_for_scheduler' => true,
            ],
            [
                'queue_id' => 778,
                'retry_allowed' => 1,
                'queue_terminal_recovered' => 1,
                'semantic_status' => 'stale',
                'process' => 'site plan completed',
                'snapshot' => [
                    'queue_id' => 778,
                    'status' => 'done',
                    'retry_allowed' => 1,
                    'queue_terminal_recovered' => 1,
                ],
            ],
            'plan'
        );
        self::assertSame('done', $doneWithStaleRecoveryFlags['status']);
        self::assertSame('done', $doneWithStaleRecoveryFlags['semantic_status']);
        self::assertSame('site plan completed', $doneWithStaleRecoveryFlags['message']);
        self::assertSame(0, $doneWithStaleRecoveryFlags['retry_allowed']);
        self::assertSame(0, $doneWithStaleRecoveryFlags['queue_terminal_recovered']);

        // 已有非空 message 不应被兜底覆盖
        $preserved = $service->reconcileActiveOperationWithQueueInfo(
            ['operation' => 'plan', 'status' => 'running', 'message' => '已写入的定制文案'],
            ['snapshot' => ['status' => 'done']],
            'plan'
        );
        self::assertSame('已写入的定制文案', $preserved['message']);
    }

    // ------------------------------------------------------------------
    // emitQueueDetailEvents
    // ------------------------------------------------------------------

    public function testEmitQueueDetailEventsNoOpWhenSseNotAlive(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(false);
        $this->service()->emitQueueDetailEvents($sse, ['queue_id' => 1, 'status' => 'queued'], 'plan');
        self::assertSame([], $sse->calls);
    }

    public function testEmitQueueDetailEventsSendsInfoWithSnapshotAndDetailLines(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);
        $queueRow = [
            'queue_id' => 42,
            'name' => 'AI Plan',
            'module' => 'GuoLaiRen_PageBuilder',
            'biz_key' => 'plan:77',
            'status' => 'running',
            'pid' => 4321,
            'type_id' => 7,
            'finished' => 0,
            'start_at' => '2026-04-23 10:00:00',
            'end_at' => '',
            'input_tokens' => 120,
            'output_tokens' => 30,
        ];

        $this->service()->emitQueueDetailEvents($sse, $queueRow, 'plan');

        self::assertCount(1, $sse->calls);
        $call = $sse->calls[0];
        self::assertSame('info', $call['event']);
        $data = $call['data'];
        self::assertIsArray($data);
        self::assertSame(42, $data['queue_id']);
        self::assertSame('running', $data['queue_status']);
        self::assertSame('plan', $data['operation']);
        self::assertSame('queue_info', $data['progress_kind']);
        self::assertTrue($data['observer_detail']);
        self::assertNotEmpty($data['detail_lines']);
        self::assertArrayHasKey('queue_snapshot', $data);
        self::assertArrayHasKey('queue_info', $data);
        self::assertSame(42, $data['queue_snapshot']['queue_id']);
        self::assertSame(120, $data['token_usage']['input_tokens']);
        self::assertSame(30, $data['token_usage']['output_tokens']);
    }

    // ------------------------------------------------------------------
    // forwardObservedQueueSignals
    // ------------------------------------------------------------------

    public function testForwardObservedQueueSignalsReturnsUnchangedWhenQueueRowEmpty(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            null,
            'plan',
            'prev-process',
            100,
            'running',
            5678
        );

        self::assertSame(['prev-process', 100, 'running', 5678], $result);
        self::assertSame([], $sse->calls);
    }

    public function testForwardObservedQueueSignalsEmitsInfoOnStatusChangeAndPidAcquisition(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 77,
            'status' => 'running',   // last='queued' → 状态变更
            'pid' => 12345,           // last=0 → pid 领取
            'process' => '',
            'result' => '',
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',
            0,
            'queued',  // 上一轮状态
            0          // 上一轮 PID
        );

        self::assertCount(2, $sse->calls, '应发出 status 变更 + PID 领取 两个 info 事件');
        self::assertSame('info', $sse->calls[0]['event']);
        self::assertSame('info', $sse->calls[1]['event']);
        self::assertTrue($sse->calls[0]['data']['observer_detail']);
        self::assertTrue($sse->calls[1]['data']['observer_detail']);

        // 返回四元组：[lastProcess, lastResultLength, nextStatus, queuePid]
        self::assertSame(['', 0, 'running', 12345], $result);
    }

    public function testForwardObservedQueueSignalsEmitsErrorEventWhenQueueEntersTerminalFailure(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 88,
            'status' => 'error',
            'pid' => 0,
            'process' => 'Build task failed hard',
            'result' => '',
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            'Build task failed hard',
            0,
            'running',
            0
        );

        $errorEvents = \array_values(\array_filter(
            $sse->calls,
            static fn (array $call): bool => $call['event'] === 'error'
        ));

        self::assertCount(1, $errorEvents);
        $data = $errorEvents[0]['data'];
        self::assertIsArray($data);
        self::assertSame('Build task failed hard', $data['message']);
        self::assertSame('build', $data['operation']);
        self::assertSame(88, $data['queue_id']);
        self::assertSame('error', $data['queue_status']);
        self::assertSame('Build task failed hard', $data['queue_process']);
        self::assertSame('queue_info', $data['progress_kind']);
        self::assertTrue($data['observer_detail']);
        self::assertTrue($data['queue_panel_update']);
        self::assertSame(409, $data['http_code']);
        self::assertSame(['Build task failed hard', 0, 'error', 0], $result);
    }

    public function testForwardObservedQueueSignalsEmitsProgressEventWhenProcessChangesAndNotSuppressed(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 1,
            'status' => 'running',
            'pid' => 100,
            'process' => 'AI 生成进度 45%',
            'result' => '',
        ];

        // operation='build' 不在 suppress 列表（plan/task_plan 被抑制） → 走 progress 事件
        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',        // 上一轮 process 为空
            0,
            'running', // 状态未变更
            100        // PID 未变
        );

        self::assertCount(1, $sse->calls);
        self::assertSame('progress', $sse->calls[0]['event']);
        self::assertSame('AI 生成进度 45%', $sse->calls[0]['data']['message']);
        self::assertSame('AI 生成进度 45%', $sse->calls[0]['data']['queue_process']);
        self::assertSame('queue_info', $sse->calls[0]['data']['progress_kind']);

        // lastProcess 应被更新
        self::assertSame('AI 生成进度 45%', $result[0]);
    }

    public function testForwardObservedQueueSignalsKeepsTaskPlanQueueProcessAndResultVisible(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 18,
            'status' => 'running',
            'pid' => 2468,
            'process' => '第二阶段任务方案：正在汇总页面任务',
            'result' => "[12:00:00] INFO 第二阶段任务方案进入批量整理\n[12:00:02] TASK_PLAN_REFINED 第二阶段任务方案已刷新\n",
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'task_plan',
            '',
            0,
            'running',
            2468
        );

        self::assertCount(3, $sse->calls);
        self::assertSame('progress', $sse->calls[0]['event']);
        self::assertSame('第二阶段任务方案：正在汇总页面任务', $sse->calls[0]['data']['message']);
        self::assertSame('chunk', $sse->calls[1]['event']);
        self::assertSame('[12:00:00] INFO 第二阶段任务方案进入批量整理', $sse->calls[1]['data']['message']);
        self::assertSame('chunk', $sse->calls[2]['event']);
        self::assertSame('[12:00:02] TASK_PLAN_REFINED 第二阶段任务方案已刷新', $sse->calls[2]['data']['message']);
        self::assertSame('第二阶段任务方案：正在汇总页面任务', $result[0]);
        self::assertSame(\strlen($queueRow['result']), $result[1]);
    }

    public function testForwardObservedQueueSignalsEmitsSuppressedPanelUpdateWhenOperationSuppressed(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 1,
            'status' => 'running',
            'pid' => 100,
            'process' => 'AI Stream Token +5',
            'result' => '',
        ];

        // operation='plan' 属于 suppress 列表 → 走带空 message 的 info + queue_panel_update=true
        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'plan',
            '',
            0,
            'running',
            100
        );

        self::assertCount(1, $sse->calls);
        self::assertSame('info', $sse->calls[0]['event']);
        self::assertSame('', $sse->calls[0]['data']['message'], 'suppress 分支必须保持 message 为空字符串');
        self::assertTrue($sse->calls[0]['data']['queue_panel_update']);
        self::assertSame('AI Stream Token +5', $sse->calls[0]['data']['queue_process']);
        self::assertSame('AI Stream Token +5', $result[0]);
    }

    public function testForwardObservedQueueSignalsEmitsChunkEventPerResultDeltaLineSkippingEmpty(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 1,
            'status' => 'running',
            'pid' => 100,
            'process' => '',
            'result' => "行1\n\n行2\r\n行3",
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',
            0,           // 上一轮 result 长度 0 → 全量作为 delta
            'running',
            100
        );

        $chunkEvents = \array_values(\array_filter(
            $sse->calls,
            static fn (array $c): bool => $c['event'] === 'chunk'
        ));
        self::assertCount(3, $chunkEvents, '空行应被跳过');
        self::assertSame('行1', $chunkEvents[0]['data']['message']);
        self::assertSame('行2', $chunkEvents[1]['data']['message']);
        self::assertSame('行3', $chunkEvents[2]['data']['message']);
        self::assertSame("行1" . PHP_EOL, $chunkEvents[0]['data']['chunk']);

        // lastQueueResultLength 应被推进到整段长度
        self::assertSame(\strlen("行1\n\n行2\r\n行3"), $result[1]);
    }

    public function testForwardObservedQueueSignalsResetsLastLengthWhenResultShrinks(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 1,
            'status' => 'running',
            'pid' => 100,
            'process' => '',
            'result' => 'short',
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',
            99999, // 上一轮长度远大于现状
            'running',
            100
        );

        // 长度倒退应重置为 0 再吞新 delta，而不是跳过
        self::assertSame(\strlen('short'), $result[1]);
        $chunkEvents = \array_values(\array_filter(
            $sse->calls,
            static fn (array $c): bool => $c['event'] === 'chunk'
        ));
        self::assertCount(1, $chunkEvents);
        self::assertSame('short', $chunkEvents[0]['data']['message']);
    }
}
