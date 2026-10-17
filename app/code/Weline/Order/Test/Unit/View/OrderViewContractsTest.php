<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Order\Controller\Backend\Order as BackendOrderController;

/**
 * Aggregated contract suite (merged from 21 *ContractTest files).
 * Observe one overall pass/fail via testAllOrderViewContractsPass().
 */
final class OrderViewContractsTest extends TestCase
{
    public function testAllOrderViewContractsPass(): void
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
            'AccountOrderGroupChrome::testAccountOrdersTemplateUsesSplitVsShipmentCopy' => function () {
            $this->assert_AccountOrderGroupChrome_testAccountOrdersTemplateUsesSplitVsShipmentCopy();
        },
            'AccountOrderPaymentRecordsSlot::testAccountOrderDetailProvidesEmptyPaymentRecordsSlot' => function () {
            $this->assert_AccountOrderPaymentRecordsSlot_testAccountOrderDetailProvidesEmptyPaymentRecordsSlot();
        },
            'AccountOrderPaymentRecordsSlot::testOrderDeclaresAccountPaymentRecordsHook' => function () {
            $this->assert_AccountOrderPaymentRecordsSlot_testOrderDeclaresAccountPaymentRecordsHook();
        },
            'AccountOrderShipmentsSlot::testAccountOrderDetailProvidesEmptyShipmentsSlot' => function () {
            $this->assert_AccountOrderShipmentsSlot_testAccountOrderDetailProvidesEmptyShipmentsSlot();
        },
            'AccountOrderShipmentsSlot::testOrderDeclaresAccountShipmentsHook' => function () {
            $this->assert_AccountOrderShipmentsSlot_testOrderDeclaresAccountShipmentsHook();
        },
            'BackendOrderAddressDisclosure::testEditTemplateUsesCollapsedDisclosureCardsForAddresses' => function () {
            $this->assert_BackendOrderAddressDisclosure_testEditTemplateUsesCollapsedDisclosureCardsForAddresses();
        },
            'BackendOrderAdminAmountAdjust::testEditTemplateDeclaresAdminAmountAdjustFormHooks' => function () {
            $this->assert_BackendOrderAdminAmountAdjust_testEditTemplateDeclaresAdminAmountAdjustFormHooks();
        },
            'BackendOrderAdminAmountAdjust::testControllerDeclaresAdjustAmountAcl' => function () {
            $this->assert_BackendOrderAdminAmountAdjust_testControllerDeclaresAdjustAmountAcl();
        },
            'BackendOrderBreadcrumbRoute::testOrderDetailBodyHasNoDuplicateBreadcrumb' => function () {
            $this->assert_BackendOrderBreadcrumbRoute_testOrderDetailBodyHasNoDuplicateBreadcrumb();
        },
            'BackendOrderBreadcrumbRoute::testOrderEditBodyHasNoDuplicateBreadcrumb' => function () {
            $this->assert_BackendOrderBreadcrumbRoute_testOrderEditBodyHasNoDuplicateBreadcrumb();
        },
            'BackendOrderEditCompact::testEditCustomerAdjustLivesInOpsTabNotScrollStub' => function () {
            $this->assert_BackendOrderEditCompact_testEditCustomerAdjustLivesInOpsTabNotScrollStub();
        },
            'BackendOrderEditCompact::testCreateStillUsesCollapsedWritableDisclosure' => function () {
            $this->assert_BackendOrderEditCompact_testCreateStillUsesCollapsedWritableDisclosure();
        },
            'BackendOrderEditCompact::testCustomerAdjustFormPartialHasCoreFields' => function () {
            $this->assert_BackendOrderEditCompact_testCustomerAdjustFormPartialHasCoreFields();
        },
            'BackendOrderEditOpsPanelSrc::testOpsPanelSrcAppendsOrderIdQueryViaPhpEcho' => function () {
            $this->assert_BackendOrderEditOpsPanelSrc_testOpsPanelSrcAppendsOrderIdQueryViaPhpEcho();
        },
            'BackendOrderGroupContext::testGroupContextPartialExposesNavigationTestIds' => function () {
            $this->assert_BackendOrderGroupContext_testGroupContextPartialExposesNavigationTestIds();
        },
            'BackendOrderGroupContext::testViewAndEditFetchGroupContextPartial' => function () {
            $this->assert_BackendOrderGroupContext_testViewAndEditFetchGroupContextPartial();
        },
            'BackendOrderGroupContext::testListExposesChildAndShipmentBadges' => function () {
            $this->assert_BackendOrderGroupContext_testListExposesChildAndShipmentBadges();
        },
            'BackendOrderLifecycleStage::testStatusFlowAndEditExposeLifecycleTestids' => function () {
            $this->assert_BackendOrderLifecycleStage_testStatusFlowAndEditExposeLifecycleTestids();
        },
            'BackendOrderOpsTabs::testEditUsesAsyncOpsTabsNotPlainLinks' => function () {
            $this->assert_BackendOrderOpsTabs_testEditUsesAsyncOpsTabsNotPlainLinks();
        },
            'BackendOrderOpsTabs::testPanelActionReturnsTemplateFragments' => function () {
            $this->assert_BackendOrderOpsTabs_testPanelActionReturnsTemplateFragments();
        },
            'BackendOrderPaymentAccordion::testEditTemplateUsesPaymentDisclosureAndSelect' => function () {
            $this->assert_BackendOrderPaymentAccordion_testEditTemplateUsesPaymentDisclosureAndSelect();
        },
            'BackendOrderPaymentRecordsSlot::testOrderDetailProvidesEmptyPaymentRecordsSlotWithoutHardcodedPaymentTable' => function () {
            $this->assert_BackendOrderPaymentRecordsSlot_testOrderDetailProvidesEmptyPaymentRecordsSlotWithoutHardcodedPaymentTable();
        },
            'BackendOrderPaymentRecordsSlot::testOrderDeclaresPaymentRecordsHook' => function () {
            $this->assert_BackendOrderPaymentRecordsSlot_testOrderDeclaresPaymentRecordsHook();
        },
            'BackendOrderPaymentRecordsSlot::testOrderDoesNotOwnPaymentAttemptResolver' => function () {
            $this->assert_BackendOrderPaymentRecordsSlot_testOrderDoesNotOwnPaymentAttemptResolver();
        },
            'BackendOrderRefundOutcomeChip::testRefundHistoryUsesOutcomeChipsNotRawSucceededCode' => function () {
            $this->assert_BackendOrderRefundOutcomeChip_testRefundHistoryUsesOutcomeChipsNotRawSucceededCode();
        },
            'BackendOrderRouterPrefix::testMenuPaymentActionUsesCanonicalOrderRouter' => function () {
            $this->assert_BackendOrderRouterPrefix_testMenuPaymentActionUsesCanonicalOrderRouter();
        },
            'BackendOrderRouterPrefix::testBackendSurfaceFilesRejectRetiredWelineOrderPrefix' => function () {
            $this->assert_BackendOrderRouterPrefix_testBackendSurfaceFilesRejectRetiredWelineOrderPrefix();
        },
            'BackendOrderShipmentPanel::testPanelIsShopifyStyleMarkAsFulfilled' => function () {
            $this->assert_BackendOrderShipmentPanel_testPanelIsShopifyStyleMarkAsFulfilled();
        },
            'BackendOrderShipmentsSlot::testOrderDetailProvidesEmptyShipmentsSlotWithoutHardcodedShipmentTable' => function () {
            $this->assert_BackendOrderShipmentsSlot_testOrderDetailProvidesEmptyShipmentsSlotWithoutHardcodedShipmentTable();
        },
            'BackendOrderShipmentsSlot::testOrderShipmentPanelProvidesEmptyShipmentsSlot' => function () {
            $this->assert_BackendOrderShipmentsSlot_testOrderShipmentPanelProvidesEmptyShipmentsSlot();
        },
            'BackendOrderShipmentsSlot::testOrderListProvidesShippingSlot' => function () {
            $this->assert_BackendOrderShipmentsSlot_testOrderListProvidesShippingSlot();
        },
            'BackendOrderShipmentsSlot::testOrderEditShipmentPanelProvidesShipmentsSlotOnly' => function () {
            $this->assert_BackendOrderShipmentsSlot_testOrderEditShipmentPanelProvidesShipmentsSlotOnly();
        },
            'BackendOrderShipmentsSlot::testOrderEditPageDoesNotHardcodeShipmentsTable' => function () {
            $this->assert_BackendOrderShipmentsSlot_testOrderEditPageDoesNotHardcodeShipmentsTable();
        },
            'BackendOrderShipmentsSlot::testOrderDeclaresShipmentsHooksAndDocs' => function () {
            $this->assert_BackendOrderShipmentsSlot_testOrderDeclaresShipmentsHooksAndDocs();
        },
            'BackendOrderStatusFlow::testEditAndViewMountStatusFlowBeforeSummary' => function () {
            $this->assert_BackendOrderStatusFlow_testEditAndViewMountStatusFlowBeforeSummary();
        },
            'BackendOrderStatusFormAction::testControllerExposesPostUpdateStatusNotUpdateStatus' => function () {
            $this->assert_BackendOrderStatusFormAction_testControllerExposesPostUpdateStatusNotUpdateStatus();
        },
            'BackendOrderStatusFormAction::testPostActionsRedirectToBackendOrderEditNotModuleRoot' => function () {
            $this->assert_BackendOrderStatusFormAction_testPostActionsRedirectToBackendOrderEditNotModuleRoot();
        },
            'BackendOrderStatusFormAction::testViewUpdateStatusFormUsesKebabRoute' => function () {
            $this->assert_BackendOrderStatusFormAction_testViewUpdateStatusFormUsesKebabRoute();
        },
            'BackendOrderStatusFormAction::testEditUpdateStatusAndAddCommentFormsUseKebabRoutes' => function () {
            $this->assert_BackendOrderStatusFormAction_testEditUpdateStatusAndAddCommentFormsUseKebabRoutes();
        },
            'BackendOrderSummaryCompact::testEditSummaryUsesCompactMultiColumnGridNotWideTables' => function () {
            $this->assert_BackendOrderSummaryCompact_testEditSummaryUsesCompactMultiColumnGridNotWideTables();
        },
            'BackendOrderViewShippingMethodHumanize::testViewTemplateShowsHumanLabelAndCopyableCode' => function () {
            $this->assert_BackendOrderViewShippingMethodHumanize_testViewTemplateShowsHumanLabelAndCopyableCode();
        },
            'BackendOrderViewShippingMethodHumanize::testViewControllerAssignsPaymentChrome' => function () {
            $this->assert_BackendOrderViewShippingMethodHumanize_testViewControllerAssignsPaymentChrome();
        },
        ];
    }

