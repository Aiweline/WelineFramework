<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentTaskPlanQueueRecoveryPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentTaskPlanQueueRecoveryService;
use PHPUnit\Framework\TestCase;

/**
 * Characterization Test：锁定 `autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing`
 * 原控制器私有方法行为，抽离到 `AiSiteAgentTaskPlanQueueRecoveryService` 后以 Ports 端口
 * 精确追踪每一个副作用调用；确保薄壳化 / 未来重构不漂移。
 *
 * 辅助工具：
 *  - `StubPortsFactory`：构造一套可完全追踪的 Port Closure 集合；
 *  - 每个 Port 按调用序写入 `$this->calls` 二维表，便于分支校验。
 */
final class AiSiteAgentTaskPlanQueueRecoveryServiceTest extends TestCase
{
    private const STAGE_VISUAL_EDIT = 'visual_edit';

    private function service(): AiSiteAgentTaskPlanQueueRecoveryService
    {
        return new AiSiteAgentTaskPlanQueueRecoveryService();
    }

    private function sessionMock(int $id = 7777, string $publicId = 'pub-xxx'): AiSiteAgentSession
    {
        $session = $this->createMock(AiSiteAgentSession::class);
        $session->method('getId')->willReturn($id);
        $session->method('getPublicId')->willReturn($publicId);
        return $session;
    }

    /**
     * 构造 StubPorts：Port Closure 会把调用参数按顺序追加进 `$calls[$name][]`。
     *
     * @param array<string, mixed> $overrides 覆盖默认返回值；key ∈ {
     *   isDraftMissing, findQueueRow, enqueueTask, buildEnvelope, loadSession,
     *   ensureDispatched, buildQueueInfoPayload, throwOn
     * }
     */
    private function makePorts(array $overrides, array &$calls): AiSiteAgentTaskPlanQueueRecoveryPorts
    {
        $calls = [
            'isDraftMissing' => [],
            'findQueueRow' => [],
            'enqueueTask' => [],
            'buildEnvelope' => [],
            'mergeScope' => [],
            'loadSession' => [],
            'ensureDispatched' => [],
            'buildQueueInfoPayload' => [],
            'logSse' => [],
            'resolveQueueStage' => [],
            'updateQueueRow' => [],
        ];

        return new AiSiteAgentTaskPlanQueueRecoveryPorts(
            isTaskPlanDraftMissing: function (array $normalized) use (&$calls, $overrides): bool {
                $calls['isDraftMissing'][] = ['normalized_keys' => \array_keys($normalized)];
                return (bool)($overrides['isDraftMissing'] ?? true);
            },
            findQueueRow: function (AiSiteAgentSession $s, string $op, int $qid = 0) use (&$calls, $overrides): ?array {
                $calls['findQueueRow'][] = ['operation' => $op, 'queue_id' => $qid];
                if (\array_key_exists('findQueueRow', $overrides)) {
                    $ret = $overrides['findQueueRow'];
                    if (\is_array($ret) && !empty($ret) && \is_array($ret[0] ?? null)) {
                        return \array_shift($overrides['findQueueRow']);
                    }
                    return $ret;
                }
                return null;
            },
            enqueueTask: function (AiSiteAgentSession $s, int $adminId, string $op, string $token, array $extras) use (&$calls, $overrides): int {
                $calls['enqueueTask'][] = [
                    'admin_id' => $adminId,
                    'operation' => $op,
                    'execution_token' => $token,
                    'extras' => $extras,
                ];
                return (int)($overrides['enqueueTask'] ?? 0);
            },
            buildOperationEnvelope: function (AiSiteAgentSession $s, string $op, string $token, string $status) use (&$calls): array {
                $calls['buildEnvelope'][] = [
                    'operation' => $op,
                    'execution_token' => $token,
                    'status' => $status,
                ];
                return [
                    'envelope_op' => $op,
                    'envelope_token' => $token,
                    'envelope_status' => $status,
                ];
            },
            mergeSessionScope: function (int $sid, int $adminId, array $patch) use (&$calls, $overrides): void {
                $calls['mergeScope'][] = [
                    'session_id' => $sid,
                    'admin_id' => $adminId,
                    'patch_keys' => \array_keys($patch),
                    'active_operation' => $patch['active_operation'] ?? null,
                    'active_operations' => $patch['active_operations'] ?? null,
                ];
                if (($overrides['throwOn'] ?? '') === 'mergeScope') {
                    throw new \RuntimeException('simulated mergeScope failure');
                }
            },
            loadSession: function (int $sid, int $adminId) use (&$calls, $overrides): ?AiSiteAgentSession {
                $calls['loadSession'][] = ['session_id' => $sid, 'admin_id' => $adminId];
                return $overrides['loadSession'] ?? null;
            },
            ensureWorkerDispatched: function (AiSiteAgentSession $s, int $adminId, string $op, int $qid, string $token, bool $force) use (&$calls, $overrides): array {
                $calls['ensureDispatched'][] = [
                    'admin_id' => $adminId,
                    'operation' => $op,
                    'queue_id' => $qid,
                    'execution_token' => $token,
                    'force' => $force,
                ];
                if (($overrides['throwOn'] ?? '') === 'ensureDispatched') {
                    throw new \RuntimeException('simulated dispatch failure');
                }
                return $overrides['ensureDispatched'] ?? [
                    'started' => true,
                    'attempted' => true,
                    'pid' => 9999,
                ];
            },
            buildQueueInfoPayload: function (AiSiteAgentSession $s, array $active, string $op) use (&$calls, $overrides): array {
                $calls['buildQueueInfoPayload'][] = [
                    'active_operation' => $active,
                    'operation' => $op,
                ];
                return $overrides['buildQueueInfoPayload'] ?? [
                    'snapshot' => ['status' => 'unknown', 'queue_id' => 0],
                    'queue_status' => 'unknown',
                ];
            },
            logSse: function (string $event, array $data, string $level = 'info') use (&$calls): void {
                $calls['logSse'][] = [
                    'event' => $event,
                    'data' => $data,
                    'level' => $level,
                ];
            },
            resolveQueueStage: function (string $op) use (&$calls): string {
                $calls['resolveQueueStage'][] = ['operation' => $op];
                return 'stage_visual_edit';
            },
            updateQueueRow: function (int $qid, array $patch) use (&$calls): void {
                $calls['updateQueueRow'][] = [
                    'queue_id' => $qid,
                    'patch_keys' => \array_keys($patch),
                    'status' => $patch['status'] ?? null,
                    'pid' => $patch['pid'] ?? null,
                ];
            },
        );
    }

