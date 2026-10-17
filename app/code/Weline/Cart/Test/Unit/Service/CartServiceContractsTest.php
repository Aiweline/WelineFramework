<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CartStoreInterface;
use Weline\Cart\Api\ProductTypeRequiresShippingResolverInterface;
use Weline\Cart\Service\AbandonedCartSignalService;
use Weline\Cart\Service\CartCacheStore;
use Weline\Cart\Service\CartDbStore;
use Weline\Cart\Service\CartService;
use Weline\Cart\Service\CheckoutCartSnapshotService;
use Weline\Cart\Service\ContinueCartUrlBuilder;
use Weline\Cart\Service\FreeShippingProgressService;
use Weline\Cart\Service\LegacyProductTypeRequiresShippingResolver;
use Weline\Cart\Service\RequiresShippingFlag;
use Weline\Framework\Cache\CacheManager;

/**
 * Aggregated contract suite (merged from 7 *ContractTest files).
 * Observe one overall pass/fail via testAllCartServiceContractsPass().
 */
final class CartServiceContractsTest extends TestCase
{
    public function testAllCartServiceContractsPass(): void
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
            'AbandonedCartSignal::testContinueCartUrlShape' => function () {
            $this->assert_AbandonedCartSignal_testContinueCartUrlShape();
        },
            'AbandonedCartSignal::testProviderContractSource' => function () {
            $this->assert_AbandonedCartSignal_testProviderContractSource();
        },
            'AbandonedCartSignal::testLineItemsProjectionViaReflection' => function () {
            $this->assert_AbandonedCartSignal_testLineItemsProjectionViaReflection();
        },
            'AbandonedCartSignal::testPayloadEmailExtractionKeepsHasEmailFalseWhenEmpty' => function () {
            $this->assert_AbandonedCartSignal_testPayloadEmailExtractionKeepsHasEmailFalseWhenEmpty();
        },
            'AbandonedCartSignal::testSpecFileExists' => function () {
            $this->assert_AbandonedCartSignal_testSpecFileExists();
        },
            'CartCacheDurableRouting::testCartPoolPredefinedAsHijackExempt' => function () {
            $this->assert_CartCacheDurableRouting_testCartPoolPredefinedAsHijackExempt();
        },
            'CartCacheDurableRouting::testCartCacheStoreFailsClosedOnPersistError' => function () {
            $this->assert_CartCacheDurableRouting_testCartCacheStoreFailsClosedOnPersistError();
        },
            'CartDbStoreDefaultWiring::testModuleProvidesCartStoreInterfaceToDbStore' => function () {
            $this->assert_CartDbStoreDefaultWiring_testModuleProvidesCartStoreInterfaceToDbStore();
        },
            'CartDbStoreDefaultWiring::testCartServiceConstructorDefaultsToCartDbStore' => function () {
            $this->assert_CartDbStoreDefaultWiring_testCartServiceConstructorDefaultsToCartDbStore();
        },
            'CartDbStoreDefaultWiring::testCartDbStoreDocumentsDbAuthority' => function () {
            $this->assert_CartDbStoreDefaultWiring_testCartDbStoreDocumentsDbAuthority();
        },
            'CartLineStorefrontProductUrl::testLineFromSnapshotCarriesSlugAndStorefrontProductUrl' => function () {
            $this->assert_CartLineStorefrontProductUrl_testLineFromSnapshotCarriesSlugAndStorefrontProductUrl();
        },
            'CartSummaryEnrichEvent::testSummaryDispatchesEnrichEvent' => function () {
            $this->assert_CartSummaryEnrichEvent_testSummaryDispatchesEnrichEvent();
        },
            'FreeShippingProgressService::testSubtotalDoesNotEstablishApplicableFreeShipping' => function () {
            $this->assert_FreeShippingProgressService_testSubtotalDoesNotEstablishApplicableFreeShipping();
        },
            'FreeShippingProgressService::testMixedCartShowsLineDistinctionWithoutWholeCartQualified' => function () {
            $this->assert_FreeShippingProgressService_testMixedCartShowsLineDistinctionWithoutWholeCartQualified();
        },
            'FreeShippingProgressService::testAllProductFreeLinesDoNotAdvertiseWholeCartFreeShipping' => function () {
            $this->assert_FreeShippingProgressService_testAllProductFreeLinesDoNotAdvertiseWholeCartFreeShipping();
        },
            'FreeShippingProgressService::testCartQueryProviderEnrichesFreeShippingProgress' => function () {
            $this->assert_FreeShippingProgressService_testCartQueryProviderEnrichesFreeShippingProgress();
        },
            'ProductTypeRequiresShippingFallback::testLegacyResolverOnlyKnowsBuiltinDigitalTypes' => function () {
            $this->assert_ProductTypeRequiresShippingFallback_testLegacyResolverOnlyKnowsBuiltinDigitalTypes();
        },
            'ProductTypeRequiresShippingFallback::testCartModuleDoesNotProvideTypeRequiresShippingSpi' => function () {
            $this->assert_ProductTypeRequiresShippingFallback_testCartModuleDoesNotProvideTypeRequiresShippingSpi();
        },
            'ProductTypeRequiresShippingFallback::testRequiresShippingFlagAbsentAndCoerce' => function () {
            $this->assert_ProductTypeRequiresShippingFallback_testRequiresShippingFlagAbsentAndCoerce();
        },
            'ProductTypeRequiresShippingFallback::testFreezeUsesTypeResolverWhenSnapshotFlagNull' => function () {
            $this->assert_ProductTypeRequiresShippingFallback_testFreezeUsesTypeResolverWhenSnapshotFlagNull();
        },
            'ProductTypeRequiresShippingFallback::testResolveViaInjectedTypeSpi' => function () {
            $this->assert_ProductTypeRequiresShippingFallback_testResolveViaInjectedTypeSpi();
        },
        ];
    }

