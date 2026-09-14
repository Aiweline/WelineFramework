<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendOrderStatusFlowContractTest extends TestCase
{
    public function testEditAndViewMountStatusFlowBeforeSummary(): void
    {
        $root = dirname(__DIR__, 3);
        $edit = (string)file_get_contents($root . '/view/templates/Backend/Order/edit.phtml');
        $view = (string)file_get_contents($root . '/view/templates/Backend/Order/view.phtml');
        $partial = (string)file_get_contents($root . '/view/templates/Backend/Order/partial/status-flow.phtml');

        self::assertNotSame('', $edit);
        self::assertNotSame('', $view);
        self::assertNotSame('', $partial);
        self::assertStringContainsString('Weline_Order::templates/Backend/Order/partial/status-flow.phtml', $edit);
        self::assertStringContainsString('Weline_Order::templates/Backend/Order/partial/status-flow.phtml', $view);
        self::assertStringContainsString('data-testid="order-status-flow"', $partial);
        self::assertStringContainsString('data-testid="order-status-flow-steps"', $partial);
        self::assertStringContainsString('data-testid="order-status-flow-refunds"', $partial);
        self::assertStringContainsString('data-testid="order-status-flow-current"', $partial);

        $editFetch = strpos($edit, 'partial/status-flow.phtml');
        $editSummary = strpos($edit, 'data-testid="order-edit-summary"');
        self::assertNotFalse($editFetch);
        self::assertNotFalse($editSummary);
        self::assertLessThan($editSummary, $editFetch);

        $viewFetch = strpos($view, 'partial/status-flow.phtml');
        $viewInfo = strpos($view, '订单基本信息');
        self::assertNotFalse($viewFetch);
        self::assertNotFalse($viewInfo);
        self::assertLessThan($viewInfo, $viewFetch);
    }
}