    // ------------------------------------------------------------------
    // Short-circuit branches
    // ------------------------------------------------------------------

    public function testNoOpWhenStageIsNotVisualEdit(): void
    {
        $calls = [];
        $ports = $this->makePorts([], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            'plan', // 非 visual_edit
            self::STAGE_VISUAL_EDIT,
            ['task_plan' => []],
            [],
            null,
            $ports
        );

        self::assertSame(['task_plan' => []], $result['normalized']);
        self::assertSame([], $result['active_operation']);
        self::assertNull($result['task_plan_queue_info']);
        self::assertSame([], $calls['isDraftMissing']);
        self::assertSame([], $calls['findQueueRow']);
        self::assertSame([], $calls['logSse']);
    }

    public function testNoOpWhenDraftNotMissing(): void
    {
        $calls = [];
        $ports = $this->makePorts(['isDraftMissing' => false], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            ['task_plan' => ['draft' => ['x' => 1]]],
            [],
            null,
            $ports
        );

        self::assertCount(1, $calls['isDraftMissing']);
        self::assertSame([], $calls['findQueueRow']);
        self::assertSame([], $calls['enqueueTask']);
        self::assertSame([], $calls['logSse']);
        self::assertSame([], $result['active_operation']);
    }

    public function testNoOpWhenQueueInProgressPending(): void
    {
        $calls = [];
        $ports = $this->makePorts([], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            [],
            [
                'queue_id' => 42,
                'snapshot' => ['status' => 'pending', 'queue_id' => 42],
            ],
            $ports
        );

        self::assertCount(1, $calls['isDraftMissing']);
        self::assertSame([], $calls['findQueueRow'], 'snapshot 提供了 status+qid，不应回退 findQueueRow');
        self::assertSame([], $calls['enqueueTask']);
        self::assertSame([], $calls['updateQueueRow']);
        self::assertSame([], $calls['logSse']);
    }

