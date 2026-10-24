<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Api\B2BHangPaymentBridgeInterface;
use Weline\B2B\Model\B2BOrderHang;
use Weline\B2B\Model\CustomerGroupRecord;
use Weline\B2B\Model\CustomerGroupRecord\LocalDescription;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BHangOrderService;
use Weline\B2B\Service\B2BHangPaymentBridge;
use Weline\B2B\Service\B2BService;
use Weline\B2B\Service\ProductSellingModeFlags;
use Weline\B2B\Service\SellingModePolicy;
use Weline\Checkout\Model\CheckoutSession;
use Weline\Checkout\Service\CheckoutGroupSubmitService;
use Weline\Checkout\Service\CheckoutPaymentRecoveryStateService;
use Weline\Checkout\Service\InMemoryCheckoutSessionStore;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationCatalog;
use Weline\Marketing\Api\Quote\DiscountQuote;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;
use Weline\Order\Service\CommerceOrderTypeRegistry;
use Weline\Order\Service\OrderCalculatorGate;
use Weline\Shipping\Service\ScopedShippingQuoteService;

/**
 * Aggregated contract suite (merged from 10 *ContractTest files).
 * Observe one overall pass/fail via testAllB2BServiceContractsPass().
 */
final class B2BServiceContractsTest extends TestCase
{
    public function testAllB2BServiceContractsPass(): void
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
            'B2BBaseCurrencyResolverSnapshot::testReadWebsiteDefaultUsesWebsiteDataSnapshot' => function () {
            $this->assert_B2BBaseCurrencyResolverSnapshot_testReadWebsiteDefaultUsesWebsiteDataSnapshot();
        },
            'B2BCatalogDisplayType::testB2bRegistersDisplayTypeAndVisibilityFilterWithoutOfferRouting' => function () {
            $this->assert_B2BCatalogDisplayType_testB2bRegistersDisplayTypeAndVisibilityFilterWithoutOfferRouting();
        },
            'B2BHangPaymentBridge::testEventDocExistsBeforeDispatchContract' => function () {
            $this->assert_B2BHangPaymentBridge_testEventDocExistsBeforeDispatchContract();
        },
            'B2BHangPaymentBridge::testModuleProvidesBridgeInterface' => function () {
            $this->assert_B2BHangPaymentBridge_testModuleProvidesBridgeInterface();
        },
            'B2BHangPaymentBridge::testBridgeReconcileDepositDelegatesToHangService' => function () {
            $this->assert_B2BHangPaymentBridge_testBridgeReconcileDepositDelegatesToHangService();
        },
            'B2BHangPaymentBridge::testHangStatusChangedEventNameConstantOnService' => function () {
            $this->assert_B2BHangPaymentBridge_testHangStatusChangedEventNameConstantOnService();
        },
            'B2BHangPaymentBridge::testCallSitesPreferBridgeInterfaceNotConcreteHang' => function () {
            $this->assert_B2BHangPaymentBridge_testCallSitesPreferBridgeInterfaceNotConcreteHang();
        },
            'B2BHangPaymentOrderSummary::testPaymentContextBuildsOrderSummaryFromOrderFacade' => function () {
            $this->assert_B2BHangPaymentOrderSummary_testPaymentContextBuildsOrderSummaryFromOrderFacade();
        },
            'B2BSellingModeSettings::testSellingModeSettingsUsesSystemConfigEmbedAndMenu' => function () {
            $this->assert_B2BSellingModeSettings_testSellingModeSettingsUsesSystemConfigEmbedAndMenu();
        },
            'B2BVipCreditUi::testApproveDefaultsToFirstGroupOptionAndConfigEmbedsCreditSwitch' => function () {
            $this->assert_B2BVipCreditUi_testApproveDefaultsToFirstGroupOptionAndConfigEmbedsCreditSwitch();
        },
            'CustomerGroupLocalModel::testLocalDescriptionExtendsLocalModelAndMapsParent' => function () {
            $this->assert_CustomerGroupLocalModel_testLocalDescriptionExtendsLocalModelAndMapsParent();
        },
            'CustomerGroupLocalModel::testCatalogDiscoversCustomerGroupLocalFields' => function () {
            $this->assert_CustomerGroupLocalModel_testCatalogDiscoversCustomerGroupLocalFields();
        },
            'CustomerGroupLocalModel::testControlCenterEditUsesLocalTagForNameAndDescription' => function () {
            $this->assert_CustomerGroupLocalModel_testControlCenterEditUsesLocalTagForNameAndDescription();
        },
            'CustomerGroupLocalModel::testUpgradeSeedsCustomerGroupLocals' => function () {
            $this->assert_CustomerGroupLocalModel_testUpgradeSeedsCustomerGroupLocals();
        },
            'DefaultWholesalePolicyWiring::testPluginAndObserverWired' => function () {
            $this->assert_DefaultWholesalePolicyWiring_testPluginAndObserverWired();
        },
            'ProductSellingModeFlags::testFromOfferReadsTopLevelAndAttributes' => function () {
            $this->assert_ProductSellingModeFlags_testFromOfferReadsTopLevelAndAttributes();
        },
            'ProductSellingModeFlags::testExplicitFlagsWin' => function () {
            $this->assert_ProductSellingModeFlags_testExplicitFlagsWin();
        },
            'ProductSellingModeFlags::testStorefrontTemplatesHydrateFlags' => function () {
            $this->assert_ProductSellingModeFlags_testStorefrontTemplatesHydrateFlags();
        },
            'TobDiscountHangMultiline::testOrderCalculatorGateBansTobPriceChangingCalculators' => function () {
            $this->assert_TobDiscountHangMultiline_testOrderCalculatorGateBansTobPriceChangingCalculators();
        },
            'TobDiscountHangMultiline::testTobFreezeSkipsDiscountQuoteAndComputesDeposit' => function () {
            $this->assert_TobDiscountHangMultiline_testTobFreezeSkipsDiscountQuoteAndComputesDeposit();
        },
            'TobDiscountHangMultiline::testMultilineQuoteSetAtomicConsumeAndDriftFailsAll' => function () {
            $this->assert_TobDiscountHangMultiline_testMultilineQuoteSetAtomicConsumeAndDriftFailsAll();
        },
            'TobDiscountHangMultiline::testHangLifecycleDepositApproveBalanceAndReject' => function () {
            $this->assert_TobDiscountHangMultiline_testHangLifecycleDepositApproveBalanceAndReject();
        },
            'TobDiscountHangMultiline::testPaymentRecoveryRecordsDepositAndBalanceEntries' => function () {
            $this->assert_TobDiscountHangMultiline_testPaymentRecoveryRecordsDepositAndBalanceEntries();
        },
        ];
    }