private function assert_AccountOrderGroupChrome_testAccountOrdersTemplateUsesSplitVsShipmentCopy(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="account-orders-hint"', $source);
    self::assertStringContainsString('多仓会拆成多个子订单；同一子订单也可分批发货', $source);
    self::assertStringContainsString('data-testid="account-order-group-label"', $source);
    self::assertStringContainsString('data-testid="account-order-child-badge"', $source);
    self::assertStringNotContainsString('状态分叉', $source);
    self::assertStringNotContainsString('OrderShipment::', $source);
}

private function assert_AccountOrderPaymentRecordsSlot_testAccountOrderDetailProvidesEmptyPaymentRecordsSlot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="account-order-payment-records"', $source);
    self::assertStringContainsString('Weline_Order::frontend::account::order-detail::payment-records', $source);
    self::assertStringContainsString('account_payment_order_uuid', $source);
    self::assertStringNotContainsString('BackendOrderPaymentRecordsService', $source);
    self::assertStringNotContainsString('weline_payment_attempt', $source);
}

private function assert_AccountOrderPaymentRecordsSlot_testOrderDeclaresAccountPaymentRecordsHook(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/hook.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'Weline_Order::frontend::account::order-detail::payment-records'", $source);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/doc/hook/frontend/account/order-detail/payment-records.md');
}

