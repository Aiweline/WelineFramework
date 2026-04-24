<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceEntryNoticeService;
use PHPUnit\Framework\TestCase;

/**
 * Characterization Test：锁定 `buildWorkspaceEntryQueueNotice` 原控制器私有方法行为。
 *
 * 关键分支：
 *  - show=false ×3（无 queueInfo / 无 snapshot / queueId<=0 || status='')
 *  - operation fallback 到 build / task_plan / plan（status 必须 ∈ running|pending|error）
 *  - 恢复型 notice（warning / 固定 title / 条件 message）×3（created_queue / reused_queue / message 保留）
 *  - 通用型 notice（各 status match 出不同 level / message）×5
 *  - 默认分支 status 未知：level=info，message 用 status labelize default
 */
final class AiSiteAgentWorkspaceEntryNoticeServiceTest extends TestCase
{
    private function service(): AiSiteAgentWorkspaceEntryNoticeService
    {
        return new AiSiteAgentWorkspaceEntryNoticeService();
    }

    /** @return \Closure(array): string */
    private function recoveryStub(string $ret = ''): \Closure
    {
        return fn (array $_): string => $ret;
    }

    // ---- show=false 分支 --------------------------------------------

    public function testReturnsFalseWhenNoQueueInfoAvailable(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            [],
            $this->recoveryStub()
        );
        self::assertSame(['show' => false], $result);
    }

    public function testReturnsFalseWhenSnapshotMissing(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 10]],
            $this->recoveryStub()
        );
        self::assertSame(['show' => false], $result);
    }

    public function testReturnsFalseWhenQueueIdZero(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 0, 'snapshot' => ['status' => 'running', 'queue_id' => 0]]],
            $this->recoveryStub()
        );
        self::assertSame(['show' => false], $result);
    }

    public function testReturnsFalseWhenStatusEmpty(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan', 'status' => ''],
            ['plan' => ['queue_id' => 5, 'snapshot' => ['status' => '']]],
            $this->recoveryStub()
        );
        self::assertSame(['show' => false], $result);
    }

    // ---- 回退扫描（operation 空 / 无匹配） ---------------------------

    public function testFallsBackToBuildOperationWhenActiveOperationEmpty(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'build' => ['queue_id' => 77, 'snapshot' => ['status' => 'running', 'queue_id' => 77]],
            ],
            $this->recoveryStub()
        );
        self::assertTrue($result['show']);
        self::assertSame('build', $result['operation']);
        self::assertSame(77, $result['queue_id']);
    }

    public function testFallbackPrefersBuildThenTaskPlanThenPlan(): void
    {
        // 都存在 running；build 优先
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'plan' => ['queue_id' => 1, 'snapshot' => ['status' => 'running', 'queue_id' => 1]],
                'task_plan' => ['queue_id' => 2, 'snapshot' => ['status' => 'running', 'queue_id' => 2]],
                'build' => ['queue_id' => 3, 'snapshot' => ['status' => 'running', 'queue_id' => 3]],
            ],
            $this->recoveryStub()
        );
        self::assertSame('build', $result['operation']);
        self::assertSame(3, $result['queue_id']);
    }

    public function testFallbackSkipsOperationsInTerminalStatus(): void
    {
        // build done → 跳过；回退到 task_plan running
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'build' => ['queue_id' => 99, 'snapshot' => ['status' => 'done', 'queue_id' => 99]],
                'task_plan' => ['queue_id' => 88, 'snapshot' => ['status' => 'running', 'queue_id' => 88]],
                'plan' => ['queue_id' => 77, 'snapshot' => ['status' => 'pending', 'queue_id' => 77]],
            ],
            $this->recoveryStub()
        );
        self::assertSame('task_plan', $result['operation']);
    }

    public function testFallbackReturnsFalseIfNoRunningPendingOrErrorCandidates(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'build' => ['queue_id' => 99, 'snapshot' => ['status' => 'done', 'queue_id' => 99]],
            ],
            $this->recoveryStub()
        );
        self::assertSame(['show' => false], $result);
    }

    // ---- active_status 降级 -----------------------------------------

    public function testUsesActiveStatusWhenSnapshotStatusEmpty(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan', 'status' => 'pending'],
            ['plan' => ['queue_id' => 10, 'snapshot' => ['status' => '', 'queue_id' => 10]]],
            $this->recoveryStub()
        );
        self::assertTrue($result['show']);
        self::assertSame('pending', $result['queue_status']);
        self::assertSame('warning', $result['level']);
    }

    // ---- 通用型 notice 五分支 ----------------------------------------

    public function testBuildsPendingNotice(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 10, 'snapshot' => ['status' => 'pending', 'queue_id' => 10]]],
            $this->recoveryStub()
        );
        self::assertTrue($result['show']);
        self::assertSame('warning', $result['level']);
        self::assertSame('pending', $result['queue_status']);
        self::assertStringContainsString('#10', $result['message']);
    }

    public function testBuildsRunningNotice(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'running', 'queue_id' => 20]]],
            $this->recoveryStub()
        );
        self::assertTrue($result['show']);
        self::assertSame('warning', $result['level']);
        self::assertSame('running', $result['queue_status']);
    }

    public function testBuildsErrorNotice(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'error', 'queue_id' => 20]]],
            $this->recoveryStub()
        );
        self::assertSame('error', $result['level']);
        self::assertSame('error', $result['queue_status']);
    }

    public function testBuildsDoneNotice(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'done', 'queue_id' => 20]]],
            $this->recoveryStub()
        );
        self::assertSame('success', $result['level']);
        self::assertSame('done', $result['queue_status']);
    }

    public function testBuildsUnknownStatusUsesDefaultLevelInfo(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'weird', 'queue_id' => 20]]],
            $this->recoveryStub()
        );
        self::assertSame('info', $result['level']);
        self::assertSame('weird', $result['queue_status']);
        self::assertSame('weird', $result['queue_status_label']);
        self::assertStringContainsString('当前状态', $result['message']);
    }

    // ---- 恢复型 notice（task_plan + recovery action） ------------------

    public function testBuildsRecoveryNoticeForCreatedQueue(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'task_plan'],
            ['task_plan' => ['queue_id' => 42, 'snapshot' => ['status' => 'running', 'queue_id' => 42]]],
            $this->recoveryStub('created_queue')
        );
        self::assertTrue($result['show']);
        self::assertSame('warning', $result['level']);
        self::assertSame('已初始化队列状态', $result['title']);
        self::assertSame('已初始化', $result['queue_status_label']);
        self::assertStringContainsString('初始化队列 #42', $result['message']);
    }

    public function testBuildsRecoveryNoticeForReusedQueue(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'task_plan'],
            ['task_plan' => ['queue_id' => 42, 'snapshot' => ['status' => 'running', 'queue_id' => 42]]],
            $this->recoveryStub('reused_queue')
        );
        self::assertSame('warning', $result['level']);
        self::assertSame('已重跑', $result['queue_status_label']);
        self::assertStringContainsString('重跑原队列 #42', $result['message']);
    }

    public function testRecoveryNoticeRetainsActiveMessageWhenCoherent(): void
    {
        // active_operation.message 已有"重跑"字样 + action=reused_queue → 保留原 message
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [
                'operation' => 'task_plan',
                'message' => '已重跑队列 42（自定义消息）',
            ],
            ['task_plan' => ['queue_id' => 42, 'snapshot' => ['status' => 'running', 'queue_id' => 42]]],
            $this->recoveryStub('reused_queue')
        );
        self::assertSame('已重跑队列 42（自定义消息）', $result['message']);
    }

    public function testRecoveryNoticeOverwritesActiveMessageWhenInconsistent(): void
    {
        // action=created_queue 但 active message 只写了"重跑"字样（关键字不匹配）→ 覆盖默认文案
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [
                'operation' => 'task_plan',
                'message' => '已重跑队列',
            ],
            ['task_plan' => ['queue_id' => 42, 'snapshot' => ['status' => 'running', 'queue_id' => 42]]],
            $this->recoveryStub('created_queue')
        );
        self::assertStringContainsString('初始化队列 #42', $result['message']);
        self::assertStringNotContainsString('已重跑队列', $result['message']);
    }

    public function testRecoveryNoticeIgnoredForNonTaskPlanOperation(): void
    {
        // plan operation + recovery=reused_queue 不应触发恢复型 notice，走通用型
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 42, 'snapshot' => ['status' => 'running', 'queue_id' => 42]]],
            $this->recoveryStub('reused_queue')
        );
        self::assertSame('已读取队列状态', $result['title']);
        self::assertSame('warning', $result['level']);
    }

    // ---- Result excerpt 截断 ----------------------------------------

    public function testResultExcerptUsesLast600Chars(): void
    {
        $longLog = \str_repeat('A', 700);
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => [
                'queue_id' => 10,
                'snapshot' => ['status' => 'error', 'queue_id' => 10],
                'result_log' => $longLog,
            ]],
            $this->recoveryStub()
        );
        self::assertSame(600, \mb_strlen($result['result_excerpt']));
    }

    public function testResultExcerptEmptyWhenNoResultLog(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 10, 'snapshot' => ['status' => 'error', 'queue_id' => 10]]],
            $this->recoveryStub()
        );
        self::assertSame('', $result['result_excerpt']);
    }

    // ---- 队列元数据透传 ---------------------------------------------

    public function testSnapshotNameAndBizKeyPropagated(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => [
                'queue_id' => 10,
                'snapshot' => [
                    'status' => 'running',
                    'queue_id' => 10,
                    'name' => 'my-queue',
                    'biz_key' => 'biz-xyz',
                ],
                'process' => 'phase-2/5',
            ]],
            $this->recoveryStub()
        );
        self::assertSame('my-queue', $result['queue_name']);
        self::assertSame('biz-xyz', $result['biz_key']);
        self::assertSame('phase-2/5', $result['process']);
    }
}
