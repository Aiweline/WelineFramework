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

    public function testUsesTopLevelQueueStateWhenNestedStateMissing(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 10, 'status' => 'running', 'name' => 'my-queue']]
        );

        self::assertTrue($result['show']);
        self::assertSame(10, $result['queue_id']);
        self::assertSame('running', $result['queue_status']);
        self::assertSame('my-queue', $result['queue_name']);
    }

    public function testReturnsFalseWhenQueueIdOrStatusMissing(): void
    {
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(
                ['operation' => 'plan'],
                ['plan' => ['queue_id' => 0, 'status' => 'running']]
            )
        );
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(
                ['operation' => 'plan', 'status' => ''],
                ['plan' => ['queue_id' => 5, 'status' => '']]
            )
        );
    }

    public function testFallbackChecksBuildThenPlanOnly(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'plan' => ['queue_id' => 1, 'status' => 'running'],
                'task_plan' => ['queue_id' => 2, 'status' => 'running'],
                'build' => ['queue_id' => 3, 'status' => 'running'],
            ]
        );

        self::assertTrue($result['show']);
        self::assertSame('build', $result['operation']);
        self::assertSame(3, $result['queue_id']);
    }

    public function testScopedBuildBucketOperationsRenderNotice(): void
    {
        $imageAsset = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'image_asset'],
            ['image_asset' => ['queue_id' => 4, 'status' => 'running']]
        );

        self::assertTrue($imageAsset['show']);
        self::assertSame('image_asset', $imageAsset['operation']);
        self::assertSame(4, $imageAsset['queue_id']);

        $fallback = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'plan' => ['queue_id' => 1, 'status' => 'running'],
                'block_partial_patch' => ['queue_id' => 5, 'status' => 'queued'],
            ]
        );

        self::assertTrue($fallback['show']);
        self::assertSame('block_partial_patch', $fallback['operation']);
        self::assertSame(5, $fallback['queue_id']);
    }

    public function testFallbackPrefersPublishWhenPublishQueueIsRunning(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'plan' => ['queue_id' => 1, 'status' => 'running'],
                'build' => ['queue_id' => 2, 'status' => 'running'],
                'publish' => ['queue_id' => 3, 'status' => 'running'],
            ]
        );

        self::assertTrue($result['show']);
        self::assertSame('publish', $result['operation']);
        self::assertSame('发布站点', $result['operation_label']);
        self::assertSame(3, $result['queue_id']);
    }

    public function testFallbackSkipsTerminalBuildAndIgnoresRemovedTaskPlan(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            [],
            [
                'build' => ['queue_id' => 99, 'status' => 'done'],
                'task_plan' => ['queue_id' => 88, 'status' => 'running'],
                'plan' => ['queue_id' => 77, 'status' => 'pending'],
            ]
        );

        self::assertSame('plan', $result['operation']);
        self::assertSame(77, $result['queue_id']);
    }

    public function testRemovedTaskPlanOperationDoesNotRenderNotice(): void
    {
        self::assertSame(
            ['show' => false],
            $this->service()->buildWorkspaceEntryQueueNotice(
                ['operation' => 'task_plan'],
                ['task_plan' => ['queue_id' => 42, 'status' => 'running']]
            )
        );
    }

    public function testUsesActiveStatusWhenQueueStatusEmpty(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan', 'status' => 'pending'],
            ['plan' => ['queue_id' => 10, 'status' => '']]
        );

        self::assertTrue($result['show']);
        self::assertSame('pending', $result['queue_status']);
        self::assertSame('warning', $result['level']);
    }

    public function testBuildsStatusSpecificNotices(): void
    {
        $pending = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => ['queue_id' => 10, 'status' => 'pending']]
        );
        self::assertSame('warning', $pending['level']);
        self::assertStringContainsString('#10', $pending['message']);

        $running = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'status' => 'running']]
        );
        self::assertSame('warning', $running['level']);

        $error = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'status' => 'error']]
        );
        self::assertSame('error', $error['level']);

        $done = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'status' => 'done']]
        );
        self::assertSame('success', $done['level']);

        $publish = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'publish'],
            ['publish' => ['queue_id' => 30, 'status' => 'running']]
        );
        self::assertSame('warning', $publish['level']);
        self::assertSame('发布站点', $publish['operation_label']);
        self::assertStringContainsString('发布站点', $publish['message']);
    }

    public function testUnknownStatusUsesInfoAndOriginalLabel(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'build'],
            ['build' => ['queue_id' => 20, 'status' => 'weird']]
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
                'status' => 'error',
                'result_log' => \str_repeat('A', 700),
            ]]
        );

        self::assertSame(600, \mb_strlen($result['result_excerpt']));
    }

    public function testQueueNameBizKeyAndProcessPropagate(): void
    {
        $result = $this->service()->buildWorkspaceEntryQueueNotice(
            ['operation' => 'plan'],
            ['plan' => [
                'queue_id' => 10,
                'status' => 'running',
                'name' => 'my-queue',
                'biz_key' => 'biz-xyz',
                'process' => 'phase-2/5',
            ]]
        );

        self::assertSame('my-queue', $result['queue_name']);
        self::assertSame('biz-xyz', $result['biz_key']);
        self::assertSame('phase-2/5', $result['process']);
    }
}