private function assert_AbandonedCartSignal_testContinueCartUrlShape(): void
{
    $b = new ContinueCartUrlBuilder('https://shop.test.weline.com:9555');
    $r = $b->build(1, 'en_US');
    self::assertTrue($r['reachable']);
    self::assertSame('https://shop.test.weline.com:9555/en_US/cart', $r['continue_cart_url']);
    $zh = $b->build(1, 'zh_Hans_CN');
    self::assertSame('https://shop.test.weline.com:9555/cart', $zh['continue_cart_url']);
}

private function assert_AbandonedCartSignal_testProviderContractSource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Framework/Query/CartSignalsQueryProvider.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("return 'cart_signals'", $src);
    self::assertStringContainsString('list_stale_carts', $src);
    self::assertStringContainsString('get_stale_cart', $src);
    self::assertStringContainsString('AbandonedCartSignalService', $src);
    self::assertStringContainsString('Weline_Cart::cart_inspection', $src);
    self::assertStringContainsString("'frontend' => false", $src);
    $svc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Service/AbandonedCartSignalService.php');
    self::assertStringContainsString("'line_items'", $svc);
    self::assertStringContainsString('lineItemsFromPayload', $svc);
    self::assertStringContainsString("'has_email'", $svc);
    self::assertStringContainsString("'reachable'", $svc);
    self::assertStringContainsString('continue_cart_url', $svc);
    self::assertStringContainsString('ContinueCartUrlBuilder', $svc);
    self::assertStringContainsString('exclude_empty', $svc);
    self::assertStringContainsString('customer:', $svc);
    self::assertStringContainsString('guest:', $svc);
    self::assertSame(90, AbandonedCartSignalService::MAX_LOOKBACK_DAYS);
}

private function assert_AbandonedCartSignal_testLineItemsProjectionViaReflection(): void
{
    $service = new AbandonedCartSignalService(new ContinueCartUrlBuilder('https://shop.test.weline.com:9555'));
    $method = new \ReflectionMethod(AbandonedCartSignalService::class, 'lineItemsFromPayload');
    $method->setAccessible(true);
    /** @var list<array<string,mixed>> $lines */
    $lines = $method->invoke($service, ['items' => [['name' => 'Demo Tee', 'sku' => 'TEE-1', 'qty' => 2, 'unit_price_minor' => 1990, 'row_total_minor' => 3980, 'options' => [['label' => 'Size', 'value_label' => 'M']], 'image' => 'https://cdn.example/tee.jpg'], ['name' => '', 'sku' => 'SKIP']]], 'CNY');
    self::assertCount(1, $lines);
    self::assertSame('Demo Tee', $lines[0]['name']);
    self::assertSame('TEE-1', $lines[0]['sku']);
    self::assertSame(2.0, $lines[0]['qty']);
    self::assertSame('19.90', $lines[0]['unit_price']);
    self::assertSame('39.80', $lines[0]['row_total']);
    self::assertSame('Size: M', $lines[0]['options_text']);
    self::assertSame('https://cdn.example/tee.jpg', $lines[0]['image_url']);
}

private function assert_AbandonedCartSignal_testPayloadEmailExtractionKeepsHasEmailFalseWhenEmpty(): void
{
    $service = new AbandonedCartSignalService(new ContinueCartUrlBuilder('https://shop.test.weline.com:9555'));
    $method = new \ReflectionMethod(AbandonedCartSignalService::class, 'extractPayloadEmail');
    $method->setAccessible(true);
    self::assertSame('a@example.com', $method->invoke($service, ['guest_email' => 'a@example.com']));
    self::assertSame('b@example.com', $method->invoke($service, ['email' => 'b@example.com']));
    self::assertSame('', $method->invoke($service, ['email' => 'not-an-email']));
    self::assertSame('', $method->invoke($service, []));
}

