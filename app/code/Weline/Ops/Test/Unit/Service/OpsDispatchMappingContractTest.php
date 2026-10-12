<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Queue\OpsTaskDispatchQueue;

final class OpsDispatchMappingContractTest extends TestCase
{
    public function test_dispatch_service_source_freezes_assignee_and_approval_gates(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/OpsDispatchService.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('AGENT_BLOG_PUBLISH', $src);
        self::assertStringContainsString('AGENT_SOCIAL_OPS', $src);
        self::assertStringContainsString('IdempotentQueueAdmission', $src);
        self::assertStringContainsString('ops_task:', $src);
        self::assertStringContainsString('createPublishBatch', $src);
        self::assertStringContainsString("'website_id' => \$websiteId", $src);
        self::assertStringContainsString('isApproveEligible', $src);
        self::assertStringContainsString('isTerminalPublishFailure', $src);
        self::assertStringContainsString('STATUS_AWAITING_APPROVAL', $src);
        self::assertStringContainsString('recoverable', $src);
        self::assertStringContainsString('STATUS_PUBLISHED', $src);
        self::assertSame('blog_publish', OpsTask::AGENT_BLOG_PUBLISH);
        self::assertSame('social_ops', OpsTask::AGENT_SOCIAL_OPS);
    }

    public function test_desk_and_task_templates_allow_retry_approve_on_failed_outbound(): void
    {
        $desk = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/Desk/index.phtml');
        $task = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/backend/Task/index.phtml');
        self::assertStringContainsString('canApproveOutbound', $desk);
        self::assertStringContainsString('再次批准外发', $desk);
        self::assertStringContainsString('canApproveOutbound', $task);
        self::assertStringContainsString('再次批准外发', $task);
    }

    public function test_queue_consumer_claims_then_execute_agent(): void
    {
        $path = dirname(__DIR__, 3) . '/Queue/OpsTaskDispatchQueue.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('claimRunning', $src);
        self::assertStringContainsString('executeAgent', $src);
        self::assertStringContainsString('STATUS_AWAITING_APPROVAL', $src);
        self::assertTrue(is_a(OpsTaskDispatchQueue::class, \Weline\Queue\Api\QueueConsumerInterface::class, true));
    }

    public function test_website_ops_agent_has_dispatch_tool(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Ai/Agent/WebsiteOpsAgent.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('accept_ops_dispatch_config', $src);
        self::assertStringContainsString('OpsDispatchService', $src);
    }
}
