<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCategoryPathLocalizerInterface;
use Weline\Dropship\Service\DropshipPreferredWarehouseBinder;
use Weline\Dropship\Service\DropshipPublishService;
use Weline\Dropship\Service\DropshipWebhookDevRelayBridge;

/**
 * Aggregated contract suite (merged from 10 *ContractTest files).
 * Observe one overall pass/fail via testAllDropshipServiceContractsPass().
 */
final class DropshipServiceContractsTest extends TestCase
{
    public function testAllDropshipServiceContractsPass(): void
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
            'DropshipCategoryPathLocalizer::testCjProviderImplementsCategoryPathLocalizer' => function () {
            $this->assert_DropshipCategoryPathLocalizer_testCjProviderImplementsCategoryPathLocalizer();
        },
            'DropshipCategoryPathLocalizer::testPublishServiceResolveCategoryIdsLocalizesBeforeEnsure' => function () {
            $this->assert_DropshipCategoryPathLocalizer_testPublishServiceResolveCategoryIdsLocalizesBeforeEnsure();
        },
            'DropshipOriginRead::testMapServiceReadsWarehouseShippingOrigin' => function () {
            $this->assert_DropshipOriginRead_testMapServiceReadsWarehouseShippingOrigin();
        },
            'DropshipOutboxTerminal::testOutboxDefinesDeadAndSealsUpsert' => function () {
            $this->assert_DropshipOutboxTerminal_testOutboxDefinesDeadAndSealsUpsert();
        },
            'DropshipOutboxTerminal::testCronExcludesDeadAndConsumerUsesOk' => function () {
            $this->assert_DropshipOutboxTerminal_testCronExcludesDeadAndConsumerUsesOk();
        },
            'DropshipOutboxWarehouseMapFallback::testOutboxDegradesMissingWarehouseMap' => function () {
            $this->assert_DropshipOutboxWarehouseMapFallback_testOutboxDegradesMissingWarehouseMap();
        },
            'DropshipPreferredWarehouseBinder::testBinderStampsPreferredWarehouseIdFromListingField' => function () {
            $this->assert_DropshipPreferredWarehouseBinder_testBinderStampsPreferredWarehouseIdFromListingField();
        },
            'DropshipPublishShippingDims::testCjExtractShippingDimsConvertsGramsAndMm' => function () {
            $this->assert_DropshipPublishShippingDims_testCjExtractShippingDimsConvertsGramsAndMm();
        },
            'DropshipPublishShippingDims::testSnapshotShippingRoundTripAndPublishPayload' => function () {
            $this->assert_DropshipPublishShippingDims_testSnapshotShippingRoundTripAndPublishPayload();
        },
            'DropshipPublishShippingDims::testMapProductDetailIncludesShipping' => function () {
            $this->assert_DropshipPublishShippingDims_testMapProductDetailIncludesShipping();
        },
            'DropshipPushTerminalCompensation::testCompensationUsesCoordinatorAndNeverOrderRefunded' => function () {
            $this->assert_DropshipPushTerminalCompensation_testCompensationUsesCoordinatorAndNeverOrderRefunded();
        },
            'DropshipPushTerminalCompensation::testSettingsSwitchDefaultOn' => function () {
            $this->assert_DropshipPushTerminalCompensation_testSettingsSwitchDefaultOn();
        },
            'DropshipWarehouseMapDisplay::testEnrichForDisplayContractInServiceSource' => function () {
            $this->assert_DropshipWarehouseMapDisplay_testEnrichForDisplayContractInServiceSource();
        },
            'DropshipWarehouseMapDisplay::testControllerAllowsDefaultWebsiteIdZero' => function () {
            $this->assert_DropshipWarehouseMapDisplay_testControllerAllowsDefaultWebsiteIdZero();
        },
            'DropshipWebhookDevRelayBridge::testInboxCodePrefixHelpers' => function () {
            $this->assert_DropshipWebhookDevRelayBridge_testInboxCodePrefixHelpers();
        },
            'DropshipWebhookDevRelayBridge::testCallbackStoresRawBodyAndBridgeCallSite' => function () {
            $this->assert_DropshipWebhookDevRelayBridge_testCallbackStoresRawBodyAndBridgeCallSite();
        },
            'DropshipWebsiteCurrencySnapshot::testPublishAndFollowResolveCurrencyViaWebsiteData' => function () {
            $this->assert_DropshipWebsiteCurrencySnapshot_testPublishAndFollowResolveCurrencyViaWebsiteData();
        },
        ];
    }

