<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueDispatchGuardService;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * 内部 Spy：记录 SseWriter 调用序列；绕开父类构造以避免牵入 Runtime/Env 依赖。
 */
final class SpySseWriterForQueueDispatchGuard extends SseWriter
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

final class AiSiteAgentQueueDispatchGuardServiceTest extends TestCase
{
    private function service(): AiSiteAgentQueueDispatchGuardService
    {
        return new AiSiteAgentQueueDispatchGuardService();
    }

    /**
     * 构造最小可用的 AiSiteAgentSession mock；服务只透传给 Closure 端口，不解引用其内部字段。
     */
    private function sessionMock(): AiSiteAgentSession
    {
        return $this->createMock(AiSiteAgentSession::class);
    }

    /**
     * @param array{
     *   attempted?:bool, started?:bool, pid?:int, queue_id?:int,
     *   reason?:string, message?:string, process_name?:string
     * } $override
     */
    private function makeDispatchPort(array $override = [], ?array &$invokedWith = null): \Closure
    {
        return function (
            AiSiteAgentSession $session,
            int $adminId,
            string $operation,
            int $queueId,
            string $executionToken,
            bool $force
        ) use ($override, &$invokedWith): array {
            $invokedWith = [
                'admin_id' => $adminId,
                'operation' => $operation,
                'queue_id' => $queueId,
                'execution_token' => $executionToken,
                'force' => $force,
            ];
            return \array_replace([
                'attempted' => true,
                'started' => true,
                'pid' => 4321,
                'queue_id' => $queueId,
                'reason' => 'spawned',
                'message' => '',
                'process_name' => 'pb-ai-site-queue-' . $queueId,
            ], $override);
        };
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function makeFindRowPort(?array $row, ?array &$invokedWith = null): \Closure
    {
        return function (AiSiteAgentSession $s, string $op, int $qid) use ($row, &$invokedWith): ?array {
            $invokedWith = ['operation' => $op, 'queue_id' => $qid];
            return $row;
        };
    }

    // ------------------------------------------------------------------
    // Short-circuit branches
    // ------------------------------------------------------------------

    public function testNoOpWhenAlreadyAttempted(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;
        $queueRow = ['queue_id' => 1, 'status' => 'pending'];

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-A',
            $queueRow,
            true, // alreadyAttempted
            ['active_operation' => []],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertSame(
            ['attempted' => false, 'started' => false, 'queue' => $queueRow, 'message' => ''],
            $result
        );
        self::assertNull($dispatchInvoked, 'dispatchPort 不应被调用');
        self::assertSame([], $sse->calls, 'SSE 不应发事件');
    }

    public function testNoOpWhenQueueRowNull(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-A',
            null,
            false,
            [],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertSame(
            ['attempted' => false, 'started' => false, 'queue' => null, 'message' => ''],
            $result
        );
        self::assertNull($dispatchInvoked);
        self::assertSame([], $sse->calls);
    }

    public function testNoOpWhenQueueRowEmptyArray(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-A',
            [],
            false,
            [],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertFalse($result['started']);
        self::assertSame([], $result['queue']);
        self::assertNull($dispatchInvoked);
    }

    public function testNoOpWhenStatusDoneAndPidPresent(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-A',
            ['queue_id' => 7, 'status' => 'done', 'pid' => 4321],
            false,
            ['active_operation' => []],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertSame([], $sse->calls);
        self::assertNull($dispatchInvoked);
    }

    public function testNoOpWhenRunningWithValidPid(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-A',
            ['queue_id' => 7, 'status' => 'running', 'pid' => 9999],
            false,
            ['active_operation' => []],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertNull($dispatchInvoked);
    }

    public function testNoOpWhenQueueIdZero(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-A',
            ['queue_id' => 0, 'status' => 'pending'],
            false,
            ['active_operation' => []],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertNull($dispatchInvoked);
    }

    // ------------------------------------------------------------------
    // Pending dispatch branches
    // ------------------------------------------------------------------

    public function testDispatchesWhenPendingAndForwardsStartedInfoEvent(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;
        $findInvoked = null;

        $queueRow = ['queue_id' => 42, 'status' => 'pending', 'pid' => 0];
        $updatedRow = ['queue_id' => 42, 'status' => 'running', 'pid' => 5555];

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-X',
            $queueRow,
            false,
            ['active_operation' => []],
            $this->makeDispatchPort(
                ['started' => true, 'attempted' => true, 'pid' => 5555, 'message' => 'worker spawned'],
                $dispatchInvoked
            ),
            $this->makeFindRowPort($updatedRow, $findInvoked)
        );

        self::assertSame(['admin_id' => 100, 'operation' => 'plan', 'queue_id' => 42, 'execution_token' => 'tok-X', 'force' => true], $dispatchInvoked);
        self::assertSame(['operation' => 'plan', 'queue_id' => 42], $findInvoked);

        self::assertTrue($result['attempted']);
        self::assertTrue($result['started']);
        self::assertSame($updatedRow, $result['queue'], 'started 时应返回 findRow 的最新 row');
        self::assertSame('worker spawned', $result['message']);

        self::assertCount(1, $sse->calls);
        self::assertSame('info', $sse->calls[0]['event'], 'started=true 应发 info 事件');
        self::assertSame('worker spawned', $sse->calls[0]['data']['message']);
        self::assertSame('running', $sse->calls[0]['data']['queue_status'], 'queue_status 应采用最新 row');
        self::assertTrue($sse->calls[0]['data']['observer_detail']);
    }