private function assert_AbandonedCartSignal_testSpecFileExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/doc/开发/spec/cart-abandon-signals.md';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('cart_signals', $src);
    self::assertStringContainsString('list_stale_carts', $src);
    self::assertStringContainsString('WHEN', $src);
    self::assertStringContainsString('UC-1', $src);
}

private function assert_CartCacheDurableRouting_testCartPoolPredefinedAsHijackExempt(): void
{
    $ref = new \ReflectionClass(CacheManager::class);
    $const = $ref->getConstant('PREDEFINED_POOLS');
    self::assertIsArray($const);
    self::assertArrayHasKey('cart', $const);
    self::assertTrue((bool) ($const['cart']['hijack_exempt'] ?? false));
    self::assertTrue((bool) ($const['cart']['durable'] ?? false));
    $src = (string) \file_get_contents($ref->getFileName());
    self::assertStringContainsString('hijack_exempt', $src);
    self::assertStringContainsString("!empty(\$poolConfig['hijack_exempt'])", $src);
}

private function assert_CartCacheDurableRouting_testCartCacheStoreFailsClosedOnPersistError(): void
{
    $storeRef = new \ReflectionClass(CartCacheStore::class);
    $store = (string) \file_get_contents((string) $storeRef->getFileName());
    self::assertStringContainsString('ERROR_PERSIST', $store);
    self::assertStringContainsString('cart_persist_failed', $store);
    self::assertStringContainsString('if (!$cache->setCustom(', $store);
    self::assertSame('cart_persist_failed', CartCacheStore::ERROR_PERSIST);
}

private function assert_CartDbStoreDefaultWiring_testModuleProvidesCartStoreInterfaceToDbStore(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/etc/module.php';
    self::assertIsArray($module);
    $provides = $module['provides'] ?? [];
    self::assertIsArray($provides);
    self::assertSame(CartDbStore::class, $provides[CartStoreInterface::class] ?? null);
}

private function assert_CartDbStoreDefaultWiring_testCartServiceConstructorDefaultsToCartDbStore(): void
{
    $src = (string) \file_get_contents((new \ReflectionClass(CartService::class))->getFileName());
    self::assertStringContainsString('CartDbStore::class', $src);
    self::assertStringNotContainsString('ObjectManager::getInstance(CartCacheStore::class)', $src);
    self::assertTrue(\class_exists(CartDbStore::class));
    self::assertTrue(\is_a(CartDbStore::class, CartStoreInterface::class, true));
}

private function assert_CartDbStoreDefaultWiring_testCartDbStoreDocumentsDbAuthority(): void
{
    $src = (string) \file_get_contents((new \ReflectionClass(CartDbStore::class))->getFileName());
    self::assertStringContainsString('weline_cart', $src);
    self::assertStringContainsString('expires_at', $src);
    self::assertStringContainsString('CartPersistencePolicy::expiresAtForCart', $src);
    self::assertSame('cart_persist_failed', CartDbStore::ERROR_PERSIST);
}

private function assert_CartLineStorefrontProductUrl_testLineFromSnapshotCarriesSlugAndStorefrontProductUrl(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Service/CartService.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('function storefrontProductUrl', $source);
    self::assertStringContainsString("'slug' => \$slug", $source);
    self::assertStringContainsString("'url' => \$this->storefrontProductUrl(\$slug, \$productId)", $source);
    self::assertStringContainsString("'/product/'", $source);
    self::assertStringContainsString('never /cart', $source);
}

private function assert_CartSummaryEnrichEvent_testSummaryDispatchesEnrichEvent(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Service/CartService.php');
    self::assertIsString($src);
    self::assertStringContainsString("Weline_Cart::cart_summary::enrich", $src);
    self::assertStringContainsString('enrichSummary', $src);
    self::assertStringContainsString("'tax_amount_minor'", $src);
    $events = include '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/event.php';
    self::assertIsArray($events);
    self::assertArrayHasKey('Weline_Cart::cart_summary::enrich', $events);
}

private function assert_FreeShippingProgressService_testSubtotalDoesNotEstablishApplicableFreeShipping(): void
{
    $service = new FreeShippingProgressService();
    foreach ([0, 2000, 4900, 100000] as $subtotalMinor) {
        $progress = $service->build(['currency' => 'USD', 'subtotal_minor' => $subtotalMinor]);
        self::assertFalse($progress['enabled']);
        self::assertArrayNotHasKey('threshold_minor', $progress);
    }
}