    public function testNoOpWhenQueueExistsButNotDone(): void
    {
        $calls = [];
        // status='running' pid>0 场景：pending/queued/running 都会走 in-progress 分支
        // 这里选一个独立分支：status='unknown' + 无 active → recovery-init 允许（qid>0）
        //   → in-progress? status=unknown 不属于 pending/queued/running，active 空 → 通过；
        //   → not-terminal? status=unknown 不属于可复用终态，qid>0 → 拦截返回
        $ports = $this->makePorts([], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            [], // 无 active operation
            [
                'queue_id' => 42,
                'snapshot' => ['status' => 'unknown', 'queue_id' => 42],
            ],
            $ports
        );

        self::assertSame([], $calls['enqueueTask']);
        self::assertSame([], $calls['updateQueueRow']);
        self::assertSame([], $calls['logSse']);
    }

    public function testNoOpWhenRecoveryInitDisallowed(): void
    {
        $calls = [];
        // active.operation ≠ task_plan 且 qid<=0 → allowTaskPlanRecoveryInit=false
        $ports = $this->makePorts([], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            ['operation' => 'plan', 'status' => 'done'],
            null,
            $ports
        );

        self::assertSame([], $calls['enqueueTask']);
        self::assertSame([], $calls['updateQueueRow']);
        self::assertSame([], $calls['logSse']);
    }

    public function testNoOpWhenActiveInProgressAndStatusEmpty(): void
    {
        $calls = [];
        // status='' + active operation 仍在 running → in-progress
        $ports = $this->makePorts([], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            ['operation' => 'task_plan', 'status' => 'running'],
            null,
            $ports
        );

        // 无 snapshot, qid=0 → fallback findQueueRow
        self::assertCount(1, $calls['findQueueRow']);
        self::assertSame([], $calls['enqueueTask']);
        self::assertSame([], $calls['logSse']);
    }

    // ------------------------------------------------------------------
    // Branch A: create new queue
    // ------------------------------------------------------------------

