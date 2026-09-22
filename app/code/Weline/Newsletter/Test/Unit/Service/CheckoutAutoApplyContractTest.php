<?php

declare(strict_types=1);

namespace Weline\Newsletter\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Newsletter\Api\NewsletterCheckoutCouponAutoApplyInterface;
use Weline\Newsletter\Observer\CheckoutEmailMatchCouponObserver;
use Weline\Newsletter\Service\CheckoutAutoApplyService;

/**
 * Contract: T1 apply wiring + T2 email-match auto-apply (Event + Interface).
 */
final class CheckoutAutoApplyContractTest extends TestCase
{
    public function testServiceImplementsInterfaceAndFixesApplySignature(): void
    {
        self::assertTrue(\is_a(CheckoutAutoApplyService::class, NewsletterCheckoutCouponAutoApplyInterface::class, true));

        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/CheckoutAutoApplyService.php');
        self::assertStringContainsString('DiscountQuoteServiceInterface', $src);
        self::assertStringContainsString('applyCoupon($code, $quotes, $params)', $src);
        self::assertStringContainsString('applyForRecognizedEmail', $src);
        self::assertStringContainsString('GIFT_ISSUED', $src);
        self::assertStringContainsString('couponsAllowedForCartType', $src);
        self::assertStringContainsString('audience_tob', $src);
    }

    public function testSubscribeServiceWiresT1AfterIssue(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/SubscribeService.php');
        self::assertStringContainsString('applyIssuedCoupon', $src);
        self::assertStringContainsString('CheckoutAutoApplyService', $src);
    }

    public function testObserverExtractsEmailWithoutCheckoutModel(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Observer/CheckoutEmailMatchCouponObserver.php');
        self::assertStringContainsString('applyForRecognizedEmail', $src);
        self::assertStringContainsString('guest_email', $src);
        self::assertStringNotContainsString('Weline\\Checkout\\Model', $src);
        self::assertStringNotContainsString('Weline\\Cart\\Model', $src);
        self::assertStringNotContainsString('Weline\\Marketing\\Model\\Coupon', $src);
    }

    public function testEventXmlRegistersCheckoutObservers(): void
    {
        $xml = (string)\file_get_contents(\dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Checkout::checkout::identity::resolve::after', $xml);
        self::assertStringContainsString('Weline_Checkout::checkout::guest::validate::after', $xml);
        self::assertStringContainsString(CheckoutEmailMatchCouponObserver::class, $xml);
    }

    public function testEventDocsExist(): void
    {
        $root = \dirname(__DIR__, 3) . '/doc/event';
        self::assertFileExists($root . '/checkout-email-match-coupon.md');
        self::assertFileExists($root . '/guest-validate-after-coupon.md');
        $doc = (string)\file_get_contents($root . '/checkout-email-match-coupon.md');
        self::assertStringContainsString('identity::resolve::after', $doc);
        self::assertStringContainsString('NewsletterCheckoutCouponAutoApplyInterface', $doc);
    }

    public function testModuleProvidesInterface(): void
    {
        $module = include \dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertSame('1.0.9', $module['version'] ?? '');
        $provides = $module['provides'] ?? [];
        self::assertSame(
            CheckoutAutoApplyService::class,
            $provides[NewsletterCheckoutCouponAutoApplyInterface::class] ?? null
        );
    }
}