private function assert_AccountOrderShipmentsSlot_testAccountOrderDetailProvidesEmptyShipmentsSlot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/hooks/Weline_Order/frontend/account/index/orders.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="account-order-shipments"', $source);
    self::assertStringContainsString('Weline_Order::frontend::account::order-detail::shipments', $source);
    self::assertStringContainsString('account_shipment_order_uuid', $source);
    self::assertStringContainsString('data-testid="account-order-split-detail-link"', $source);
    self::assertStringNotContainsString('BackendOrderShipmentsService', $source);
    self::assertStringNotContainsString('listForOrderId', $source);
    self::assertStringNotContainsString('OrderShipment::', $source);
    self::assertStringNotContainsString('getShipments(', $source);
}

private function assert_AccountOrderShipmentsSlot_testOrderDeclaresAccountShipmentsHook(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/hook.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'Weline_Order::frontend::account::order-detail::shipments'", $source);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/doc/hook/frontend/account/order-detail/shipments.md');
}

private function assert_BackendOrderAddressDisclosure_testEditTemplateUsesCollapsedDisclosureCardsForAddresses(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('data-testid="order-edit-shipping-card"', $src);
    self::assertStringContainsString('data-testid="order-edit-billing-card"', $src);
    self::assertStringContainsString('data-w-component="disclosure"', $src);
    self::assertStringContainsString('aria-expanded="false"', $src);
    self::assertStringContainsString('data-w-disclosure-panel hidden', $src);
    self::assertStringContainsString('data-testid="order-edit-shipping-toggle"', $src);
    self::assertStringContainsString('data-testid="order-edit-billing-toggle"', $src);
    self::assertStringNotContainsString('收货地址（当前）', $src);
    self::assertStringNotContainsString('账单地址（当前）', $src);
}

