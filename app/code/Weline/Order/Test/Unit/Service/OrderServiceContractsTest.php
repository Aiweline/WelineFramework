<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Model\Order;
use Weline\Order\Service\AccountCheckoutGroupLoader;
use Weline\Order\Service\AccountCheckoutGroupPresenter;
use Weline\Order\Service\AccountOrderScopePresenter;
use Weline\Order\Service\OrderMailNotifier;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Aggregated contract suite (merged from 6 *ContractTest files).
 * Observe one overall pass/fail via testAllOrderServiceContractsPass().
 */
final class OrderServiceContractsTest extends TestCase
{
    public function testAllOrderServiceContractsPass(): void
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
            'AccountOrderScope::testLoaderAcceptsStoreChannelFiltersAndExposesScopeDto' => function () {
            $this->assert_AccountOrderScope_testLoaderAcceptsStoreChannelFiltersAndExposesScopeDto();
        },
            'AccountOrderScope::testPresenterExposesScopeLabelFields' => function () {
            $this->assert_AccountOrderScope_testPresenterExposesScopeLabelFields();
        },
            'AccountOrderScope::testScopePresenterFallsBackToSnapshotWhenCatalogMissing' => function () {
            $this->assert_AccountOrderScope_testScopePresenterFallsBackToSnapshotWhenCatalogMissing();
        },
            'AccountOrderScope::testLoaderClassExists' => function () {
            $this->assert_AccountOrderScope_testLoaderClassExists();
        },
            'OrderFacadeImageChrome::testPlannedItemPersistsImageFromCommandLine' => function () {
            $this->assert_OrderFacadeImageChrome_testPlannedItemPersistsImageFromCommandLine();
        },
            'OrderFacadeOrderLevelDutyTax::testBuildPlannedOrdersAcceptsOrderLevelDutySnapshots' => function () {
            $this->assert_OrderFacadeOrderLevelDutyTax_testBuildPlannedOrdersAcceptsOrderLevelDutySnapshots();
        },
            'OrderMailNotifier::testMapStatusToChannel' => function () {
            $this->assert_OrderMailNotifier_testMapStatusToChannel();
        },
            'OrderMailNotifier::testNotifyStatusChangedRespectsFlag' => function () {
            $this->assert_OrderMailNotifier_testNotifyStatusChangedRespectsFlag();
        },
            'OrderMailNotifier::testNotifierLocalizesStatusVars' => function () {
            $this->assert_OrderMailNotifier_testNotifierLocalizesStatusVars();
        },
            'OrderRefundCandidatesUuid::testRefundCandidatesQueryItemsByOrderUuidFirst' => function () {
            $this->assert_OrderRefundCandidatesUuid_testRefundCandidatesQueryItemsByOrderUuidFirst();
        },
            'OrderRefundCandidatesUuid::testOrmStoreReloadsOrderIdWhenSaveDoesNotFillPk' => function () {
            $this->assert_OrderRefundCandidatesUuid_testOrmStoreReloadsOrderIdWhenSaveDoesNotFillPk();
        },
            'OrderRefundCandidatesUuid::testRefundCasesExposePaypalChannelHistory' => function () {
            $this->assert_OrderRefundCandidatesUuid_testRefundCasesExposePaypalChannelHistory();
        },
            'OrderRefundCandidatesUuid::testRefundCandidatesSubtractOccupiedQtyAndFilterByOrderId' => function () {
            $this->assert_OrderRefundCandidatesUuid_testRefundCandidatesSubtractOccupiedQtyAndFilterByOrderId();
        },
            'UnpaidOrderMailLineProjector::testSignalServiceWiresLineItems' => function () {
            $this->assert_UnpaidOrderMailLineProjector_testSignalServiceWiresLineItems();
        },
        ];
    }

private function assert_AccountOrderScope_testLoaderAcceptsStoreChannelFiltersAndExposesScopeDto(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/AccountCheckoutGroupLoader.php');
    self::assertStringContainsString('?int $storeId = null', $src);
    self::assertStringContainsString('?int $channelId = null', $src);
    self::assertStringContainsString('Order::schema_fields_STORE_ID', $src);
    self::assertStringContainsString('buildScopeFilter', $src);
    self::assertStringContainsString("'scope' =>", $src);
    self::assertStringContainsString('rowMatchesChannel', $src);
    self::assertStringContainsString('AccountOrderScopePresenter', $src);
}