private function assert_DropshipCategoryPathLocalizer_testCjProviderImplementsCategoryPathLocalizer(): void
{
    $provider = new CjProvider();
    self::assertInstanceOf(DropshipCategoryPathLocalizerInterface::class, $provider);
    self::assertSame('方向盘套', $provider->localizeCategoryPath('Steering Covers', 'zh_Hans_CN'));
    self::assertSame('家居、园艺与家具 / 家居收纳', $provider->localizeCategoryPath('Home, Garden & Furniture / Home Storage', 'zh_Hans_CN'));
    self::assertSame('Steering Covers', $provider->localizeCategoryPath('Steering Covers', 'en_US'));
}

private function assert_DropshipCategoryPathLocalizer_testPublishServiceResolveCategoryIdsLocalizesBeforeEnsure(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipPublishService.php');
    self::assertStringContainsString('DropshipCategoryPathLocalizerInterface', $src);
    self::assertStringContainsString('localizeCategoryPath', $src);
    self::assertStringContainsString('ensureFromRemotePath($websiteId, $snapshot->providerCode, $path, $locale)', $src);
}

private function assert_DropshipOriginRead_testMapServiceReadsWarehouseShippingOrigin(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipWarehouseMapService.php');
    self::assertStringContainsString('resolveShippingAddressId', $src);
    self::assertStringContainsString('WarehouseShippingOriginInterface', $src);
    self::assertStringContainsString('findShippingAddressId', $src);
}

private function assert_DropshipOutboxTerminal_testOutboxDefinesDeadAndSealsUpsert(): void
{
    $model = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Model/DropshipPushOutbox.php');
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipOutboxService.php');
    self::assertStringContainsString("STATUS_DEAD = 'dead'", $model);
    self::assertStringContainsString('STATUS_DEAD', $svc);
    self::assertStringContainsString('already_dead', $svc);
    self::assertStringContainsString('MAX_ATTEMPTS', $svc);
    self::assertStringContainsString('Weline_Dropship::push_terminal', $svc);
    self::assertStringContainsString("'terminal' => true", $svc);
    self::assertStringContainsString('isPermanentFailure', $svc);
    self::assertStringContainsString('Logistic not found', $svc);
    self::assertStringContainsString('Platform not support', $svc);
    self::assertStringNotContainsString("'Order exist'", $svc);
    self::assertStringNotContainsString("'do not duplicate create'", $svc);
    self::assertStringContainsString('maybeRecoverExistingFulfillment', $svc);
    self::assertStringContainsString('recovered_existing', $svc);
    self::assertStringContainsString('maybeBackfillTerminalCompensation', $svc);
    self::assertStringContainsString('DropshipPushTerminalCompensationService', $svc);
    self::assertStringContainsString('markTerminalDead', $svc);
    // done/dead 密封，禁止重开
    self::assertMatchesRegularExpression('/STATUS_DONE.*STATUS_DEAD|STATUS_DEAD.*STATUS_DONE/s', $svc);
    self::assertStringContainsString('create', $svc);
    // create + unsupported → dead（不是长期停在 skipped）
    self::assertStringContainsString("\$action === 'create'", $svc);
}

private function assert_DropshipOutboxTerminal_testCronExcludesDeadAndConsumerUsesOk(): void
{
    $cron = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Cron/DropshipOutboxRetry.php');
    $consumer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Queue/DropshipOrderPushConsumer.php');
    self::assertStringContainsString('STATUS_PENDING', $cron);
    self::assertStringContainsString('STATUS_ERROR', $cron);
    self::assertStringNotContainsString('STATUS_DEAD', $cron);
    self::assertStringContainsString("(\$result['ok'] ?? false) ? 'QUEUE_DONE'", $consumer);
}

private function assert_DropshipOutboxWarehouseMapFallback_testOutboxDegradesMissingWarehouseMap(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipOutboxService.php');
    self::assertStringContainsString('resolveWarehouseMapOrEmpty', $src);
    self::assertStringContainsString('ERROR_MISSING', $src);
    self::assertStringContainsString('tracking_number', $src);
    self::assertStringContainsString("\$result['status']", $src);
}

private function assert_DropshipPreferredWarehouseBinder_testBinderStampsPreferredWarehouseIdFromListingField(): void
{
    self::assertTrue(class_exists(DropshipPreferredWarehouseBinder::class));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipPreferredWarehouseBinder.php');
    self::assertStringContainsString('preferred_warehouse_id', $src);
    self::assertStringContainsString('LOCAL_WAREHOUSE_ID', $src);
    self::assertStringContainsString('LOCAL_OFFER_ID', $src);
    self::assertStringContainsString('function bind', $src);
}