private function assert_BackendOrderAdminAmountAdjust_testEditTemplateDeclaresAdminAmountAdjustFormHooks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="order-edit-admin-amount-adjust"', $src);
    self::assertStringContainsString('data-testid="order-edit-admin-amount-form"', $src);
    self::assertStringContainsString('data-testid="order-edit-admin-amount-label"', $src);
    self::assertStringContainsString('data-testid="order-edit-admin-amount-value"', $src);
    self::assertStringContainsString("order/backend/order/adjust-amount", $src);
    self::assertStringContainsString('admin_amount_adjust_enabled', $src);
}

private function assert_BackendOrderAdminAmountAdjust_testControllerDeclaresAdjustAmountAcl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Controller/Backend/Order.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("Weline_Order::order_admin_amount_adjust", $src);
    self::assertStringContainsString('function adjustAmount', $src);
    self::assertStringContainsString('OrderAdminAmountAdjustmentShell', $src);
}

private function assert_BackendOrderBreadcrumbRoute_testOrderDetailBodyHasNoDuplicateBreadcrumb(): void
{
    $template = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/view.phtml';
    self::assertFileExists($template);
    $html = (string) file_get_contents($template);
    self::assertStringNotContainsString('w-breadcrumb', $html, '订单详情正文不得再画 w-breadcrumb（顶栏壳已有菜单面包屑）');
    self::assertStringNotContainsString("__('系统')", $html, '正文不得再以「系统」为面包屑根');
    self::assertStringContainsString("@backend-url{'order/backend/order/index'}", $html, '返回列表须指向 order/backend/order/index');
    self::assertStringNotContainsString('weline_order/backend/order', $html, '不得再写死已退役的 weline_order 路由前缀');
}

private function assert_BackendOrderBreadcrumbRoute_testOrderEditBodyHasNoDuplicateBreadcrumb(): void
{
    $template = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml';
    self::assertFileExists($template);
    $html = (string) file_get_contents($template);
    self::assertStringNotContainsString('w-breadcrumb', $html, '订单编辑正文不得再画 w-breadcrumb（顶栏壳已有菜单面包屑）');
    self::assertStringNotContainsString("__('系统')", $html, '正文不得再以「系统」为面包屑根');
    self::assertStringContainsString("@backend-url{'order/backend/order/index'}", $html, '返回列表须指向 order/backend/order/index');
    self::assertStringNotContainsString('weline_order/backend/order', $html, '不得再写死已退役的 weline_order 路由前缀');
}

private function assert_BackendOrderEditCompact_testEditCustomerAdjustLivesInOpsTabNotScrollStub(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('data-testid="order-edit-ops-panel-customer"', $src);
    self::assertStringContainsString('customer-adjust-form.phtml', $src);
    self::assertStringContainsString('data-testid="order-edit-tab-customer"', $src);
    self::assertStringNotContainsString('前往客户调整', $src);
    self::assertStringNotContainsString('data-order-ops-scroll-writable', $src);
    self::assertStringNotContainsString('在下方「客户调整」区编辑', $src);
    $tabPos = strpos($src, 'data-testid="order-edit-ops-panel-customer"');
    $formFetchPos = strpos($src, 'customer-adjust-form.phtml');
    self::assertNotFalse($tabPos);
    self::assertNotFalse($formFetchPos);
    self::assertLessThan($formFetchPos, $tabPos);
}

private function assert_BackendOrderEditCompact_testCreateStillUsesCollapsedWritableDisclosure(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertStringContainsString('data-testid="order-edit-writable-toggle"', $src);
    self::assertStringContainsString('data-testid="order-edit-writable-summary"', $src);
    self::assertStringContainsString('if (!$isEdit):', $src);
    self::assertStringContainsString('data-testid="order-edit-more-toggle"', $src);
    self::assertStringContainsString('--w-stat-tile-min:9rem', $src);
}

