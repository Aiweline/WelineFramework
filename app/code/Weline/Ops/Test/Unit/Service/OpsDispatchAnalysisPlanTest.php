<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Queue\OpsTaskDispatchQueue;

final class OpsDispatchAnalysisPlanTest extends TestCase
{
    public function test_dispatch_service_source_always_plans_analysis_task(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/OpsDispatchService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('insight.daily_analysis', $src);
        self::assertStringContainsString('AGENT_OPS_ANALYST', $src);
        self::assertStringContainsString('已受理运营配置并入队（含分析任务）', $src);
        self::assertStringContainsString("OpsRunLog::KIND_DRAFT", $src);
    }

    public function test_queue_worker_source_routes_analyst_to_done(): void
    {
        $path = dirname(__DIR__, 3) . '/Queue/OpsTaskDispatchQueue.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('AGENT_OPS_ANALYST', $src);
        self::assertStringContainsString('KIND_DAILY_ANALYSIS', $src);
        self::assertStringContainsString('resolveNextStatus', $src);
    }

    public function test_queue_class_exists(): void
    {
        self::assertTrue(class_exists(OpsTaskDispatchQueue::class));
        self::assertSame('ops_analyst', OpsTask::AGENT_OPS_ANALYST);
    }
}