private function assert_DropshipPublishShippingDims_testCjExtractShippingDimsConvertsGramsAndMm(): void
{
    $dims = CjProvider::extractShippingDims(['packingWeight' => '350.00-380.00', 'variants' => [['inventoryNum' => 2, 'variantWeight' => 350, 'variantLength' => 300, 'variantWidth' => 200, 'variantHeight' => 30]]]);
    self::assertEqualsWithDelta(0.35, $dims['weight_kg'], 0.0001);
    self::assertEqualsWithDelta(30.0, $dims['length_cm'], 0.0001);
    self::assertEqualsWithDelta(20.0, $dims['width_cm'], 0.0001);
    self::assertEqualsWithDelta(3.0, $dims['height_cm'], 0.0001);
}

private function assert_DropshipPublishShippingDims_testSnapshotShippingRoundTripAndPublishPayload(): void
{
    $snap = DropshipCatalogSnapshot::fromArray(['provider_code' => 'cj', 'external_spu' => 'P-SHIP', 'title' => 'Ship me', 'origin_currency' => 'USD', 'origin_price_minor' => 100, 'qty' => 1, 'shelf_status' => 'active', 'shipping' => ['weight_kg' => 0.35, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 3]]);
    self::assertTrue($snap->hasShippingDims());
    self::assertSame(0.35, $snap->toArray()['shipping']['weight_kg']);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipPublishService.php');
    self::assertStringContainsString('applyShippingDims', $src);
    self::assertStringContainsString("\$payload['weight']", $src);
    self::assertTrue(method_exists(DropshipPublishService::class, 'publish'));
}

private function assert_DropshipPublishShippingDims_testMapProductDetailIncludesShipping(): void
{
    $snap = CjProvider::mapProductDetail(['pid' => 'PID-1', 'productName' => '测试裙', 'sellPrice' => 6.77, 'variants' => [['inventoryNum' => 1, 'variantWeight' => 350, 'variantLength' => 300, 'variantWidth' => 200, 'variantHeight' => 30, 'variantSku' => 'SKU-1', 'vid' => 'V1']]], 'CN', 'zh_Hans_CN');
    self::assertNotNull($snap);
    self::assertTrue($snap->hasShippingDims());
    self::assertEqualsWithDelta(0.35, $snap->shipping['weight_kg'], 0.0001);
}

private function assert_DropshipPushTerminalCompensation_testCompensationUsesCoordinatorAndNeverOrderRefunded(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $svc = (string) file_get_contents($root . '/Service/DropshipPushTerminalCompensationService.php');
    $obs = (string) file_get_contents($root . '/Observer/DropshipPushTerminalRefundObserver.php');
    $eventXml = (string) file_get_contents($root . '/etc/event.xml');
    $mail = (string) file_get_contents($root . '/extends/MailChannelProvider.php');
    $extends = (string) file_get_contents($root . '/extends.php');
    self::assertStringContainsString('OrderRefundCoordinator', $svc);
    self::assertStringContainsString('requestRefund', $svc);
    self::assertStringContainsString('dropship:auto-refund:', $svc);
    self::assertStringContainsString('item_uuid', $svc);
    self::assertStringContainsString('qty_minor', $svc);
    self::assertStringContainsString('local_offer_id', $svc);
    self::assertStringContainsString('shippingRefundMinor', $svc);
    self::assertStringContainsString('compensation', $svc);
    self::assertStringContainsString('switch_off', $svc);
    self::assertStringContainsString('refunded', $svc);
    self::assertStringContainsString('refund_failed', $svc);
    self::assertStringContainsString('mail_sent_at', $svc);
    self::assertStringContainsString('fulfillment_consolation', $svc);
    self::assertStringContainsString('已启动退款', $svc);
    self::assertStringNotContainsString('order_refunded', $svc);
    self::assertStringNotContainsString('RefundService', $svc);
    self::assertStringContainsString('DropshipPushTerminalCompensationService', $obs);
    self::assertStringContainsString("\$action !== 'create'", $svc);
    self::assertStringContainsString('Weline_Dropship::push_terminal', $eventXml);
    self::assertStringContainsString('DropshipPushTerminalRefundObserver', $eventXml);
    self::assertStringContainsString('Weline_Dropship::fulfillment_consolation', $mail);
    self::assertStringContainsString("'default_templates'", $mail);
    self::assertStringContainsString("'variables'", $mail);
    self::assertStringContainsString('MailChannelProviderInterface', $extends);
    self::assertFileExists($root . '/view/email/fulfillment_consolation/zh_Hans_CN.html');
    self::assertStringContainsString("'vars'", $svc);
    self::assertStringContainsString('order_uuid', $svc);
    self::assertStringNotContainsString("'subject' =>", $svc);
    self::assertStringNotContainsString("'content' => \$content", $svc);
}