private function assert_BackendOrderEditCompact_testCustomerAdjustFormPartialHasCoreFields(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/partial/customer-adjust-form.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('data-testid="order-edit-customer-select"', $src);
    self::assertStringContainsString('data-testid="order-edit-notes"', $src);
    self::assertStringContainsString('data-testid="order-edit-save"', $src);
    self::assertStringContainsString("rows=\"2\" data-testid=\"order-edit-notes\"", $src);
}

private function assert_BackendOrderEditOpsPanelSrc_testOpsPanelSrcAppendsOrderIdQueryViaPhpEcho(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("data-order-ops-async=\"1\"", $src);
    self::assertStringContainsString("@backend-url{'order/backend/order/panel'}?id=<?= (int)\$orderId ?>&amp;tab=shipment&amp;isAjax=1", $src);
    self::assertStringContainsString("@backend-url{'order/backend/order/panel'}?id=<?= (int)\$orderId ?>&amp;tab=refund&amp;isAjax=1", $src);
    self::assertStringContainsString("@backend-url{'order/backend/order/panel'}?id=<?= (int)\$orderId ?>&amp;tab=comms&amp;isAjax=1", $src);
    self::assertStringNotContainsString("['id' => \$orderId, 'tab' => 'shipment'", $src);
    self::assertStringNotContainsString("['id' => \$orderData[Order::schema_fields_ID]", $src);
}

private function assert_BackendOrderGroupContext_testGroupContextPartialExposesNavigationTestIds(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/partial/group-context.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="order-group-context"', $source);
    self::assertStringContainsString('data-testid="order-group-sibling-link"', $source);
    self::assertStringContainsString('结账上下文', $source);
    self::assertStringContainsString('运费归属', $source);
}

private function assert_BackendOrderGroupContext_testViewAndEditFetchGroupContextPartial(): void
{
    foreach (['view.phtml', 'edit.phtml'] as $file) {
        $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/' . $file;
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('Backend/Order/partial/group-context.phtml', $source, $file);
    }
}

private function assert_BackendOrderGroupContext_testListExposesChildAndShipmentBadges(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/index.phtml';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="order-list-child-badge"', $source);
    self::assertStringContainsString('data-testid="order-list-shipment-badge"', $source);
    self::assertStringContainsString('group_contexts', $source);
}