private function assert_FreeShippingProgressService_testMixedCartShowsLineDistinctionWithoutWholeCartQualified(): void
{
    $service = new FreeShippingProgressService();
    $progress = $service->build(['currency_precision' => 2, 'items' => [['requires_shipping' => true, 'is_free_shipping' => 1, 'free_shipping_min_amount' => 0, 'row_total_minor' => 1000], ['requires_shipping' => true, 'row_total_minor' => 5000]]]);
    self::assertTrue($progress['enabled']);
    self::assertFalse($progress['qualified']);
    self::assertSame('product_line', $progress['source']);
    self::assertSame(1, $progress['free_item_count']);
    self::assertSame(1, $progress['paid_item_count']);
    self::assertLessThan(100, $progress['progress_percent']);
}

private function assert_FreeShippingProgressService_testAllProductFreeLinesDoNotAdvertiseWholeCartFreeShipping(): void
{
    $service = new FreeShippingProgressService();
    $progress = $service->build(['items' => [['requires_shipping' => true, 'is_free_shipping' => 1, 'row_total_minor' => 1000]]]);
    self::assertFalse($progress['enabled']);
}

private function assert_FreeShippingProgressService_testCartQueryProviderEnrichesFreeShippingProgress(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Framework/Query/CartQueryProvider.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('FreeShippingProgressService', $source);
    self::assertStringContainsString('enrichSummaryWithFreeShippingProgress', $source);
    self::assertStringContainsString('free_shipping_progress', $source);
}

private function assert_ProductTypeRequiresShippingFallback_testLegacyResolverOnlyKnowsBuiltinDigitalTypes(): void
{
    $r = new LegacyProductTypeRequiresShippingResolver();
    self::assertFalse($r->requiresShippingForProductType('virtual'));
    self::assertFalse($r->requiresShippingForProductType('digital'));
    self::assertFalse($r->requiresShippingForProductType('downloadable'));
    self::assertNull($r->requiresShippingForProductType('simple'));
    self::assertNull($r->requiresShippingForProductType('travel_route'));
}

/**
 * Cart must NOT module-provide the type SPI when Product Integration does —
 * dual provides blow ServiceProviderRegistry and surface as「请求边界清理失败」.
 */
private function assert_ProductTypeRequiresShippingFallback_testCartModuleDoesNotProvideTypeRequiresShippingSpi(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/etc/module.php';
    $provides = is_array($module['provides'] ?? null) ? $module['provides'] : [];
    self::assertArrayNotHasKey(ProductTypeRequiresShippingResolverInterface::class, $provides);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/etc/module.php');
    self::assertStringNotContainsString('LegacyProductTypeRequiresShippingResolver::class', $src);
}

private function assert_ProductTypeRequiresShippingFallback_testRequiresShippingFlagAbsentAndCoerce(): void
{
    self::assertTrue(RequiresShippingFlag::isAbsent(null));
    self::assertTrue(RequiresShippingFlag::isAbsent(''));
    self::assertFalse(RequiresShippingFlag::isAbsent(0));
    self::assertFalse(RequiresShippingFlag::coercePresent(0));
    self::assertFalse(RequiresShippingFlag::coercePresent('0'));
    self::assertTrue(RequiresShippingFlag::coercePresent(1));
    self::assertTrue(RequiresShippingFlag::coercePresent('1'));
}

private function assert_ProductTypeRequiresShippingFallback_testFreezeUsesTypeResolverWhenSnapshotFlagNull(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Service/CheckoutCartSnapshotService.php');
    self::assertStringContainsString('ProductTypeRequiresShippingResolverInterface', $src);
    self::assertStringContainsString('resolveRequiresShipping', $src);
    self::assertStringNotContainsString("product_type === 'travel_route'", $src);
    self::assertStringNotContainsString("'travel_route'", $src);
    self::assertStringNotContainsString("!in_array(strtolower(\$snapshot->productType), ['virtual', 'digital', 'downloadable'], true)", $src);
}

private function assert_ProductTypeRequiresShippingFallback_testResolveViaInjectedTypeSpi(): void
{
    $typeSpi = new class implements ProductTypeRequiresShippingResolverInterface
    {
        public function requiresShippingForProductType(string $productType): ?bool
        {
            return strtolower($productType) === 'travel_route' ? false : null;
        }
    };
    $method = new \ReflectionMethod(CheckoutCartSnapshotService::class, 'resolveRequiresShipping');
    $method->setAccessible(true);
    $ref = new \ReflectionClass(CheckoutCartSnapshotService::class);
    $obj = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getProperty('typeRequiresShipping');
    $prop->setAccessible(true);
    $prop->setValue($obj, $typeSpi);
    self::assertTrue($method->invoke($obj, true, 'travel_route'));
    self::assertFalse($method->invoke($obj, false, 'simple'));
    self::assertFalse($method->invoke($obj, null, 'travel_route'));
    self::assertTrue($method->invoke($obj, null, 'simple'));
}
}