private function assert_DropshipPushTerminalCompensation_testSettingsSwitchDefaultOn(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship';
    $settings = (string) file_get_contents($root . '/Service/DropshipSettings.php');
    $decl = (string) file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/frontend/dropship-settings.phtml');
    self::assertStringContainsString('KEY_AUTO_REFUND_ON_PUSH_FAIL', $settings);
    self::assertStringContainsString('dropship/ops/auto_refund_on_push_fail', $settings);
    self::assertStringContainsString('isAutoRefundOnPushFail', $settings);
    self::assertMatchesRegularExpression('/key="dropship\/ops\/auto_refund_on_push_fail"[^>]*default="1"/', $decl);
}

private function assert_DropshipWarehouseMapDisplay_testEnrichForDisplayContractInServiceSource(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipWarehouseMapService.php');
    self::assertStringContainsString('function enrichForDisplay', $src);
    self::assertStringContainsString('provider_label', $src);
    self::assertStringContainsString('website_label', $src);
    self::assertStringContainsString('store_label', $src);
    self::assertStringContainsString('remote_label', $src);
    self::assertStringContainsString('local_label', $src);
    self::assertStringContainsString('channel_label', $src);
    self::assertStringContainsString("'—'", $src);
    self::assertStringContainsString('WebsiteCatalogInterface', $src);
    self::assertStringContainsString('StoreCatalogInterface', $src);
    self::assertStringContainsString('DropshipRemoteWarehouseService', $src);
    self::assertStringContainsString('listAll', $src);
    self::assertStringContainsString('enrichForDisplay($rows)', $src);
    self::assertStringContainsString('usort', $src);
    self::assertStringContainsString('schema_fields_WEBSITE_ID', $src);
    self::assertStringContainsString('storeId < 0', $src);
    self::assertStringNotContainsString('storeId <= 0', $src);
    self::assertStringContainsString('function syncCountryPairs', $src);
    self::assertStringContainsString('function coverageBoard', $src);
    self::assertStringContainsString('function providerSummaries', $src);
    self::assertStringContainsString('string $countryCode = \'\'', $src);
    self::assertStringContainsString('REMOTE_COUNTRY_CODE', $src);
}

private function assert_DropshipWarehouseMapDisplay_testControllerAllowsDefaultWebsiteIdZero(): void
{
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Backend/Warehouse.php');
    self::assertStringContainsString('websiteId === null', $ctrl);
    self::assertStringNotContainsString('websiteId === 0', $ctrl);
    self::assertStringNotContainsString('websiteId <= 0', $ctrl);
}

private function assert_DropshipWebhookDevRelayBridge_testInboxCodePrefixHelpers(): void
{
    self::assertTrue(DropshipWebhookDevRelayBridge::isDropshipInboxCode('dropship:12'));
    self::assertFalse(DropshipWebhookDevRelayBridge::isDropshipInboxCode('pay_abc'));
    self::assertSame(12, DropshipWebhookDevRelayBridge::parseInboxId('dropship:12'));
    self::assertSame(0, DropshipWebhookDevRelayBridge::parseInboxId('pay_abc'));
    self::assertSame(DropshipWebhookDevRelayBridge::INBOX_PREFIX, 'dropship:');
}

private function assert_DropshipWebhookDevRelayBridge_testCallbackStoresRawBodyAndBridgeCallSite(): void
{
    $ctrl = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Controller/Frontend/Callback.php');
    self::assertIsString($ctrl);
    self::assertStringContainsString('getBodyParams', $ctrl);
    self::assertStringContainsString('getRawBody', $ctrl);
    self::assertStringContainsString('DropshipWebhookDevRelayBridge', $ctrl);
    self::assertStringContainsString('publishInbox', $ctrl);
}

private function assert_DropshipWebsiteCurrencySnapshot_testPublishAndFollowResolveCurrencyViaWebsiteData(): void
{
    $publish = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipPublishService.php';
    $follow = '/Users/weline/Project/Official/框架/app/code/Weline/Dropship' . '/Service/DropshipFollowService.php';
    self::assertFileExists($publish);
    self::assertFileExists($follow);
    foreach ([$publish, $follow] as $path) {
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src, $path);
        self::assertStringContainsString('WebsiteData::currencyCodesForWebsite', $src, $path);
        self::assertStringNotContainsString('where(Website::schema_fields_ID', $src, $path . ' must not Website::find for default currency.');
    }
}
}