private function assert_BackendOrderLifecycleStage_testStatusFlowAndEditExposeLifecycleTestids(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Order';
    $flow = (string) file_get_contents($root . '/view/templates/backend/Order/partial/status-flow.phtml');
    $edit = (string) file_get_contents($root . '/view/templates/backend/Order/edit.phtml');
    $index = (string) file_get_contents($root . '/view/templates/backend/Order/index.phtml');
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

private function assert_BackendOrderOpsTabs_testEditUsesAsyncOpsTabsNotPlainLinks(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('data-testid="order-edit-ops-tabs"', $src);
    self::assertStringContainsString('data-order-ops-async="1"', $src);
    self::assertStringContainsString('data-testid="order-edit-tab-shipment"', $src);
    self::assertStringContainsString('data-testid="order-edit-tab-refund"', $src);
    self::assertStringContainsString('data-testid="order-edit-tab-comms"', $src);
    self::assertStringContainsString('data-testid="order-edit-tab-customer"', $src);
    self::assertStringContainsString('customer-adjust-form.phtml', $src);
    self::assertStringNotContainsString('前往客户调整', $src);
    self::assertStringContainsString('order/backend/order/panel', $src);
    self::assertStringContainsString('data-w-component="tabs"', $src);
    self::assertStringContainsString('[data-testid^="order-edit-panel-"]', $src);
    self::assertStringNotContainsString("order/backend/shipment/index'", $src);
}

private function assert_BackendOrderOpsTabs_testPanelActionReturnsTemplateFragments(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Controller/Backend/Order.php');
    self::assertNotSame('', $src);
    self::assertStringContainsString("->template('Weline_Order::templates/backend/Order/panel/shipment.phtml')", $src);
    self::assertStringContainsString("->template('Weline_Order::templates/backend/Order/panel/refund.phtml')", $src);
    self::assertStringContainsString("->template('Weline_Order::templates/backend/Order/panel/comms.phtml')", $src);
    self::assertStringNotContainsString("->fetch('Weline_Order::templates/backend/Order/panel/", $src);
}

private function assert_BackendOrderPaymentAccordion_testEditTemplateUsesPaymentDisclosureAndSelect(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('data-testid="order-edit-payment-card"', $src);
    self::assertStringContainsString('data-testid="order-edit-payment-summary"', $src);
    self::assertStringContainsString('data-testid="order-edit-payment-method"', $src);
    self::assertStringContainsString('name="payment_method"', $src);
    self::assertStringContainsString('w-select', $src);
    self::assertStringContainsString('data-testid="order-edit-shipping-method-card"', $src);
    self::assertStringContainsString('data-testid="order-edit-shipping-method-label"', $src);
    self::assertStringContainsString('data-testid="order-edit-discount-items"', $src);
    self::assertStringContainsString('无优惠项目', $src);
    self::assertStringNotContainsString('<input type="text" name="payment_method"', $src);
}

private function assert_BackendOrderPaymentRecordsSlot_testOrderDetailProvidesEmptyPaymentRecordsSlotWithoutHardcodedPaymentTable(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/view.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="backend-order-payment-records"', $source);
    self::assertStringContainsString('accept="backend-order-payment-records,payment-records,payment"', $source);
    self::assertStringContainsString('Weline_Order::backend::order::view::payment-records', $source);
    self::assertStringNotContainsString('OrderPayment::schema_fields_PAYMENT_METHOD', $source);
    self::assertStringNotContainsString("getData('payments')", $source);
    self::assertStringNotContainsString('<w:widget', $source);
}

private function assert_BackendOrderPaymentRecordsSlot_testOrderDeclaresPaymentRecordsHook(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/hook.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'Weline_Order::backend::order::view::payment-records'", $source);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/doc/hook/backend/order/view/payment-records.md');
}

private function assert_BackendOrderPaymentRecordsSlot_testOrderDoesNotOwnPaymentAttemptResolver(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/BackendOrderPaymentHistoryResolver.php';
    self::assertFileDoesNotExist($path);
}

private function assert_BackendOrderRefundOutcomeChip_testRefundHistoryUsesOutcomeChipsNotRawSucceededCode(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Order';
    $tpl = (string) file_get_contents($root . '/view/templates/backend/Order/panel/refund.phtml');
    $flow = (string) file_get_contents($root . '/view/templates/backend/Order/partial/status-flow.phtml');
    $chip = (string) file_get_contents($root . '/view/templates/backend/Order/partial/outcome-chip.phtml');
    self::assertStringContainsString('BackendOrderOutcomeChip', $tpl);
    self::assertStringContainsString('OutcomeChipMarkup::render', $tpl);
    self::assertStringNotContainsString("fetch('Weline_Order::templates/backend/Order/partial/outcome-chip.phtml'", $tpl);
    self::assertStringContainsString('order-edit-refund-status', $tpl);
    self::assertStringContainsString('order-edit-refund-customer-view', $tpl);
    self::assertStringNotContainsString("\$escape((\$row['status'] ?? '') . ((\$row['channel_status'] ?? '') !== '' ? ' / ' . \$row['channel_status'] : ''))", $tpl);
    self::assertStringContainsString('BackendOrderOutcomeChip', $flow);
    self::assertStringContainsString('OutcomeChipMarkup::render', $flow);
    self::assertStringNotContainsString("fetch('Weline_Order::templates/backend/Order/partial/outcome-chip.phtml'", $flow);
    self::assertStringContainsString('OutcomeChipMarkup::render', $chip);
    self::assertStringContainsString('check-circle', (string) file_get_contents($root . '/Helper/OutcomeChipMarkup.php'));
}

/** @return list<string> */
private function scannedRelativePaths_BackendOrderRouterPrefix(): array
{
    return ['etc/backend/menu.xml', 'Controller/Backend/Payment.php', 'Controller/Backend/Shipment.php', 'Controller/Backend/Refund.php', 'Controller/Backend/Invoice.php', 'view/templates/backend/Records/index.phtml', 'view/templates/backend/Invoice/index.phtml', 'view/templates/backend/Refund/index.phtml', 'view/templates/backend/Shipment/index.phtml', 'view/templates/backend/Status/edit.phtml', 'view/templates/backend/Status/index.phtml', 'Test/e2e/backend/Weline_Order-r43-menu.spec.js'];
}

private function assert_BackendOrderRouterPrefix_testMenuPaymentActionUsesCanonicalOrderRouter(): void
{
    $menu = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/etc/backend/menu.xml';
    self::assertFileExists($menu);
    $xml = (string) file_get_contents($menu);
    self::assertStringContainsString('action="order/backend/records/payment"', $xml, '订单收款记录菜单必须指向 order/backend/records/payment');
    self::assertStringNotContainsString('weline_order/backend', $xml, '后台菜单不得再写死已退役的 weline_order 路由前缀');
}

private function assert_BackendOrderRouterPrefix_testBackendSurfaceFilesRejectRetiredWelineOrderPrefix(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Order';
    foreach ($this->scannedRelativePaths_BackendOrderRouterPrefix() as $relative) {
        $path = $root . '/' . $relative;
        self::assertFileExists($path, $relative);
        $body = (string) file_get_contents($path);
        self::assertStringNotContainsString('weline_order/backend', $body, $relative . ' 不得包含已退役前缀 weline_order/backend');
    }
}

private function assert_BackendOrderShipmentPanel_testPanelIsShopifyStyleMarkAsFulfilled(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/panel/shipment.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('shipment-checkout-ref', $src);
    self::assertStringContainsString('name="carrier_id"', $src);
    self::assertStringContainsString('name="tracking_number"', $src);
    self::assertStringContainsString('name="notify_customer"', $src);
    self::assertStringContainsString('shipment-package-lines', $src);
    self::assertStringContainsString('package_lines', $src);
    self::assertStringContainsString('包裹内商品', $src);
    self::assertStringContainsString('fulfill_mode', $src);
    self::assertStringContainsString('shipment_action', $src);
    self::assertStringContainsString('set_channel', $src);
    self::assertStringContainsString('交由物流商履约', $src);
    self::assertStringContainsString('id="backend-order-shipments"', $src);
    self::assertStringContainsString('Weline_Order::backend::order::view::shipments', $src);
    self::assertStringNotContainsString('发货记录 / 补充运单号', $src);
    self::assertStringNotContainsString('本单待发履约单元；提交后写入仓维进度账本。', $src);
}

private function assert_BackendOrderShipmentsSlot_testOrderDetailProvidesEmptyShipmentsSlotWithoutHardcodedShipmentTable(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/view.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="backend-order-shipments"', $source);
    self::assertStringContainsString('accept="backend-order-shipments,order-shipments,shipping-shipments,shipping"', $source);
    self::assertStringContainsString('Weline_Order::backend::order::view::shipments', $source);
    self::assertStringNotContainsString('OrderShipment::schema_fields_TRACKING_NUMBER', $source);
    self::assertStringNotContainsString("getData('shipments')", $source);
}

private function assert_BackendOrderShipmentsSlot_testOrderShipmentPanelProvidesEmptyShipmentsSlot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/panel/shipment.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="backend-order-shipments"', $source);
    self::assertStringContainsString('Weline_Order::backend::order::view::shipments', $source);
    self::assertStringNotContainsString('发货记录 / 补充运单号', $source);
}

