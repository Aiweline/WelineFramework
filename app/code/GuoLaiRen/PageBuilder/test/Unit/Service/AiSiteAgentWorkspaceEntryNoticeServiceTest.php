<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceEntryNoticeService;
use PHPUnit\Framework\TestCase;

final class AiSiteAgentWorkspaceEntryNoticeServiceTest extends TestCase
{
    private function service(): AiSiteAgentWorkspaceEntryNoticeService
    {
        return new AiSiteAgentWorkspaceEntryNoticeService();
    }

    public function testReturnsFalseWhenNoQueueInfoAvailable(): void
    {
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(['operation' => 'plan'], [])
        );
    }

    public function testReturnsFalseWhenSnapshotMissing(): void
    {
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(['operation' => 'plan'], ['plan' => ['queue_id' => 10]])
        );
    }

    public function testReturnsFalseWhenQueueIdOrStatusMissing(): void
    {
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(
                ['operation' => 'plan'],
                ['plan' => ['queue_id' => 0, 'snapshot' => ['status' => 'running']]]
            )
        );
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(
                ['operation' => 'plan', 'status' => ''],
                ['plan' => ['queue_id' => 5, 'snapshot' => ['status' => '']]]
            )
        );
    }

    public function testFallbackChecksBuildThenPlanOnly(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'plan' => ['queue_id' => 1, 'snapshot' => ['status' => 'running', 'queue_id' => 1]],
                'task_plan' => ['queue_id' => 2, 'snapshot' => ['status' => 'running', 'queue_id' => 2]],
                'build' => ['queue_id' => 3, 'snapshot' => ['status' => 'running', 'queue_id' => 3]],
            ]
        );

        self::assertTrue($result['show']);
        self::assertSame('build', $result['operation']);
        self::assertSame(3, $result['queue_id']);
    }

    public function testFallbackSkipsTerminalBuildAndIgnoresLegacyTaskPlan(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'build' => ['queue_id' => 99, 'snapshot' => ['status' => 'done', 'queue_id' => 99]],
                'task_plan' => ['queue_id' => 88, 'snapshot' => ['status' => 'running', 'queue_id' => 88]],
                'plan' => ['queue_id' => 77, 'snapshot' => ['status' => 'pending', 'queue_id' => 77]],
            ]
        );

        self::assertSame('plan', $result['operation']);
        self::assertSame(77, $result['queue_id']);
    }

    public function testLegacyTaskPlanOperationDoesNotRenderNotice(): void
    {
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(
                ['operation' => 'task_plan'],
                ['task_plan' => ['queue_id' => 42, 'snapshot' => ['status' => 'running', 'queue_id' => 42]]]
            )
        );
    }

    public function testUsesActiveStatusWhenSnapshotStatusEmpty(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan', 'status' => 'pending'],
            ['plan' => ['queue_id' => 10, 'snapshot' => ['status' => '', 'queue_id' => 10]]]
        );

        self::assertTrue($result['show']);
        self::assertSame('pending', $result['queue_status']);
        self::assertSame('warning', $result['level']);
    }

    public function testBuildsStatusSpecificNotices(): void
    {
        $pending = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 10, 'snapshot' => ['status' => 'pending', 'queue_id' => 10]]]
        );
        self::assertSame('warning', $pending['level']);
        self::assertStringContainsString('#10', $pending['message']);

        $running = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'running', 'queue_id' => 20]]]
        );
        self::assertSame('warning', $running['level']);

        $error = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'error', 'queue_id' => 20]]]
        );
        self::assertSame('error', $error['level']);

        $done = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'done', 'queue_id' => 20]]]
        );
        self::assertSame('success', $done['level']);
    }

    public function testUnknownStatusUsesInfoAndOriginalLabel(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'snapshot' => ['status' => 'weird', 'queue_id' => 20]]]
        );

        self::assertSame('info', $result['level']);
        self::assertSame('weird', $result['queue_status']);
        self::assertSame('weird', $result['queue_status_label']);
    }

    public function testResultExcerptUsesLast600Chars(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => [
                'queue_id' => 10,
                'snapshot' => ['status' => 'error', 'queue_id' => 10],
                'result_log' => \str_repeat('A', 700),
            ]]
        );

        self::assertSame(600, \mb_strlen($result['result_excerpt']));
    }

    public function testSnapshotNameBizKeyAndProcessPropagate(): void
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
            ]]
        );

        self::assertSame('my-queue', $result['queue_name']);
        self::assertSame('biz-xyz', $result['biz_key']);
        self::assertSame('phase-2/5', $result['process']);
    }
}
