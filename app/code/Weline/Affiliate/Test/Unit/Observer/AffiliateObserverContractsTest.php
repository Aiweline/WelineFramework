<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAffiliateObserverContractsPass().
 */
final class AffiliateObserverContractsTest extends TestCase
{
    public function testAllAffiliateObserverContractsPass(): void
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
            'OrderPaidSideEffectIsolation::test_order_paid_observer_isolates_affiliate_exceptions' => function () {
            $this->assert_OrderPaidSideEffectIsolation_test_order_paid_observer_isolates_affiliate_exceptions();
        },
            'EngagementListeners::test_browse_cart_wishlist_review_are_enabled' => function () {
            $this->assert_EngagementListeners_test_browse_cart_wishlist_review_are_enabled();
        },
        ];
    }

private function assert_OrderPaidSideEffectIsolation_test_order_paid_observer_isolates_affiliate_exceptions(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/Observer/OrderPaymentStatusChangedObserver.php');
    self::assertStringContainsString('handlePaymentStatusChanged', $src);
    self::assertStringContainsString('catch (\Throwable', $src);
    self::assertStringContainsString('Affiliate order_paid side-effect failed', $src);
}

private function assert_EngagementListeners_test_browse_cart_wishlist_review_are_enabled(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Affiliate' . '/etc/event.xml');
    self::assertStringContainsString('name="Weline_Product::product_viewed"', $xml);
    self::assertStringContainsString('name="Weline_Cart::cart_item_added"', $xml);
    self::assertStringContainsString('name="Weline_Wishlist::add_to_wishlist_after"', $xml);
    self::assertStringContainsString('name="Weline_Review::review_created"', $xml);
    self::assertStringNotContainsString('Weline_Cart::add_to_cart_after', $xml);
    self::assertMatchesRegularExpression(
        '/name="Weline_Product::product_viewed"[\s\S]*?disabled="false"/',
        $xml
    );
    self::assertMatchesRegularExpression(
        '/name="Weline_Cart::cart_item_added"[\s\S]*?disabled="false"/',
        $xml
    );
    self::assertStringContainsString('disabled="true"', $xml);
    self::assertStringContainsString('Weline_Social::share_after', $xml);
}
}
