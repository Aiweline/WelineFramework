<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllOrderUnitContractsPass().
 */
final class OrderUnitContractsTest extends TestCase
{
    public function testAllOrderUnitContractsPass(): void
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
            'OrderNoticeWidget::testWidgetRegistrationPinsMiniCartFooterExtrasSlot' => function () {
            $this->assert_OrderNoticeWidget_testWidgetRegistrationPinsMiniCartFooterExtrasSlot();
        },
            'OrderNoticeWidget::testOrderNoticeTemplateExists' => function () {
            $this->assert_OrderNoticeWidget_testOrderNoticeTemplateExists();
        },
            'OrderNoticeWidget::testOrderNoticeScriptUsesAsyncOrderApiResource' => function () {
            $this->assert_OrderNoticeWidget_testOrderNoticeScriptUsesAsyncOrderApiResource();
        },
            'OrderListMenuSignal::testProviderTargetsOrderListAndViewDeepLink' => function () {
            $this->assert_OrderListMenuSignal_testProviderTargetsOrderListAndViewDeepLink();
        },
        ];
    }

private function assert_OrderNoticeWidget_testWidgetRegistrationPinsMiniCartFooterExtrasSlot(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/extends/module/Weline_Widget/Weline_Order/widget.php';
    $tpl = 'Weline_Order::templates/frontend/widgets/order-notice.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/frontend/widgets/order-notice.phtml');
    self::assertStringContainsString('@widget.code {order-notice}', $src);
    self::assertStringContainsString('@widget.slot {footer-extras}', $src);
    self::assertStringContainsString('@widget.cache {3600}', $src);
    self::assertStringContainsString('@widget.share {true}', $src);
    self::assertStringContainsString('@widget.page_layouts {["mini-cart","cart","checkout"]}', $src);
    self::assertStringContainsString('"layout_type":"mini-cart"', $src);
    self::assertStringContainsString('"layout_type":"cart"', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"slot":"footer-extras"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_OrderNoticeWidget_testOrderNoticeTemplateExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/frontend/widgets/order-notice.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-order-notice-input', $source);
    self::assertStringContainsString('data-mini-cart-tab-label', $source);
    self::assertStringContainsString('data-order-notice-surface', $source);
    self::assertStringContainsString('w-order-notice--summary', $source);
    self::assertStringContainsString('orderNotice', $source);
}

private function assert_OrderNoticeWidget_testOrderNoticeScriptUsesAsyncOrderApiResource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/statics/js/widgets/order-notice.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('async function orderApi()', $source);
    self::assertStringContainsString("resource('order')", $source);
    self::assertStringContainsString('await orderApi()', $source);
}

private function assert_OrderListMenuSignal_testProviderTargetsOrderListAndViewDeepLink(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Order';
    $provider = (string) file_get_contents($root . '/extends/module/Weline_Admin/BackendMenuSignalProvider/OrderListMenuSignalProvider.php');
    $observer = (string) file_get_contents($root . '/Observer/OrderCreatedObserver.php');
    $controller = (string) file_get_contents($root . '/Controller/Backend/Order.php');
    self::assertStringContainsString('BackendMenuSignalInbox', $provider);
    self::assertStringContainsString("'unread' => true", $provider);
    self::assertStringContainsString("MENU_SOURCE_ID = 'Weline_Order::order_list'", $provider);
    self::assertStringContainsString("getBackendUrl('order/backend/order/view'", $provider);
    self::assertStringNotContainsString('countOpen', $provider);
    self::assertStringContainsString('publishUnreadMenuMessage', $observer);
    self::assertStringContainsString('markOrderMenuMessageRead', $controller);
    self::assertFileDoesNotExist($root . '/Service/BackendOrderMenuAttentionService.php');
}
}