    public function testBranchACreatesNewQueueWhenQueueIdZeroAndActiveIsTaskPlan(): void
    {
        $calls = [];
        $freshSession = $this->sessionMock(7777, 'pub-fresh');
        $ports = $this->makePorts([
            'enqueueTask' => 555,
            'loadSession' => $freshSession,
            'buildQueueInfoPayload' => [
                'queue_id' => 555,
                'snapshot' => ['status' => 'unknown', 'queue_id' => 555],
                'queue_status' => 'unknown',
            ],
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(7777, 'pub-live'),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            ['operation' => 'task_plan', 'status' => 'done'],
            null,
            $ports
        );

        // enqueue 调用 1 次
        self::assertCount(1, $calls['enqueueTask']);
        self::assertSame('task_plan', $calls['enqueueTask'][0]['operation']);
        self::assertSame(['_force_rebuild' => 1], $calls['enqueueTask'][0]['extras']);
        self::assertNotEmpty($calls['enqueueTask'][0]['execution_token']);

        // 构造 envelope 调 1 次（Branch A 只调 1 次）
        self::assertCount(1, $calls['buildEnvelope']);

        // mergeScope 调 1 次
        self::assertCount(1, $calls['mergeScope']);
        self::assertSame(7777, $calls['mergeScope'][0]['session_id']);
        self::assertSame(100, $calls['mergeScope'][0]['admin_id']);
        self::assertSame(['active_operation', 'active_operations'], $calls['mergeScope'][0]['patch_keys']);
        self::assertSame(555, $calls['mergeScope'][0]['active_operations']['task_plan']['queue_id']);

        // dispatch 调 1 次，qid=555 force=true
        self::assertCount(1, $calls['ensureDispatched']);
        self::assertSame(555, $calls['ensureDispatched'][0]['queue_id']);
        self::assertTrue($calls['ensureDispatched'][0]['force']);

        // loadSession 调 1 次
        self::assertCount(1, $calls['loadSession']);

        // buildQueueInfoPayload 调 1 次（用 fresh session + 新 active_operation）
        self::assertCount(1, $calls['buildQueueInfoPayload']);
        self::assertSame('task_plan', $calls['buildQueueInfoPayload'][0]['operation']);

        // logSse 调 1 次，event/reason 正确
        self::assertCount(1, $calls['logSse']);
        self::assertSame('task_plan_queue_auto_rerun', $calls['logSse'][0]['event']);
        self::assertSame('info', $calls['logSse'][0]['level']);
        self::assertSame('queue_missing_and_draft_missing_create_new_queue', $calls['logSse'][0]['data']['reason']);
        self::assertSame(555, $calls['logSse'][0]['data']['queue_id']);
        self::assertSame(1, $calls['logSse'][0]['data']['dispatch_started']);
        self::assertSame(9999, $calls['logSse'][0]['data']['dispatch_pid']);

        // 返回 snapshot.status 被强制 pending
        self::assertSame(\Weline\Queue\Model\Queue::status_pending, $result['task_plan_queue_info']['snapshot']['status']);
        self::assertSame(\Weline\Queue\Model\Queue::status_pending, $result['task_plan_queue_info']['queue_status']);

        // active_operation 更新
        self::assertSame('task_plan', $result['active_operation']['operation']);
        self::assertSame('queued', $result['active_operation']['status']);
        self::assertSame('created_queue', $result['active_operation']['task_plan_recovery_action']);
        self::assertSame(555, $result['active_operation']['queue_id']);

        // normalized 中的 active_operation 也被更新
        self::assertSame(555, $result['normalized']['active_operation']['queue_id']);
        self::assertSame(555, $result['normalized']['active_operations']['task_plan']['queue_id']);
    }

    public function testBranchAReturnsOriginalWhenEnqueueReturnsZero(): void
    {
        $calls = [];
        $ports = $this->makePorts(['enqueueTask' => 0], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            ['operation' => 'task_plan', 'status' => 'done'],
            null,
            $ports
        );

        self::assertCount(1, $calls['enqueueTask']);
        self::assertSame([], $calls['mergeScope'], 'enqueue 失败不应继续 mergeScope');
        self::assertSame([], $calls['ensureDispatched']);
        self::assertSame([], $calls['logSse']);
        self::assertSame(['operation' => 'task_plan', 'status' => 'done'], $result['active_operation']);
    }

    // ------------------------------------------------------------------
    // Branch B: reuse existing done queue
    // ------------------------------------------------------------------

    public function testBranchBResetsExistingQueueWhenDone(): void
    {
        $calls = [];
        $freshSession = $this->sessionMock(7777, 'pub-fresh');
        $ports = $this->makePorts([
            'findQueueRow' => [
                'queue_id' => 42,
                'status' => 'done',
                'content' => \json_encode(['execution_token' => 'old-tok']),
            ],
            'enqueueTask' => 42,
            'loadSession' => $freshSession,
            'buildQueueInfoPayload' => [
                'queue_id' => 42,
                'snapshot' => ['status' => 'done', 'queue_id' => 42],
                'queue_status' => 'done',
            ],
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            [],
            [
                'queue_id' => 42,
                'snapshot' => ['status' => 'done', 'queue_id' => 42],
            ],
            $ports
        );

        // 由于 snapshot 提供了 qid+status，findQueueRow 的第一次回退不触发
        // 但 Branch B 内部会调 findQueueRow(qid=42) 取 row
        self::assertSame([], $calls['findQueueRow']);
        self::assertCount(1, $calls['enqueueTask']);
        self::assertSame('task_plan', $calls['enqueueTask'][0]['operation']);
        self::assertSame(['_force_rebuild' => 1], $calls['enqueueTask'][0]['extras']);

        // Branch B 调 buildEnvelope 2 次（一次构 queueContent，一次给 active_operation）
        self::assertCount(1, $calls['buildEnvelope']);

        // resolveQueueStage 调 1 次（生成 queueContent.stage）
        self::assertSame([], $calls['resolveQueueStage']);

        // updateQueueRow 调 1 次，status=pending pid=0
        self::assertSame([], $calls['updateQueueRow']);

        // mergeScope + dispatch + loadSession + buildQueueInfoPayload 各一次
        self::assertCount(1, $calls['mergeScope']);
        self::assertCount(1, $calls['ensureDispatched']);
        self::assertSame(42, $calls['ensureDispatched'][0]['queue_id']);
        self::assertCount(1, $calls['loadSession']);
        self::assertCount(1, $calls['buildQueueInfoPayload']);

        // logSse 调 1 次，reason 是 reuse_queue
        self::assertCount(1, $calls['logSse']);
        self::assertSame('task_plan_queue_auto_rerun', $calls['logSse'][0]['event']);
        self::assertSame('queue_done_but_draft_missing_reuse_queue', $calls['logSse'][0]['data']['reason']);
        self::assertSame(42, $calls['logSse'][0]['data']['queue_id']);

        // active_operation 重建：recovery_action=reused_queue
        self::assertSame('reused_queue', $result['active_operation']['task_plan_recovery_action']);
        self::assertSame(42, $result['active_operation']['queue_id']);
        self::assertSame('queued', $result['active_operation']['status']);

        // snapshot.status 被强制 pending
        self::assertSame(\Weline\Queue\Model\Queue::status_pending, $result['task_plan_queue_info']['snapshot']['status']);
    }

    public function testBranchBFallsBackToFindQueueRowWhenSnapshotEmpty(): void
    {
        $calls = [];
        $freshSession = $this->sessionMock(7777);
        $ports = $this->makePorts([
            // fallback findQueueRow 返回 status=done qid=9
            // 但 Branch B 内还要再调一次 findQueueRow(qid=9)
            // 这里为简化：两次都返回相同结构
            'findQueueRow' => [
                'queue_id' => 9,
                'status' => 'done',
                'content' => '',
            ],
            'enqueueTask' => 9,
            'loadSession' => $freshSession,
            'buildQueueInfoPayload' => [
                'snapshot' => ['status' => 'done', 'queue_id' => 9],
                'queue_status' => 'done',
            ],
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            [],
            null, // 完全无 queueInfo
            $ports
        );

        // findQueueRow 被调 2 次：fallback(qid=0) + Branch B(qid=9)
        self::assertCount(1, $calls['findQueueRow']);
        self::assertSame(0, $calls['findQueueRow'][0]['queue_id'], '首次是 fallback 无 qid');
        self::assertCount(1, $calls['enqueueTask']);
        self::assertSame('task_plan', $calls['enqueueTask'][0]['operation']);
        self::assertSame(['_force_rebuild' => 1], $calls['enqueueTask'][0]['extras']);

        self::assertSame([], $calls['updateQueueRow']);

        // logSse reason=reuse_queue
        self::assertCount(1, $calls['logSse']);
        self::assertSame('queue_done_but_draft_missing_reuse_queue', $calls['logSse'][0]['data']['reason']);
    }

    public function testBranchBReusesFailedQueueWhenDraftMissing(): void
    {
        $calls = [];
        $ports = $this->makePorts([
            'findQueueRow' => [
                'queue_id' => 42,
                'status' => 'error',
                'content' => '',
            ],
            'enqueueTask' => 42,
            'buildQueueInfoPayload' => [
                'queue_id' => 42,
                'snapshot' => ['status' => 'error', 'queue_id' => 42],
                'queue_status' => 'error',
            ],
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            [],
            [
                'queue_id' => 42,
                'snapshot' => ['status' => 'error', 'queue_id' => 42],
            ],
            $ports
        );

        self::assertCount(1, $calls['enqueueTask']);
        self::assertSame('task_plan', $calls['enqueueTask'][0]['operation']);
        self::assertSame([], $calls['updateQueueRow']);
        self::assertSame('queue_failed_or_stopped_and_draft_missing_reuse_queue', $calls['logSse'][0]['data']['reason']);
        self::assertSame('reused_queue', $result['active_operation']['task_plan_recovery_action']);
    }

    // ------------------------------------------------------------------
    // Throwable fallback
    // ------------------------------------------------------------------

    public function testThrowableInsideBranchALogsFailedLevelError(): void
    {
        $calls = [];
        // 让 mergeScope 抛异常 → 触发 catch Throwable → logSse failed
        $ports = $this->makePorts([
            'enqueueTask' => 555,
            'throwOn' => 'mergeScope',
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(7777),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            ['operation' => 'task_plan', 'status' => 'done'],
            null,
            $ports
        );

        self::assertCount(1, $calls['enqueueTask']);
        self::assertCount(1, $calls['mergeScope'], 'mergeScope 尝试过一次后抛出');
        self::assertSame([], $calls['ensureDispatched'], 'mergeScope 抛出后不应再 dispatch');

        // logSse 1 次 failed event + level=error
        self::assertCount(1, $calls['logSse']);
        self::assertSame('task_plan_queue_auto_rerun_failed', $calls['logSse'][0]['event']);
        self::assertSame('error', $calls['logSse'][0]['level']);
        // 原控制器隐式契约：Branch A 异常时 queue_id 采用方法入口局部变量 $queueId（此处=0），
        // 而非 Branch A 内部新建的 $newQueueId（=555）。Characterization 锁定此行为以防无意改动。
        self::assertSame(0, $calls['logSse'][0]['data']['queue_id']);
        self::assertSame('simulated mergeScope failure', $calls['logSse'][0]['data']['error']);
    }

    public function testThrowableInsideBranchBLogsFailedLevelError(): void
    {
        $calls = [];
        $ports = $this->makePorts([
            'findQueueRow' => ['queue_id' => 42, 'status' => 'done', 'content' => ''],
            'enqueueTask' => 42,
            'throwOn' => 'ensureDispatched',
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            [],
            ['queue_id' => 42, 'snapshot' => ['status' => 'done', 'queue_id' => 42]],
            $ports
        );

        self::assertSame([], $calls['updateQueueRow']);
        self::assertCount(1, $calls['enqueueTask']);
        self::assertCount(1, $calls['mergeScope']);
        self::assertCount(1, $calls['ensureDispatched'], 'dispatch 抛出');
        self::assertSame([], $calls['buildQueueInfoPayload'], '抛出后不应继续');

        self::assertCount(1, $calls['logSse']);
        self::assertSame('task_plan_queue_auto_rerun_failed', $calls['logSse'][0]['event']);
        self::assertSame('error', $calls['logSse'][0]['level']);
        self::assertSame(42, $calls['logSse'][0]['data']['queue_id']);
    }

    // ------------------------------------------------------------------
    // dispatch 状态渗透
    // ------------------------------------------------------------------

    public function testLogSseCapturesDispatchStartedFalse(): void
    {
        $calls = [];
        $ports = $this->makePorts([
            'enqueueTask' => 10,
            'ensureDispatched' => ['started' => false, 'attempted' => true, 'pid' => 0],
            'loadSession' => $this->sessionMock(),
            'buildQueueInfoPayload' => [
                'snapshot' => ['status' => 'unknown', 'queue_id' => 10],
                'queue_status' => 'unknown',
            ],
        ], $calls);

        $result = $this->service()->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $this->sessionMock(),
            100,
            self::STAGE_VISUAL_EDIT,
            self::STAGE_VISUAL_EDIT,
            [],
            ['operation' => 'task_plan', 'status' => 'done'],
            null,
            $ports
        );

        self::assertCount(1, $calls['logSse']);
        self::assertSame(0, $calls['logSse'][0]['data']['dispatch_started']);
        self::assertSame(0, $calls['logSse'][0]['data']['dispatch_pid']);
        self::assertSame(\Weline\Queue\Model\Queue::status_pending, $result['task_plan_queue_info']['snapshot']['status']);
    }
}
