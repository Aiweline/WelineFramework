<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverStreamService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueStateService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * 鍐呴儴 Spy锛氳褰?SseWriter 鐨?sendEvent / isAlive 璋冪敤浠ラ攣瀹氫簨浠跺簭鍒椾笌 payload銆? *
 * 缁曡繃鐖剁被鏋勯€狅紙SSE 鐪熷疄鍐欏叆渚濊禆 Runtime / Env锛夛紝閫氳繃 override 淇濇寔娴嬭瘯渚?100% 绾€? */
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
            new AiSiteQueueStateService(),
            new AiSiteAgentQueueObserverHelperService()
        );
    }
    // ------------------------------------------------------------------
    // reconcileActiveOperationWithQueueInfo
    // ------------------------------------------------------------------

    public function testReconcileReturnsOriginalWhenOperationMismatchesOrQueueStateMissing(): void
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
            $service->reconcileActiveOperationWithQueueInfo($active, ['status' => 'done'], 'task_plan'),
            'operation 涓嶅尮閰嶅簲鍘熸牱杩斿洖'
        );

        self::assertSame(
            $active,
            $service->reconcileActiveOperationWithQueueInfo($active, null, 'plan'),
            'queueInfo null should return original'
        );

        $notActive = ['operation' => 'plan', 'status' => 'done'];
        self::assertSame(
            $notActive,
            $service->reconcileActiveOperationWithQueueInfo($notActive, ['status' => 'error'], 'plan'),
            'inactive status should return original'
        );

        self::assertSame(
            $active,
            $service->reconcileActiveOperationWithQueueInfo($active, [], 'plan'),
            'missing queue state should return original'
        );
    }

    public function testReconcileUsesTopLevelQueueStateWithoutNestedState(): void
    {
        $service = $this->service();

        $result = $service->reconcileActiveOperationWithQueueInfo(
            ['operation' => 'plan', 'status' => 'error', 'message' => 'stale frontend failure'],
            ['queue_id' => 344, 'pid' => 987, 'status' => 'running', 'process' => 'AI body is still streaming'],
            'plan'
        );

        self::assertSame('running', $result['status']);
        self::assertSame(344, $result['queue_id']);
        self::assertSame('AI body is still streaming', $result['message']);
    }

    public function testReconcileMapsErrorStatusToErrorWithProcessFallbackChain(): void
    {
        $service = $this->service();
        $active = ['operation' => 'plan', 'status' => 'running'];

        $withProcess = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            [
                'status' => 'error',
                'process' => 'provider quota exceeded',
                'result_log' => '',
            ],
            'plan'
        );
        self::assertSame('error', $withProcess['status']);
        self::assertSame('provider quota exceeded', $withProcess['message'], 'process should be preferred when non-empty');
        self::assertFalse((bool)$withProcess['queue_waiting_for_scheduler']);
        self::assertFalse((bool)$withProcess['can_close_stream']);
        self::assertFalse((bool)$withProcess['continue_other_operations']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$withProcess['updated_at']);

        $withResultTail = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            [
                'queue_id' => 553,
                'status' => 'error',
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
                'status' => 'error',
                'process' => '',
                'result_log' => 'stack trace ...',
            ],
            'plan'
        );
        self::assertSame('error', $withOnlyResult['status']);
        self::assertNotSame('', (string)$withOnlyResult['message'], 'result_log should produce a fallback message');

        $withNothing = $service->reconcileActiveOperationWithQueueInfo(
            $active,
            ['status' => 'error'],
            'plan'
        );
        self::assertSame('error', $withNothing['status']);
        self::assertNotSame('', (string)$withNothing['message'], 'fallback i18n message should not be empty');
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
            ['queue_id' => 344, 'pid' => 987, 'status' => 'running', 'process' => 'AI body is still streaming'],
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
            ['status' => 'pending'],
            'task_plan'
        );
        self::assertSame('queued', $queued['status']);
        self::assertNotSame('', (string)$queued['message']);
        self::assertTrue((bool)$queued['queue_waiting_for_scheduler']);
        self::assertTrue((bool)$queued['can_close_stream']);
        self::assertTrue((bool)$queued['continue_other_operations']);
    }

    public function testReconcileTreatsRunningWithoutPidAsSchedulerWaiting(): void
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
                'process' => 'queue is waiting for the scheduler worker',
                'status' => 'running',
            ],
            'build'
        );

        self::assertSame('running', $running['status']);
        self::assertSame('running', $running['semantic_status']);
        self::assertSame(889, $running['queue_id']);
        self::assertStringContainsString('#889', (string)$running['message']);
        self::assertSame(0, $running['retry_allowed']);
        self::assertSame(0, $running['queue_terminal_recovered']);
        self::assertTrue((bool)$running['queue_waiting_for_scheduler']);
        self::assertTrue((bool)$running['can_close_stream']);
        self::assertTrue((bool)$running['continue_other_operations']);
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
                ['status' => $queueStatus],
                'task_plan'
            );
            self::assertSame($expectedActiveStatus, $result['status'], "queue={$queueStatus} 搴旀槧灏勪负 {$expectedActiveStatus}");
            self::assertNotSame('', (string)$result['message'], 'task_plan 鍏滃簳 message 闈炵┖');
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
            ['status' => 'done'],
            'build'
        );
        self::assertSame('done', $buildResult['status']);
        self::assertNotSame('', (string)$buildResult['message']);
        self::assertFalse((bool)$buildResult['queue_waiting_for_scheduler']);

        $recovered = $service->reconcileActiveOperationWithQueueInfo(
            ['operation' => 'plan', 'status' => 'error', 'message' => 'stale frontend failure'],
            ['status' => 'done', 'process' => 'queue completed successfully'],
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
                'status' => 'done',
            ],
            'plan'
        );
        self::assertSame('done', $doneWithStaleRecoveryFlags['status']);
        self::assertSame('done', $doneWithStaleRecoveryFlags['semantic_status']);
        self::assertSame('site plan completed', $doneWithStaleRecoveryFlags['message']);
        self::assertSame(0, $doneWithStaleRecoveryFlags['retry_allowed']);
        self::assertSame(0, $doneWithStaleRecoveryFlags['queue_terminal_recovered']);

        // 宸叉湁闈炵┖ message 涓嶅簲琚厹搴曡鐩?        $preserved = $service->reconcileActiveOperationWithQueueInfo(
        $preserved = $service->reconcileActiveOperationWithQueueInfo(
            ['operation' => 'plan', 'status' => 'running', 'message' => '宸插啓鍏ョ殑瀹氬埗鏂囨'],
            ['status' => 'done'],
            'plan'
        );
        self::assertSame('宸插啓鍏ョ殑瀹氬埗鏂囨', $preserved['message']);
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

    public function testEmitQueueDetailEventsSendsInfoWithCurrentStateAndDetailLines(): void
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
        self::assertArrayHasKey('queue_state', $data);
        self::assertArrayHasKey('queue_info', $data);
        self::assertSame(42, $data['queue_state']['queue_id']);
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
            'status' => 'running',
            'pid' => 12345,
            'process' => '',
            'result' => '',
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',
            0,
            'queued',
            0
        );

        self::assertCount(2, $sse->calls, 'status change and PID acquisition should emit two info events');
        self::assertSame('info', $sse->calls[0]['event']);
        self::assertSame('info', $sse->calls[1]['event']);
        self::assertTrue($sse->calls[0]['data']['observer_detail']);
        self::assertTrue($sse->calls[1]['data']['observer_detail']);

        // 杩斿洖鍥涘厓缁勶細[lastProcess, lastResultLength, nextStatus, queuePid]
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
            'process' => 'AI 鐢熸垚杩涘害 45%',
            'result' => '',
        ];

        // operation='build' 涓嶅湪 suppress 鍒楄〃锛坧lan/task_plan 琚姂鍒讹級 鈫?璧?progress 浜嬩欢
        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',        // 涓婁竴杞?process 涓虹┖
            0,
            'running', // 鐘舵€佹湭鍙樻洿
            100        // PID 鏈彉
        );

        self::assertCount(1, $sse->calls);
        self::assertSame('progress', $sse->calls[0]['event']);
        self::assertSame('AI 鐢熸垚杩涘害 45%', $sse->calls[0]['data']['message']);
        self::assertSame('AI 鐢熸垚杩涘害 45%', $sse->calls[0]['data']['queue_process']);
        self::assertSame('queue_info', $sse->calls[0]['data']['progress_kind']);

        // lastProcess 搴旇鏇存柊
        self::assertSame('AI 鐢熸垚杩涘害 45%', $result[0]);
    }

    public function testForwardObservedQueueSignalsKeepsTaskPlanProcessVisibleWithoutReplayingResult(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 18,
            'status' => 'running',
            'pid' => 2468,
            'process' => 'task plan is summarizing page tasks',
            'result' => "[12:00:00] INFO task plan entered batch refinement\n[12:00:02] TASK_PLAN_REFINED task plan refreshed\n",
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

        self::assertCount(1, $sse->calls);
        self::assertSame('progress', $sse->calls[0]['event']);
        self::assertSame('task plan is summarizing page tasks', $sse->calls[0]['data']['message']);
        self::assertSame('task plan is summarizing page tasks', $sse->calls[0]['data']['queue_process']);
        self::assertArrayNotHasKey('queue_result_delta', $sse->calls[0]['data']);
        self::assertSame('task plan is summarizing page tasks', $result[0]);
        self::assertSame(\strlen($queueRow['result']), $result[1]);
    }

    public function testForwardObservedQueueSignalsMirrorsPlanProcessAsProgress(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 1,
            'status' => 'running',
            'pid' => 100,
            'process' => 'AI Stream Token +5',
            'result' => '',
        ];

        // operation='plan' 灞炰簬 suppress 鍒楄〃 鈫?璧板甫绌?message 鐨?info + queue_panel_update=true
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
        self::assertSame('progress', $sse->calls[0]['event']);
        self::assertSame('AI Stream Token +5', $sse->calls[0]['data']['message']);
        self::assertSame('AI Stream Token +5', $sse->calls[0]['data']['queue_process']);
        self::assertSame('AI Stream Token +5', $result[0]);
    }

    public function testForwardObservedQueueSignalsOnlyAdvancesLastResultLengthWhenResultGrows(): void
    {
        $sse = new SpySseWriterForQueueObserverStream(true);

        $queueRow = [
            'queue_id' => 1,
            'status' => 'running',
            'pid' => 100,
            'process' => '',
            'result' => "琛?\n\n琛?\r\n琛?",
        ];

        $result = $this->service()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            'build',
            '',
            0,           // 涓婁竴杞?result 闀垮害 0 鈫?鍏ㄩ噺浣滀负 delta
            'running',
            100
        );

        self::assertSame([], $sse->calls, 'queue.result 澧為暱鍙帹杩涙父鏍囷紝涓嶅啀鎷嗘垚 chunk/queue_result_delta 鍥炴斁');
        self::assertSame(\strlen("琛?\n\n琛?\r\n琛?"), $result[1]);
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
            99999, // 涓婁竴杞暱搴﹁繙澶т簬鐜扮姸
            'running',
            100
        );

        // 闀垮害鍊掗€€搴旈噸缃负 0 鍐嶆帹杩涘埌褰撳墠闀垮害锛屼絾涓嶅洖鏀炬棫 result銆?        self::assertSame(\strlen('short'), $result[1]);
        self::assertSame([], $sse->calls);
    }
}