private function assert_B2BBaseCurrencyResolverSnapshot_testReadWebsiteDefaultUsesWebsiteDataSnapshot(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Service/B2BBaseCurrencyResolver.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src);
    self::assertStringNotContainsString('Website::schema_fields_ID', $src);
    self::assertStringNotContainsString('->load(Website::', $src);
}

private function assert_B2BCatalogDisplayType_testB2bRegistersDisplayTypeAndVisibilityFilterWithoutOfferRouting(): void
{
    $provider = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/extends/module/Weline_Websites/ScopeDisplayType/B2B.php';
    $filter = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Service/B2BStorefrontCatalogVisibilityFilter.php';
    $module = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/etc/module.php';
    $configTpl = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/backend/Config/index.phtml';
    $configField = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/extends/module/Weline_SystemConfig/Config/frontend/catalog-b2b-products-only.phtml';
    self::assertFileExists($provider);
    self::assertFileExists($filter);
    self::assertFileExists($configField);
    $providerSrc = (string) file_get_contents($provider);
    $filterSrc = (string) file_get_contents($filter);
    $moduleSrc = (string) file_get_contents($module);
    $configTplSrc = (string) file_get_contents($configTpl);
    $fieldSrc = (string) file_get_contents($configField);
    self::assertStringContainsString("CODE = 'b2b'", $providerSrc);
    self::assertStringContainsString('StorefrontCatalogVisibilityFilterInterface', $moduleSrc);
    self::assertStringContainsString('B2BStorefrontCatalogVisibilityFilter', $moduleSrc);
    self::assertStringContainsString('catalog_b2b_products_only', $configTplSrc);
    self::assertStringContainsString('catalog_b2b_products_only', $fieldSrc);
    self::assertStringContainsString('default="1"', $fieldSrc);
    $policy = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Service/CatalogB2bProductsOnlyPolicy.php';
    self::assertFileExists($policy);
    $policySrc = (string) file_get_contents($policy);
    self::assertStringContainsString('DEFAULT_ENABLED = true', $policySrc);
    self::assertStringContainsString('allowsWholesaleDisplay', $filterSrc);
    self::assertStringContainsString('forWebsite(', $filterSrc);
    self::assertStringNotContainsString('CommerceCartOfferRoutingInterface', $filterSrc);
    self::assertStringNotContainsString('B2BCartOfferRouting', $filterSrc);
}