private function assert_AccountOrderScope_testPresenterExposesScopeLabelFields(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/AccountCheckoutGroupPresenter.php');
    self::assertStringContainsString("'scope_label' =>", $src);
    self::assertStringContainsString("'scope_store_id' =>", $src);
    self::assertStringContainsString("'scope_display_type' =>", $src);
    self::assertStringContainsString('resolveScope', $src);
    $presenter = new AccountCheckoutGroupPresenter();
    $view = $presenter->present(['group_uuid' => 'g-scope-1', 'display_number' => 'G-scope', 'status' => 'paid', 'grand_total_minor' => 1000, 'currency' => 'CNY', 'scope' => ['store_id' => 997, 'store_code' => 'b2b', 'store_name' => 'B2B Store', 'channel_id' => 0, 'channel_code' => '', 'channel_name' => '', 'display_type' => 'b2b', 'display_type_label' => 'B2B', 'label' => 'B2B Store · B2B'], 'orders' => [['order_uuid' => 'ord-1', 'display_number' => '1', 'status' => 'paid', 'amount_minor' => 1000, 'refund_status' => 'none', 'invoice_status' => 'none', 'fulfillment_status' => 'none']]]);
    self::assertSame('B2B Store · B2B', $view['scope_label']);
    self::assertSame(997, $view['scope_store_id']);
    self::assertSame('b2b', $view['scope_store_code']);
    self::assertSame('b2b', $view['scope_display_type']);
}

private function assert_AccountOrderScope_testScopePresenterFallsBackToSnapshotWhenCatalogMissing(): void
{
    $emptyStores = new class implements StoreCatalogInterface
    {
        public function byWebsite(int $websiteId): array
        {
            return [];
        }
        public function byCode(int $websiteId, string $storeCode): ?StoreSummary
        {
            return null;
        }
        public function byId(int $storeId): ?StoreSummary
        {
            return null;
        }
        public function defaultStore(int $websiteId): ?StoreSummary
        {
            return null;
        }
        public function all(): array
        {
            return [];
        }
    };
    $emptyChannels = new class implements SalesChannelCatalogInterface
    {
        public function byStore(int $storeId): array
        {
            return [];
        }
        public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
        {
            return null;
        }
        public function byId(int $channelId): ?SalesChannelSummary
        {
            return null;
        }
        public function defaultChannel(int $storeId): ?SalesChannelSummary
        {
            return null;
        }
        public function defaultChannelForStore(StoreSummary $store): ?SalesChannelSummary
        {
            return null;
        }
    };
    $presenter = new AccountOrderScopePresenter($emptyStores, $emptyChannels, null, null);
    $scope = $presenter->presentFromOrderRow([Order::schema_fields_WEBSITE_ID => 0, Order::schema_fields_STORE_ID => 997, Order::schema_fields_SCOPE_SNAPSHOT_JSON => json_encode(['website_id' => 0, 'store_id' => 997, 'store_code' => 'b2b-main', 'store_name' => '批发店', 'channel_id' => 3, 'channel_code' => 'web', 'channel_name' => '官网', 'display_type' => 'b2b'], JSON_UNESCAPED_UNICODE)]);
    self::assertSame(997, $scope['store_id']);
    self::assertSame('b2b-main', $scope['store_code']);
    self::assertSame('批发店', $scope['store_name']);
    self::assertSame(3, $scope['channel_id']);
    self::assertStringContainsString('批发店', $scope['label']);
    self::assertStringContainsString('官网', $scope['label']);
    self::assertSame('b2b', $scope['display_type']);
}

private function assert_AccountOrderScope_testLoaderClassExists(): void
{
    self::assertTrue(class_exists(AccountCheckoutGroupLoader::class));
    self::assertTrue(class_exists(AccountOrderScopePresenter::class));
}

private function assert_OrderFacadeImageChrome_testPlannedItemPersistsImageFromCommandLine(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderFacade.php');
    self::assertNotSame('', $src);
    self::assertStringContainsString("\$plannedItem['image']", $src);
    self::assertStringContainsString("\$line['image']", $src);
}

private function assert_OrderFacadeOrderLevelDutyTax_testBuildPlannedOrdersAcceptsOrderLevelDutySnapshots(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderFacade.php');
    self::assertStringContainsString('isOrderLevelTaxSnapshot', $src);
    self::assertStringContainsString("str_starts_with(\$lineId, 'duty:')", $src);
    self::assertStringContainsString('orderLevelTaxMinor', $src);
    self::assertStringContainsString('import_at_border', $src);
    self::assertStringContainsString('duty_only', $src);
    self::assertStringContainsString('dutyTaxTotal', $src);
}