    public function testDispatchesWhenRunningButNoPidAndForwardsWarningOnStartedFalse(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $queueRow = ['queue_id' => 42, 'status' => 'running', 'pid' => 0];

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-X',
            $queueRow,
            false,
            ['active_operation' => []],
            $this->makeDispatchPort(
                ['started' => false, 'attempted' => true, 'message' => 'deferred'],
                $dispatchInvoked
            ),
            $this->makeFindRowPort(null) // findRow 返回 null 走兜底
        );

        self::assertNotNull($dispatchInvoked);
        self::assertTrue($result['attempted']);
        self::assertFalse($result['started']);
        self::assertSame($queueRow, $result['queue'], 'findRow null 时应用原 $queueRow 兜底');

        self::assertCount(1, $sse->calls);
        self::assertSame('warning', $sse->calls[0]['event'], 'started=false 应发 warning 事件');
        self::assertSame('deferred', $sse->calls[0]['data']['message']);
        self::assertSame('running', $sse->calls[0]['data']['queue_status'], 'findRow null 时 queue_status 应取原 row');
    }

    public function testNoSseEventWhenDispatchMessageEmpty(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);

        $queueRow = ['queue_id' => 1, 'status' => 'pending', 'pid' => 0];

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok',
            $queueRow,
            false,
            ['active_operation' => []],
            $this->makeDispatchPort(['started' => true, 'message' => '']),
            $this->makeFindRowPort(null)
        );

        self::assertTrue($result['attempted']);
        self::assertSame('', $result['message']);
        self::assertSame([], $sse->calls, 'message 为空不应发事件');
    }

    // ------------------------------------------------------------------
    // Recoverable settled branches
    // ------------------------------------------------------------------

    public function testRecoverableSettledQueueEmitsWarningPreludeThenDispatches(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $queueRow = [
            'queue_id' => 42,
            'status' => 'error',
            'pid' => 0,
            'content' => \json_encode(['execution_token' => 'tok-X']),
        ];

        // active_operation 匹配当前 (operation, executionToken) 且 status ∈ {queued,running}
        $scope = [
            'active_operation' => [
                'operation' => 'plan',
                'execution_token' => 'tok-X',
                'status' => 'running',
            ],
        ];

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-X',
            $queueRow,
            false,
            $scope,
            $this->makeDispatchPort(
                ['started' => true, 'attempted' => true, 'message' => 'recovered'],
                $dispatchInvoked
            ),
            $this->makeFindRowPort(['queue_id' => 42, 'status' => 'running', 'pid' => 8888])
        );

        self::assertNotNull($dispatchInvoked);
        self::assertTrue($result['started']);

        self::assertCount(2, $sse->calls, '应先发恢复 warning 再发 dispatch info');
        self::assertSame('warning', $sse->calls[0]['event']);
        self::assertSame('error', $sse->calls[0]['data']['queue_status'], '恢复提示事件携带原 status=error');
        self::assertTrue($sse->calls[0]['data']['observer_detail']);
        self::assertSame('info', $sse->calls[1]['event']);
        self::assertSame('recovered', $sse->calls[1]['data']['message']);
    }

    public function testRecoverableSettledDoesNotRecoverWhenActiveOperationMismatches(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        // active_operation 属于不同 operation → 不满足 activeMatchesCurrent → 不视作可恢复
        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-X',
            [
                'queue_id' => 42,
                'status' => 'error',
                'pid' => 0,
                'content' => \json_encode(['execution_token' => 'tok-X']),
            ],
            false,
            [
                'active_operation' => [
                    'operation' => 'task_plan', // 不匹配 plan
                    'execution_token' => 'tok-X',
                    'status' => 'running',
                ],
            ],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertSame([], $sse->calls);
        self::assertNull($dispatchInvoked);
    }

    public function testRecoverableSettledDoesNotRecoverWhenExecutionTokenInContentDiffers(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        // queueContent.execution_token 非空且不等于当前 executionToken → 不可恢复
        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-NEW',
            [
                'queue_id' => 42,
                'status' => 'stop',
                'pid' => 0,
                'content' => \json_encode(['execution_token' => 'tok-OLD']),
            ],
            false,
            [
                'active_operation' => [
                    'operation' => 'plan',
                    'execution_token' => 'tok-NEW',
                    'status' => 'running',
                ],
            ],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertNull($dispatchInvoked);
    }

    public function testRecoverableSettledSkipsWarningPreludeWhenSseDead(): void
    {
        $sse = new SpySseWriterForQueueDispatchGuard(false); // dead SSE
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-X',
            [
                'queue_id' => 42,
                'status' => 'error',
                'pid' => 0,
                'content' => \json_encode(['execution_token' => '']),
            ],
            false,
            [
                'active_operation' => [
                    'operation' => 'plan',
                    'execution_token' => 'tok-X',
                    'status' => 'running',
                ],
            ],
            $this->makeDispatchPort(['started' => true, 'attempted' => true, 'message' => 'ok'], $dispatchInvoked),
            $this->makeFindRowPort(['queue_id' => 42, 'status' => 'running'])
        );

        // dispatch 仍被调用（恢复分支的 SSE 只是前奏提示）
        self::assertNotNull($dispatchInvoked);
        self::assertTrue($result['started']);
        // 但 dead SSE 完全静默
        self::assertSame([], $sse->calls);
    }

    public function testRecoverableSettledDoesNotRecoverWhenActiveStatusIsDone(): void
    {
        // active.status=done → !in_array(queued|running) → activeMatchesCurrent=false
        $sse = new SpySseWriterForQueueDispatchGuard(true);
        $dispatchInvoked = null;

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok-X',
            [
                'queue_id' => 42,
                'status' => 'error',
                'pid' => 0,
                'content' => \json_encode(['execution_token' => 'tok-X']),
            ],
            false,
            [
                'active_operation' => [
                    'operation' => 'plan',
                    'execution_token' => 'tok-X',
                    'status' => 'done',
                ],
            ],
            $this->makeDispatchPort([], $dispatchInvoked),
            $this->makeFindRowPort(null)
        );

        self::assertFalse($result['attempted']);
        self::assertNull($dispatchInvoked);
    }

    public function testScopeWithoutActiveOperationKeyTolerated(): void
    {
        // normalizedScope 完全没有 active_operation 键 → 服务应安全降级为空数组
        $sse = new SpySseWriterForQueueDispatchGuard(true);

        $result = $this->service()->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $this->sessionMock(),
            100,
            'plan',
            'tok',
            ['queue_id' => 1, 'status' => 'pending', 'pid' => 0],
            false,
            [], // 无 active_operation 键
            $this->makeDispatchPort(['started' => true, 'message' => 'ok']),
            $this->makeFindRowPort(null)
        );

        // pending 分支仍会触发 dispatch
        self::assertTrue($result['attempted']);
        self::assertTrue($result['started']);
    }
}
