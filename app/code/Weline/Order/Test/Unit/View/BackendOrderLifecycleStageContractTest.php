<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderLifecycleStageContractTest extends TestCase
{
    public function testStatusFlowAndEditExposeLifecycleTestids(): void
    {
        $root = dirname(__DIR__, 3);
        $flow = (string)file_get_contents($root . '/view/templates/backend/Order/partial/status-flow.phtml');
        $edit = (string)file_get_contents($root . '/view/templates/backend/Order/edit.phtml');
        $index = (string)file_get_contents($root . '/view/templates/backend/Order/index.phtml');
        self::assertStringContainsString('order-lifecycle-stage-flow', $flow);
        self::assertStringContainsString('order-edit-lifecycle-stage', $edit);
        self::assertStringContainsString('order-edit-hang-review-hint', $edit);
        self::assertStringContainsString('order-edit-hang-review-hint', $flow);
        self::assertStringContainsString('通过审查后客户方可支付尾款', $edit);
        self::assertStringContainsString("lifecycleStage['actions']", $edit);
        self::assertStringContainsString("lifeAction['testid']", $edit);
        self::assertStringContainsString('@backend-url{$lifePath}', $edit);
        self::assertStringContainsString("str_starts_with(\$lifeTestid, 'order-edit-')", $flow);
        self::assertStringContainsString("'order-lifecycle-'", $flow);
        self::assertStringContainsString('order-list-lifecycle-stage', $index);
        self::assertStringContainsString('order-type-badge', $index);
    }
}