private function assert_B2BHangPaymentBridge_testEventDocExistsBeforeDispatchContract(): void
{
    $doc = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/doc/event/hang_status_changed.md';
    self::assertFileExists($doc);
    $src = (string) file_get_contents($doc);
    self::assertStringContainsString('Weline_B2B::hang_status_changed', $src);
    self::assertStringContainsString('B2BHangPaymentBridgeInterface', $src);
}

private function assert_B2BHangPaymentBridge_testModuleProvidesBridgeInterface(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/etc/module.php';
    self::assertSame(\Weline\B2B\Service\B2BHangPaymentBridge::class, $module['provides'][B2BHangPaymentBridgeInterface::class] ?? null);
}

private function assert_B2BHangPaymentBridge_testBridgeReconcileDepositDelegatesToHangService(): void
{
    $hang = B2BHangOrderService::forTesting(clock: static fn(): int => 1700000000);
    $hang->createAwaitingDeposit(['order_ref' => 'ord-bridge-1', 'customer_id' => '9', 'website_id' => 1, 'goods_subtotal_taxed_minor' => 10000, 'shipping_amount_minor' => 500, 'is_shipping_owner' => true]);
    $bridge = new B2BHangPaymentBridge($hang);
    $out = $bridge->reconcilePaymentSuccess('ord-bridge-1', 'deposit', 'pi_d1');
    self::assertIsArray($out);
    self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $out['hang_status'] ?? null);
    self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $bridge->getHangByOrderRef('ord-bridge-1')['hang_status'] ?? null);
}

private function assert_B2BHangPaymentBridge_testHangStatusChangedEventNameConstantOnService(): void
{
    self::assertSame('Weline_B2B::hang_status_changed', B2BHangOrderService::EVENT_HANG_STATUS_CHANGED);
}

private function assert_B2BHangPaymentBridge_testCallSitesPreferBridgeInterfaceNotConcreteHang(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline';
    $payment = (string) file_get_contents($root . '/Payment/Service/PaymentBrowserReturnDispatcher.php');
    $checkout = (string) file_get_contents($root . '/Checkout/Service/CheckoutOrderPaymentService.php');
    $payable = (string) file_get_contents($root . '/Order/extends/module/Weline_Payment/PayableResolver/OrderPayableResolver.php');
    foreach ([$payment, $checkout, $payable] as $src) {
        self::assertStringContainsString('B2BHangPaymentBridgeInterface', $src);
        self::assertStringNotContainsString('getInstance(\Weline\B2B\Service\B2BHangOrderService::class)', $src);
    }
}

private function assert_B2BHangPaymentOrderSummary_testPaymentContextBuildsOrderSummaryFromOrderFacade(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/Service/B2BHangPaymentService.php');
    self::assertStringContainsString("'order_summary'", $src);
    self::assertStringContainsString('buildOrderSummary', $src);
    self::assertStringContainsString('emptyOrderSummary', $src);
    self::assertStringContainsString("'can_pay'", $src);
    self::assertStringContainsString("'view_state'", $src);
    self::assertStringContainsString("'completed'", $src);
    self::assertStringContainsString('本单尾款已结清，无需再支付', $src);
    self::assertStringContainsString('挂单已完成', $src);
    self::assertStringContainsString('requireHangOwnedByCustomer', $src);
    self::assertStringContainsString('isHangPurposePayable', $src);
    self::assertStringContainsString('hang_status_label', $src);
    self::assertStringContainsString('goods_subtotal_minor', $src);
    self::assertStringContainsString('payable_minor', $src);
    self::assertStringContainsString('discount_lines', $src);
    self::assertStringContainsString('presentDiscountLines', $src);
    self::assertStringContainsString('admin_adjustment', $src);
    self::assertStringContainsString("array_key_exists('balance_amount_minor', \$tp)", $src);
    self::assertStringContainsString('image_url', $src);
    self::assertStringContainsString('resolveProductImageUrls', $src);
    self::assertStringContainsString('presentStorefrontImageUrl', $src);
    self::assertStringContainsString('StorefrontProductMediaUrlResolver', $src);
    self::assertStringContainsString('MediaRepository', $src);
    self::assertStringContainsString("\$read->items", $src);
    self::assertStringContainsString('displayNumber', $src);
}