private function assert_BackendOrderShipmentsSlot_testOrderListProvidesShippingSlot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/index.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="backend-order-list-shipping"', $source);
    self::assertStringContainsString('Weline_Order::backend::order::list::shipping', $source);
}

private function assert_BackendOrderShipmentsSlot_testOrderEditShipmentPanelProvidesShipmentsSlotOnly(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/panel/shipment.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('id="backend-order-shipments"', $source);
    self::assertStringContainsString('Weline_Order::backend::order::view::shipments', $source);
    self::assertStringNotContainsString('data-testid="shipment-row"', $source);
    self::assertStringContainsString('履约进度账本', $source);
}

private function assert_BackendOrderShipmentsSlot_testOrderEditPageDoesNotHardcodeShipmentsTable(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringNotContainsString('data-testid="order-edit-shipments"', $source);
    self::assertStringNotContainsString('OrderShipment::schema_fields_TRACKING_NUMBER', $source);
}

private function assert_BackendOrderShipmentsSlot_testOrderDeclaresShipmentsHooksAndDocs(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/hook.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'Weline_Order::backend::order::view::shipments'", $source);
    self::assertStringContainsString("'Weline_Order::backend::order::list::shipping'", $source);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/doc/hook/backend/order/view/shipments.md');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/doc/hook/backend/order/list/shipping.md');
}