private function assert_OrderMailNotifier_testMapStatusToChannel(): void
{
    $n = new OrderMailNotifier();
    self::assertSame(OrderMailNotifier::CHANNEL_SHIPPED, $n->mapStatusToChannel(Order::STATUS_FULFILLED));
    self::assertSame(OrderMailNotifier::CHANNEL_SHIPPED, $n->mapStatusToChannel('shipped'));
    self::assertSame(OrderMailNotifier::CHANNEL_REFUND, $n->mapStatusToChannel(Order::STATUS_REFUNDED));
    self::assertSame(OrderMailNotifier::CHANNEL_REFUND, $n->mapStatusToChannel('refund'));
    self::assertSame(OrderMailNotifier::CHANNEL_STATUS_CHANGED, $n->mapStatusToChannel('processing'));
}

private function assert_OrderMailNotifier_testNotifyStatusChangedRespectsFlag(): void
{
    $n = new OrderMailNotifier();
    $order = $this->createMock(Order::class);
    $result = $n->notifyStatusChanged($order, 'pending', 'processing', false);
    self::assertTrue(!empty($result['skipped']));
    self::assertSame('notify_customer_false', $result['message']);
}

private function assert_OrderMailNotifier_testNotifierLocalizesStatusVars(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderMailNotifier.php');
    self::assertStringContainsString('localizeStatusVars', $src);
    self::assertStringContainsString('getStatusLabel', $src);
    self::assertStringContainsString('withMailLocaleEnvironment', $src);
    self::assertStringContainsString('formatGrandTotalDisplay', $src);
    self::assertSame('已发货', Order::getStatusLabel(Order::STATUS_FULFILLED));
    self::assertSame('待处理', Order::getStatusLabel(Order::STATUS_PENDING));
    $zh = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/email/order_created/zh_Hans_CN.html');
    self::assertStringNotContainsString('{{var.order_uuid}}', $zh);
    self::assertStringContainsString('{{var.grand_total}}', $zh);
}

private function assert_OrderRefundCandidatesUuid_testRefundCandidatesQueryItemsByOrderUuidFirst(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderTradeAdminCommandService.php');
    self::assertStringContainsString('schema_fields_ORDER_UUID, $orderUuid', $src);
    self::assertStringContainsString('schema_fields_ORDER_ID, $rowOrderId', $src);
}

private function assert_OrderRefundCandidatesUuid_testOrmStoreReloadsOrderIdWhenSaveDoesNotFillPk(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrmOrderFacadeStore.php');
    self::assertStringContainsString('order_persist_missing_id', $src);
    self::assertStringContainsString("schema_fields_ORDER_UUID, (string)\$row['order_uuid']", $src);
    self::assertStringContainsString("'payment_method'", $src);
    self::assertStringContainsString("'payment_status'", $src);
}

private function assert_OrderRefundCandidatesUuid_testRefundCasesExposePaypalChannelHistory(): void
{
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderTradeAdminCommandService.php');
    self::assertStringContainsString('provider_refund_id', $svc);
    self::assertStringContainsString('schema_fields_METHOD_CODE', $svc);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/view/templates/backend/Order/panel/refund.phtml');
    self::assertStringContainsString('order-edit-refund-history', $tpl);
    self::assertStringContainsString('provider_refund_id', $tpl);
    self::assertStringContainsString('payment_method', $tpl);
}

private function assert_OrderRefundCandidatesUuid_testRefundCandidatesSubtractOccupiedQtyAndFilterByOrderId(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderTradeAdminCommandService.php');
    self::assertStringContainsString('occupiedRefundQtyByItem', $src);
    self::assertStringContainsString('function refundCandidates(int $limit = 50, int $orderId = 0)', $src);
    self::assertStringContainsString('function refundCases(int $limit = 50, int $orderId = 0)', $src);
    $coord = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/OrderRefundCoordinator.php');
    self::assertStringContainsString('syncPersistedRefundProgress', $coord);
    self::assertStringContainsString('appendRefundHistory', $coord);
    self::assertStringContainsString('渠道退款号', $coord);
}

private function assert_UnpaidOrderMailLineProjector_testSignalServiceWiresLineItems(): void
{
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/UnpaidOrderSignalService.php');
    self::assertStringContainsString('UnpaidOrderMailLineProjector', $svc);
    self::assertStringContainsString("'line_items'", $svc);
    self::assertStringContainsString('resolveCreatedAtDisplay', $svc);
    self::assertStringContainsString('schema_fields_CREATE_TIME', $svc);
    $proj = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Order' . '/Service/UnpaidOrderMailLineProjector.php');
    self::assertStringContainsString('BackendOrderLinePresenter', $proj);
    self::assertStringContainsString('image_url', $proj);
    self::assertStringContainsString('options_text', $proj);
}
}
