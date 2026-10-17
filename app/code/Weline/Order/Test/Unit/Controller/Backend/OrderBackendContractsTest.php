<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllOrderBackendContractsPass().
 */
final class OrderBackendContractsTest extends TestCase
{
    public function testAllOrderBackendContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BackendOrderPageTitle::testViewAndEditAssignOrderEditTitle' => function () {
            $this->setUp_BackendOrderPageTitle();
            $this->assert_BackendOrderPageTitle_testViewAndEditAssignOrderEditTitle();
        },
            'BackendOrderPageTitle::testIndexAssignsOrderListTitle' => function () {
            $this->setUp_BackendOrderPageTitle();
            $this->assert_BackendOrderPageTitle_testIndexAssignsOrderListTitle();
        },
            'BackendOrderPageTitle::testHelperAssignsTitleAndPageTitle' => function () {
            $this->setUp_BackendOrderPageTitle();
            $this->assert_BackendOrderPageTitle_testHelperAssignsTitleAndPageTitle();
        },
        ];
    }

private string $src_BackendOrderPageTitle;

private function setUp_BackendOrderPageTitle(): void
{
    $this->src_BackendOrderPageTitle = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Controller/Backend/Order.php');
}

private function assert_BackendOrderPageTitle_testViewAndEditAssignOrderEditTitle(): void
{
    self::assertStringContainsString("assignBackendPageTitle((string)__('订单编辑'))", $this->src_BackendOrderPageTitle);
    self::assertGreaterThanOrEqual(2, substr_count($this->src_BackendOrderPageTitle, "assignBackendPageTitle((string)__('订单编辑'))"), 'view 与 edit 都必须写入「订单编辑」顶栏标题');
}

private function assert_BackendOrderPageTitle_testIndexAssignsOrderListTitle(): void
{
    self::assertStringContainsString("assignBackendPageTitle((string)__('订单列表'))", $this->src_BackendOrderPageTitle);
}

private function assert_BackendOrderPageTitle_testHelperAssignsTitleAndPageTitle(): void
{
    self::assertStringContainsString("\$this->assign('title', \$title);", $this->src_BackendOrderPageTitle);
    self::assertStringContainsString("\$this->assign('page_title', \$title);", $this->src_BackendOrderPageTitle);
    self::assertStringNotContainsString("assign('title', __('WelineFramework Admin'))", $this->src_BackendOrderPageTitle);
}
}
