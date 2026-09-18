<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 转化去重配置与 track 门闩契约。
 */
final class ConversionEventDedupeContractTest extends TestCase
{
    public function testVisitorTrackingConfigExposesConversionDedupeRuntime(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/VisitorTrackingConfig.php');
        self::assertStringContainsString('KEY_CONVERSION_DEDUPE_ENABLED', $src);
        self::assertStringContainsString('KEY_CONVERSION_DEDUPE_TTL_DAYS', $src);
        self::assertStringContainsString('KEY_CONVERSION_DEDUPE_EVENTS', $src);
        self::assertStringContainsString("'conversionDedupe'", $src);
        self::assertStringContainsString('buildConversionDedupeRuntime', $src);
    }

    public function testTrackingPhtmlDeclaresDedupeFields(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml'
        );
        self::assertStringContainsString('visitor/tracking/conversion_dedupe_enabled', $src);
        self::assertStringContainsString('visitor/tracking/conversion_dedupe_ttl_days', $src);
        self::assertStringContainsString('visitor/tracking/conversion_dedupe_events', $src);
        self::assertStringContainsString('visitor_conversion_dedupe', $src);
    }

    public function testPixelEventServiceSkipsDuplicateReason(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/PixelEventService.php');
        self::assertStringContainsString('conversionDedupe()', $src);
        self::assertStringContainsString('skipReason', $src);
        self::assertStringContainsString('PixelConversionDedupeService', $src);
    }

    public function testPixelJsHasLocalStorageDedupeGate(): void
    {
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel.js');
        $phtml = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml');
        foreach ([$js, $phtml] as $source) {
            self::assertStringContainsString('function __isConversionDedupeDuplicate', $source);
            self::assertStringContainsString('function __markConversionDedupeSeen', $source);
            self::assertStringContainsString('function __emitConversionDedupeSandbox', $source);
            self::assertStringContainsString('function __conversionLedgerFamily', $source);
            self::assertStringContainsString('function __collectConversionAliasKeys', $source);
            self::assertStringContainsString('function __conversionBridgeGate', $source);
            self::assertStringContainsString('weline_pixel_dedupe:', $source);
            self::assertStringContainsString('conversionDedupe', $source);
            self::assertStringContainsString('sandboxDedupeIsDup', $source);
            self::assertStringContainsString('function __resolvePixelVendorArea', $source);
            self::assertStringContainsString('_earlyBuffer', $source);
            self::assertStringContainsString('回放缓冲：成功页 SSR track', $source);
            self::assertStringContainsString("__emitConversionDedupeSandbox(gate.name", $source);
            self::assertStringContainsString("hit_kind: 'dedupe'", $source);
            self::assertStringContainsString('bridge_status', $source);
            self::assertStringContainsString('未发送·去重', $source);
            self::assertStringNotContainsString("__shouldDropConversionDedupe(normalizedEventName, meta || {})", $source);
            self::assertStringContainsString('转化去重不拦截主 track', $source);
        }
    }

    public function testCheckoutSuccessExplicitTrackWithoutSessionStorageDedupe(): void
    {
        $success = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Checkout/view/frontend/checkout/success.phtml'
        );
        self::assertStringContainsString("WelinePixel.track('checkout_success'", $success);
        self::assertStringContainsString('maxAttempts = 80', $success);
        self::assertStringContainsString('items:', $success);
        self::assertStringContainsString('currency:', $success);
        self::assertStringContainsString('value:', $success);
        self::assertStringNotContainsString('sessionStorage', $success);

        $payment = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Payment/view/templates/Frontend/checkout/payment-success.phtml'
        );
        self::assertStringContainsString("WelinePixel.track('payment_success'", $payment);
        self::assertStringNotContainsString('sessionStorage.getItem(dedupeKey)', $payment);

        $return = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Payment/view/templates/Frontend/checkout/return.phtml'
        );
        self::assertStringContainsString("WelinePixel.track('payment_success'", $return);
        self::assertStringContainsString('items:', $return);
    }

    public function testPixelSandboxKeepsGa4EcommerceOnDedupeEmit(): void
    {
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel.js');
        $phtml = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml');
        foreach ([$js, $phtml] as $source) {
            self::assertStringContainsString('putEcommerceBag', $source);
            self::assertStringContainsString('items_count', $source);
            self::assertStringContainsString("'item_' + n + '_name'", $source);
            self::assertStringContainsString("params.items = items.slice(0, 20)", $source);
            self::assertStringContainsString('function __getOrderConfirmItems', $source);
        }
    }
}
