<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Contract: AfterSale 纳入 dead、懒补补偿、中文化状态/补偿列。 */
final class AfterSaleDeadContractTest extends TestCase
{
    public function testAfterSaleListsDeadAndCompensation(): void
    {
        $root = dirname(__DIR__, 3);
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/AfterSale.php');
        $tpl = (string)file_get_contents($root . '/view/templates/Backend/AfterSale/index.phtml');

        self::assertStringContainsString('STATUS_DEAD', $ctrl);
        self::assertStringContainsString('STATUS_ERROR', $ctrl);
        self::assertStringContainsString('compensation', $ctrl);
        self::assertStringContainsString('maybeBackfillTerminalCompensation', $ctrl);
        self::assertStringContainsString('maybeRecoverExistingFulfillment', $ctrl);
        self::assertStringContainsString('recovered_existing', $ctrl);
        self::assertStringContainsString('compensation_error_code', $ctrl);
        self::assertStringContainsString('待补偿', $ctrl);
        self::assertStringContainsString('无法履约', $ctrl);
        self::assertStringContainsString('已对齐远端订单', $ctrl);
        self::assertStringContainsString('compensation', $tpl);
        self::assertStringContainsString('data-testid="dropship-aftersale-compensation"', $tpl);
        self::assertStringContainsString('data-testid="dropship-aftersale-status"', $tpl);
        self::assertStringContainsString('data-testid="dropship-aftersale-empty"', $tpl);
        self::assertStringContainsString('data-testid="dropship-aftersale-goto-orders"', $tpl);
        self::assertStringContainsString('data-testid="dropship-aftersale-goto-config"', $tpl);
        self::assertStringContainsString('w-card', $tpl);
        self::assertStringContainsString('推单异常与补偿', $tpl);
        self::assertStringContainsString('查看履约订单', $tpl);
        self::assertStringContainsString('补偿进度', $tpl);
        self::assertStringContainsString('错误原因', $tpl);
        self::assertStringContainsString('平台代码', $tpl);
        self::assertStringContainsString('orders_url', $ctrl);
        self::assertStringContainsString('config_url', $ctrl);
    }
}