private function assert_B2BSellingModeSettings_testSellingModeSettingsUsesSystemConfigEmbedAndMenu(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/B2B';
    $declaration = (string) file_get_contents($moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/selling-mode.phtml');
    $configController = (string) file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
    $configTemplate = (string) file_get_contents($moduleRoot . '/view/templates/backend/Config/index.phtml');
    $menuXml = (string) file_get_contents($moduleRoot . '/etc/backend/menu.xml');
    $policy = (string) file_get_contents($moduleRoot . '/Service/SellingModePolicy.php');
    self::assertStringContainsString('selling_mode_toc_enabled', $declaration);
    self::assertStringContainsString('selling_mode_tob_enabled', $declaration);
    self::assertStringContainsString('b2b_credit_enabled', $declaration);
    self::assertStringContainsString('b2b_credit_min_cash_deposit_percent', $declaration);
    self::assertStringContainsString('b2b_hang_require_merchant_review_before_balance', $declaration);
    self::assertStringContainsString('尾款前需卖家审查', $declaration);
    self::assertStringContainsString('scope="global,website"', $declaration);
    self::assertStringContainsString('b2b_max_discount_bps', $declaration);
    self::assertStringContainsString('b2b_min_margin_bps', $declaration);
    self::assertStringContainsString('b2b_default_tier_policy_json', $declaration);
    self::assertStringContainsString('cache-namespaces="storefront/product"', $declaration);
    self::assertSame(3, substr_count($declaration, 'cache-namespaces="storefront/product"'));
    self::assertStringContainsString('不回刷已有价目表或历史订单', $declaration);
    self::assertStringContainsString('定金最低现金占比', $declaration);
    self::assertStringContainsString('type="switch"', $declaration);
    self::assertStringContainsString('value-type="bool"', $declaration);
    self::assertStringContainsString('scope="global,website,store"', $declaration);
    self::assertStringContainsString('key="b2b_credit_enabled"', $declaration);
    self::assertStringContainsString('key="b2b_credit_min_cash_deposit_percent"', $declaration);
    self::assertStringContainsString('scope="global"', $declaration);
    self::assertStringContainsString('@config.area {frontend}', $declaration);
    self::assertStringContainsString('Weline_B2B::config', $declaration);
    self::assertStringContainsString('CONFIG_TOC_ENABLED = \'selling_mode_toc_enabled\'', $policy);
    self::assertStringContainsString('CONFIG_TOB_ENABLED = \'selling_mode_tob_enabled\'', $policy);
    self::assertStringContainsString('SystemConfigTargetScopeService', $configController);
    self::assertStringContainsString('Weline_B2B::config', $configController);
    self::assertStringContainsString('return $this->fetch()', $configController);
    self::assertStringNotContainsString('weline_systemconfig/backend/config', $configController);
    self::assertStringContainsString('<w:config:embed', $configTemplate);
    self::assertStringContainsString('module="Weline_B2B"', $configTemplate);
    self::assertStringContainsString('area="frontend"', $configTemplate);
    self::assertStringContainsString('<w:scope', $configTemplate);
    self::assertStringContainsString('selling_mode_toc_enabled', $configTemplate);
    self::assertStringContainsString('selling_mode_tob_enabled', $configTemplate);
    self::assertStringContainsString('b2b_credit_enabled', $configTemplate);
    self::assertStringContainsString('b2b_credit_min_cash_deposit_percent', $configTemplate);
    self::assertStringContainsString('b2b_hang_require_merchant_review_before_balance', $configTemplate);
    self::assertStringContainsString('b2b_max_discount_bps', $configTemplate);
    self::assertStringContainsString('b2b_min_margin_bps', $configTemplate);
    self::assertStringContainsString('b2b_default_tier_policy_json', $configTemplate);
    self::assertStringContainsString('data-testid="b2b-default-tier-policy-table"', $configTemplate);
    self::assertStringContainsString('data-testid="b2b-default-tier-policy-heading"', $configTemplate);
    self::assertStringContainsString('折扣（%）', $configTemplate);
    self::assertStringContainsString('data-b2b-json-hidden', $configTemplate);
    self::assertStringContainsString('不会回刷已有价目表或历史订单', $configTemplate);
    self::assertStringContainsString('source="Weline_B2B::config"', $menuXml);
    self::assertStringContainsString('action="*/backend/config"', $menuXml);
    self::assertStringContainsString('title="批发配置"', $menuXml);
}

private function assert_B2BVipCreditUi_testApproveDefaultsToFirstGroupOptionAndConfigEmbedsCreditSwitch(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/B2B';
    $controlCenter = (string) file_get_contents($moduleRoot . '/view/templates/backend/ControlCenter/index.phtml');
    $config = (string) file_get_contents($moduleRoot . '/view/templates/backend/Config/index.phtml');
    $declaration = (string) file_get_contents($moduleRoot . '/extends/module/Weline_SystemConfig/Config/frontend/selling-mode.phtml');
    $switcher = (string) file_get_contents($moduleRoot . '/view/templates/frontend/widgets/selling-mode-switcher.phtml');
    $module = (string) file_get_contents($moduleRoot . '/etc/module.php');
    self::assertStringContainsString('b2b_credit_enabled', $declaration);
    self::assertStringContainsString('b2b_credit_min_cash_deposit_percent', $declaration);
    self::assertStringContainsString('scope="global"', $declaration);
    self::assertStringContainsString('b2b_credit_enabled', $config);
    self::assertStringContainsString('b2b_credit_min_cash_deposit_percent', $config);
    self::assertStringContainsString('defaultGroupValue', $controlCenter);
    self::assertStringContainsString('value="<?= $escape($defaultGroupValue) ?>"', $controlCenter);
    self::assertStringContainsString('credit_limit_minor', $controlCenter);
    self::assertStringContainsString('b2b-membership-tier-info', $switcher);
    self::assertStringContainsString('payment.asset_policy.Weline_B2B', $module);
    self::assertMatchesRegularExpression("/'version'\\s*=>\\s*'2\\.\\d+\\.\\d+'/", $module);
}

private function assert_CustomerGroupLocalModel_testLocalDescriptionExtendsLocalModelAndMapsParent(): void
{
    self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
    self::assertSame(CustomerGroupRecord::schema_fields_ID, LocalDescription::schema_fields_ID);
    self::assertSame(CustomerGroupRecord::schema_fields_NAME, LocalDescription::schema_fields_NAME);
    self::assertSame(CustomerGroupRecord::schema_fields_DESCRIPTION, LocalDescription::schema_fields_DESCRIPTION);
    self::assertSame(CustomerGroupRecord::schema_fields_NAME, LocalDescription::schema_fields_name);
    self::assertSame('weline_b2b_customer_group_local', LocalDescription::schema_table);
}

private function assert_CustomerGroupLocalModel_testCatalogDiscoversCustomerGroupLocalFields(): void
{
    $catalog = new LocalModelTranslationCatalog();
    $byClass = [];
    foreach ($catalog->descriptors() as $descriptor) {
        $byClass[(string) $descriptor['local_model']] = $descriptor;
    }
    self::assertArrayHasKey(LocalDescription::class, $byClass);
    self::assertSame(CustomerGroupRecord::class, $byClass[LocalDescription::class]['parent_model']);
    self::assertContains(CustomerGroupRecord::schema_fields_NAME, $byClass[LocalDescription::class]['fields']);
    self::assertContains(CustomerGroupRecord::schema_fields_DESCRIPTION, $byClass[LocalDescription::class]['fields']);
}

private function assert_CustomerGroupLocalModel_testControlCenterEditUsesLocalTagForNameAndDescription(): void
{
    $path = BP . 'app/code/Weline/B2B/view/templates/backend/ControlCenter/index.phtml';
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('Weline\B2B\Model\CustomerGroupRecord\LocalDescription', $content);
    self::assertStringContainsString('field="name"', $content);
    self::assertStringContainsString('field="description"', $content);
    self::assertStringContainsString('data-testid="b2b-group-edit-name-local"', $content);
    self::assertStringContainsString('data-testid="b2b-group-edit-description-local"', $content);
    self::assertStringContainsString('data-testid="b2b-group-local-vault"', $content);
    self::assertStringContainsString('id="group_row_id"', $content);
    self::assertStringContainsString('data-testid="b2b-group-edit-name"', $content);
    self::assertStringContainsString('data-testid="b2b-group-edit-description"', $content);
    self::assertStringContainsString('id="b2b-group-edit-name"', $content);
    self::assertStringContainsString('id="b2b-group-edit-description"', $content);
}

private function assert_CustomerGroupLocalModel_testUpgradeSeedsCustomerGroupLocals(): void
{
    $path = BP . 'app/code/Weline/B2B/Setup/Upgrade.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('CustomerGroupLocalSeedService', $content);
}

private function assert_DefaultWholesalePolicyWiring_testPluginAndObserverWired(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/B2B';
    $pluginXml = (string) file_get_contents($root . '/etc/plugin.xml');
    $eventXml = (string) file_get_contents($root . '/etc/event.xml');
    $pluginSrc = (string) file_get_contents($root . '/Plugin/SystemConfigCenterSaveGuard.php');
    $observerSrc = (string) file_get_contents($root . '/Observer/DefaultWholesalePolicyConfigResourceChanged.php');
    self::assertStringContainsString('SystemConfigCenterService', $pluginXml);
    self::assertStringContainsString('SystemConfigCenterSaveGuard', $pluginXml);
    self::assertStringContainsString('beforeSaveTemplateConfig', $pluginSrc);
    self::assertStringContainsString('assertAndNormalizeForSave', $pluginSrc);
    self::assertStringContainsString('Weline_Framework::resource_changed', $eventXml);
    self::assertStringContainsString('DefaultWholesalePolicyConfigResourceChanged', $eventXml);
    self::assertStringContainsString('ProductStorefrontCacheInvalidator', $observerSrc);
    self::assertStringContainsString('purgeForUrls', $observerSrc);
    self::assertStringContainsString('b2b_default_wholesale_policy', $observerSrc);
}

private function assert_ProductSellingModeFlags_testFromOfferReadsTopLevelAndAttributes(): void
{
    $flags = ProductSellingModeFlags::fromOffer(['product_id' => 0, SellingModePolicy::PRODUCT_FLAG_TOB => false]);
    self::assertIsArray($flags);
    self::assertArrayHasKey(SellingModePolicy::PRODUCT_FLAG_TOB, $flags);
    self::assertFalse((bool) $flags[SellingModePolicy::PRODUCT_FLAG_TOB]);
    $nested = ProductSellingModeFlags::fromOffer(['product_id' => 0, 'attributes' => [SellingModePolicy::PRODUCT_FLAG_TOB => '0']]);
    self::assertIsArray($nested);
    self::assertArrayHasKey(SellingModePolicy::PRODUCT_FLAG_TOB, $nested);
}

private function assert_ProductSellingModeFlags_testExplicitFlagsWin(): void
{
    $flags = ProductSellingModeFlags::fromOffer([SellingModePolicy::PRODUCT_FLAG_TOB => true], [SellingModePolicy::PRODUCT_FLAG_TOB => false]);
    self::assertSame(false, $flags[SellingModePolicy::PRODUCT_FLAG_TOB] ?? null);
}

private function assert_ProductSellingModeFlags_testStorefrontTemplatesHydrateFlags(): void
{
    $switcher = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/widgets/selling-mode-switcher.phtml';
    $tiers = '/Users/weline/Project/Official/框架/app/code/Weline/B2B' . '/view/templates/frontend/partials/qty-tiers.phtml';
    self::assertFileExists($switcher);
    self::assertFileExists($tiers);
    $switcherSrc = (string) file_get_contents($switcher);
    $tiersSrc = (string) file_get_contents($tiers);
    self::assertStringContainsString('ProductSellingModeFlags::fromOffer', $switcherSrc);
    self::assertStringContainsString('ProductSellingModeFlags::fromOffer', $tiersSrc);
    self::assertStringContainsString('ProductWholesaleEligibility', $switcherSrc);
    self::assertStringContainsString('allowsWholesaleDisplay', $tiersSrc);
}

private function assert_TobDiscountHangMultiline_testOrderCalculatorGateBansTobPriceChangingCalculators(): void
{
    $tob = new class implements \Weline\Order\Api\CommerceOrderTypeInterface
    {
        public function getCode(): string
        {
            return 'tob';
        }
        public function getLabel(): string
        {
            return '批发';
        }
        public function getBadgeTone(): string
        {
            return 'warning';
        }
        public function requiresCustomerLogin(): bool
        {
            return true;
        }
        public function disablesStorefrontDiscounts(): bool
        {
            return true;
        }
    };
    $gate = OrderCalculatorGate::forTesting(CommerceOrderTypeRegistry::forTesting([$tob]));
    self::assertFalse($gate->allowsPriceChangingCalculators('tob'));
    self::assertTrue($gate->allowsPriceChangingCalculators('toc'));
}

private function assert_TobDiscountHangMultiline_testTobFreezeSkipsDiscountQuoteAndComputesDeposit(): void
{
    $discountCalls = 0;
    $discounts = new class($discountCalls) implements DiscountQuoteServiceInterface
    {
        public function __construct(private int &$calls)
        {
        }
        public function activeConfigVersion(): string
        {
            return '1';
        }
        public function quote(DiscountQuoteRequest $request): DiscountQuote
        {
            $this->calls++;
            return new DiscountQuote(discountQuoteToken: 'dqt_x', amountMinor: 100, currency: 'CNY', currencyPrecision: 2, requestHash: 'h', lines: [], appliedRuleIds: [1], couponCode: 'SAVE', actionPayloads: []);
        }
        public function validateToken(DiscountQuoteRequest $request, DiscountQuote $quote): bool
        {
            return true;
        }
        public function redeemCoupon(string $code, array $context, DiscountQuote $quote): void
        {
        }
    };
    $quotes = ScopedShippingQuoteService::forTesting(['std' => ['amount_minor' => 1500, 'label' => 'Standard', 'currencies' => ['CNY']]], '1');
    $svc = CheckoutGroupSubmitService::forTesting($quotes, discountQuotes: $discounts);
    $frozen = $svc->freezeAndQuote(lines: [['name' => 'SKU-A', 'sku' => 'SKU-A', 'qty_minor' => 1, 'unit_price_minor' => 10000, 'requires_shipping' => true, 'line_uuid' => 'line-1']], address: ['country' => 'CN'], scope: ['website_id' => 0, 'store_id' => 0], serviceCode: 'std', currency: 'CNY', cartType: 'tob', couponCode: 'SAVE');
    self::assertSame(0, $discountCalls);
    self::assertNull($frozen['discount']);
    self::assertSame('', $frozen['coupon_code']);
    self::assertTrue($frozen['discounts_banned']);
    self::assertSame('tob', $frozen['cart_type']);
    self::assertSame(3000, $frozen['deposit']['deposit_ratio_bps']);
    // goods 10000 + tax 0 = 10000; 30% = 3000; shipping 1500 not in deposit
    self::assertSame(10000, $frozen['deposit']['goods_subtotal_taxed_minor']);
    self::assertSame(3000, $frozen['deposit']['deposit_amount_minor']);
    self::assertSame(8500, $frozen['deposit']['balance_amount_minor']);
    // 7000 remaining goods + 1500 ship
    self::assertFalse($frozen['deposit']['shipping_in_deposit']);
}

private function assert_TobDiscountHangMultiline_testMultilineQuoteSetAtomicConsumeAndDriftFailsAll(): void
{
    $service = B2BService::forTesting();
    $service->seedGroup('g-dealer', 0, 'dealer');
    $service->assignCustomer('cust-b2b', 'g-dealer');
    $service->seedPriceList('pl-dealer', 'g-dealer', 0, 1, ['SKU-A' => 800, 'SKU-B' => 900]);
    $service->enableShadow();
    $set = $service->issueQuoteSet([['customer_id' => 'cust-b2b', 'website_id' => 0, 'sku' => 'SKU-A', 'retail_amount_minor' => 1000], ['customer_id' => 'cust-b2b', 'website_id' => 0, 'sku' => 'SKU-B', 'retail_amount_minor' => 1200]]);
    self::assertTrue($set['ok']);
    self::assertNotSame('', $set['quote_set_id']);
    self::assertCount(2, $set['tokens']);
    $tokenIds = array_map(static fn(array $t): string => (string) $t['token_id'], $set['tokens']);
    $ok = $service->submitQuoteSet($tokenIds, 'cust-b2b', 0, 'order-multi-1');
    self::assertTrue($ok['ok']);
    self::assertCount(2, $ok['snapshots']);
    self::assertSame(2, $service->checkout()->acceptedOrderCount());
    $set2 = $service->issueQuoteSet([['customer_id' => 'cust-b2b', 'website_id' => 0, 'sku' => 'SKU-A', 'retail_amount_minor' => 1000], ['customer_id' => 'cust-b2b', 'website_id' => 0, 'sku' => 'SKU-B', 'retail_amount_minor' => 1200]]);
    $tokenIds2 = array_map(static fn(array $t): string => (string) $t['token_id'], $set2['tokens']);
    $service->seedPriceList('pl-dealer', 'g-dealer', 0, 2, ['SKU-A' => 780, 'SKU-B' => 900]);
    $before = $service->checkout()->acceptedOrderCount();
    $failed = $service->submitQuoteSet($tokenIds2, 'cust-b2b', 0, 'order-multi-drift');
    self::assertFalse($failed['ok']);
    self::assertSame(\Weline\B2B\Service\B2BCheckoutRecheckService::ERROR_QUOTE_VERSION_CONFLICT, $failed['error']);
    self::assertSame($before, $service->checkout()->acceptedOrderCount());
    // tokens remain open (not partially consumed)
    self::assertSame('open', $service->checkout()->quotes()->get($tokenIds2[0])?->status());
    self::assertSame('open', $service->checkout()->quotes()->get($tokenIds2[1])?->status());
}

private function assert_TobDiscountHangMultiline_testHangLifecycleDepositApproveBalanceAndReject(): void
{
    $hang = B2BHangOrderService::forTesting(clock: static fn(): int => 1700000000);
    $created = $hang->createAwaitingDeposit(['order_ref' => 'ord-1', 'customer_id' => 'cust-1', 'website_id' => 0, 'goods_subtotal_taxed_minor' => 10000, 'shipping_amount_minor' => 1500, 'is_shipping_owner' => true]);
    self::assertSame(B2BOrderHang::STATUS_AWAITING_DEPOSIT, $created['hang_status']);
    self::assertSame(3000, $created['deposit_amount_minor']);
    self::assertSame(8500, $created['balance_amount_minor']);
    $reserved = false;
    $afterDeposit = $hang->onDepositPaid('ord-1', 'pi_deposit_1', static function () use (&$reserved): array {
        $reserved = true;
        return [['reservation_uuid' => 'res-1']];
    });
    self::assertTrue($reserved);
    self::assertSame(B2BOrderHang::STATUS_AWAITING_MERCHANT_APPROVAL, $afterDeposit['hang_status']);
    self::assertSame('pi_deposit_1', $afterDeposit['deposit_intent_code']);
    try {
        $hang->approve('ord-1');
        // should work
    } catch (B2BConflictException $e) {
        self::fail($e->getMessage());
    }
    $approved = $hang->getByOrderRef('ord-1');
    self::assertSame(B2BOrderHang::STATUS_AWAITING_BALANCE, $approved?->hangStatus);
    $completed = $hang->onBalancePaid('ord-1', 'pi_balance_1');
    self::assertSame(B2BOrderHang::STATUS_COMPLETED, $completed['hang_status']);
    $hang2 = B2BHangOrderService::forTesting(clock: static fn(): int => 1700000100);
    $hang2->createAwaitingDeposit(['order_ref' => 'ord-2', 'customer_id' => 'cust-1', 'website_id' => 0, 'goods_subtotal_taxed_minor' => 10000, 'shipping_amount_minor' => 0, 'is_shipping_owner' => false]);
    $hang2->onDepositPaid('ord-2', 'pi_d2', static fn(): array => [['reservation_uuid' => 'res-2']]);
    $rejected = $hang2->reject('ord-2', notes: 'no stock');
    self::assertSame(B2BOrderHang::STATUS_REJECTED, $rejected['hang_status']);
    self::assertNotEmpty($hang2->refunds());
    self::assertSame('awaiting_deposit', $hang2->eventPayloads()[0]['type_payload']['hang_status']);
}

private function assert_TobDiscountHangMultiline_testPaymentRecoveryRecordsDepositAndBalanceEntries(): void
{
    $store = new InMemoryCheckoutSessionStore();
    $store->put('qt_hang', ['state' => CheckoutSession::STATE_SUBMITTED, 'idempotency_key' => 'order-idem-hang', 'submitted_result' => ['order_uuids' => ['ord-h']]]);
    $svc = new CheckoutPaymentRecoveryStateService($store);
    $svc->record('qt_hang', 'order-idem-hang', ['paid' => false, 'outcome' => 'pending', 'status' => 'pending', 'purpose' => 'deposit']);
    $svc->record('qt_hang', 'order-idem-hang', ['paid' => true, 'outcome' => 'paid', 'status' => 'paid', 'purpose' => 'balance']);
    self::assertSame('deposit', $svc->getByPurpose('qt_hang', 'order-idem-hang', 'deposit')['purpose']);
    self::assertSame('balance', $svc->getByPurpose('qt_hang', 'order-idem-hang', 'balance')['purpose']);
    self::assertSame('paid', $svc->get('qt_hang', 'order-idem-hang')['outcome']);
}
}