private function assert_BackendOrderStatusFlow_testEditAndViewMountStatusFlowBeforeSummary(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Order';
    $edit = (string) file_get_contents($root . '/view/templates/backend/Order/edit.phtml');
    $view = (string) file_get_contents($root . '/view/templates/backend/Order/view.phtml');
    $partial = (string) file_get_contents($root . '/view/templates/backend/Order/partial/status-flow.phtml');
    self::assertNotSame('', $edit);
    self::assertNotSame('', $view);
    self::assertNotSame('', $partial);
    self::assertStringContainsString('Weline_Order::templates/backend/Order/partial/status-flow.phtml', $edit);
    self::assertStringContainsString('Weline_Order::templates/backend/Order/partial/status-flow.phtml', $view);
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

private function assert_BackendOrderStatusFormAction_testControllerExposesPostUpdateStatusNotUpdateStatus(): void
{
    $ref = new ReflectionClass(BackendOrderController::class);
    self::assertTrue($ref->hasMethod('postUpdateStatus'), 'Backend Order 须暴露 postUpdateStatus，以便路由注册为 update-status::POST');
    self::assertFalse($ref->hasMethod('updateStatus'), '禁止 updateStatus：首段 update 会注册为 HTTP UPDATE，表单 POST 无法命中');
    $method = $ref->getMethod('postUpdateStatus');
    self::assertTrue($method->isPublic());
}

private function assert_BackendOrderStatusFormAction_testPostActionsRedirectToBackendOrderEditNotModuleRoot(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Controller/Backend/Order.php');
    self::assertStringContainsString("redirect('*/backend/order/edit?id='", $src, 'postUpdateStatus/addComment/cancel 须回跳 order/backend/order/edit（* 仅展开为模块 frontName）');
    self::assertStringNotContainsString("redirect('*/edit", $src, '禁止 */edit：会变成 order/edit 并落到前端 404');
}

private function assert_BackendOrderStatusFormAction_testViewUpdateStatusFormUsesKebabRoute(): void
{
    $html = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/view.phtml');
    self::assertStringContainsString("@backend-url{'order/backend/order/update-status'}", $html);
    self::assertStringNotContainsString("@backend-url{'order/backend/order/updateStatus'}", $html);
}

private function assert_BackendOrderStatusFormAction_testEditUpdateStatusAndAddCommentFormsUseKebabRoutes(): void
{
    $html = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertStringContainsString("@backend-url{'order/backend/order/update-status'}", $html);
    self::assertStringContainsString("@backend-url{'order/backend/order/add-comment'}", $html);
    self::assertStringNotContainsString("@backend-url{'order/backend/order/updateStatus'}", $html);
    self::assertStringNotContainsString("@backend-url{'order/backend/order/addComment'}", $html);
}

private function assert_BackendOrderSummaryCompact_testEditSummaryUsesCompactMultiColumnGridNotWideTables(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/edit.phtml');
    self::assertNotSame('', $src);
    self::assertStringContainsString('data-testid="order-edit-summary-grid"', $src);
    self::assertStringContainsString('w-stat-tiles', $src);
    self::assertStringContainsString('--w-stat-tile-min:11rem', $src);
    self::assertStringContainsString('w-cluster', $src);
    self::assertStringContainsString('data-testid="order-edit-totals"', $src);
    self::assertStringContainsString('data-testid="order-edit-line-count"', $src);
    // Sparse two half-width tables should be gone from summary.
    self::assertDoesNotMatchRegularExpression('/data-testid="order-edit-summary"[\s\S]{0,800}?--w-span-md:6[\s\S]{0,200}?<table class="w-table"/', $src);
}

private function assert_BackendOrderViewShippingMethodHumanize_testViewTemplateShowsHumanLabelAndCopyableCode(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/view.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('payment_chrome', $src);
    self::assertStringContainsString('shipping_method_label', $src);
    self::assertStringContainsString('order-view-shipping-method-label', $src);
    self::assertStringContainsString('order-view-shipping-method-code', $src);
    self::assertStringContainsString('data-copy-text', $src);
    self::assertStringContainsString('点击复制服务码', $src);
    self::assertStringContainsString('navigator.clipboard', $src);
    // Must not dump raw shipping_method as the only cell content.
    self::assertDoesNotMatchRegularExpression('/配送方式[\s\S]{0,200}<\?= \$viewShippingMethod !== \'\' \? htmlspecialchars\(\$viewShippingMethod\)/', $src);
}

private function assert_BackendOrderViewShippingMethodHumanize_testViewControllerAssignsPaymentChrome(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Controller/Backend/Order.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertMatchesRegularExpression('/function view\(\)[\s\S]+BackendOrderPaymentChromePresenter[\s\S]+assign\(\'payment_chrome\'/', $src);
}
}
