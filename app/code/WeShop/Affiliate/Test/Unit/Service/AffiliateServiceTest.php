<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Model\AffiliateShare;
use WeShop\Affiliate\Service\AffiliateService;

class AffiliateServiceTest extends TestCase
{
    public function testGetAffiliateSummaryCalculatesPendingCommission(): void
    {
        $service = new class() extends AffiliateService {
            protected function getAffiliateAccountOrCreate(int $customerId): array
            {
                return [
                    'affiliate_id' => 12,
                    'customer_id' => $customerId,
                    'referral_code' => 'REF00000012ABCD',
                    'commission_rate' => 0.15,
                    'total_commission' => 200.0,
                    'paid_commission' => 80.5,
                    'status' => 'active',
                ];
            }

            public function getReferralLink(string $referralCode): string
            {
                return 'https://shop.example.test/customer/account/register?ref=' . rawurlencode($referralCode);
            }

            protected function getDefaultShareLinkData(int $customerId): array
            {
                return [];
            }

            protected function getAffiliateMetrics(int $affiliateId): array
            {
                return [
                    'share_count' => 0,
                    'outbound_share_count' => 0,
                    'click_count' => 0,
                    'view_count' => 0,
                    'wishlist_count' => 0,
                    'add_to_cart_count' => 0,
                    'review_count' => 0,
                    'order_count' => 0,
                    'paid_count' => 0,
                    'cancelled_count' => 0,
                    'refunded_count' => 0,
                    'pending_commission' => 0.0,
                    'approved_commission' => 0.0,
                    'paid_ledger_commission' => 0.0,
                    'cancelled_commission' => 0.0,
                ];
            }

            protected function getAffiliateWorkspaceData(int $affiliateId): array
            {
                return [
                    'share_links' => [],
                    'referred_customers' => [],
                    'promoted_products' => [],
                    'affiliate_orders' => [],
                    'commission_ledger' => [],
                ];
            }
        };

        $summary = $service->getAffiliateSummary(12);

        $this->assertSame(12, $summary['affiliate_id']);
        $this->assertSame('REF00000012ABCD', $summary['referral_code']);
        $this->assertSame(119.5, $summary['pending_commission']);
        $this->assertSame('https://shop.example.test/customer/account/register?ref=REF00000012ABCD', $summary['referral_link']);
        $this->assertStringStartsWith('https://', $summary['referral_link']);
        $this->assertStringContainsString('/customer/account/register?ref=', $summary['referral_link']);
        $this->assertStringNotContainsString('https://shop.example.test/register?ref=', $summary['referral_link']);
    }

    public function testCommissionBaseDeductsOrderDiscountProportionally(): void
    {
        $service = new class() extends AffiliateService {
            public function exposedCalculateCommissionBase(array $item, array $summary, array $allItems = []): float
            {
                return $this->calculateCommissionBase($item, $summary, $allItems);
            }
        };

        $base = $service->exposedCalculateCommissionBase(
            ['price' => 100.0, 'quantity' => 2, 'total' => 200.0],
            ['subtotal' => 500.0, 'discount' => 50.0, 'shipping' => 40.0, 'tax' => 30.0]
        );

        $this->assertSame(180.0, $base);
    }

    public function testCommissionBaseExcludesShippingAndTax(): void
    {
        $service = new class() extends AffiliateService {
            public function exposedCalculateCommissionBase(array $item, array $summary, array $allItems = []): float
            {
                return $this->calculateCommissionBase($item, $summary, $allItems);
            }
        };

        $base = $service->exposedCalculateCommissionBase(
            ['price' => 100.0, 'quantity' => 1],
            ['subtotal' => 100.0, 'discount' => 0.0, 'shipping' => 80.0, 'tax' => 15.0]
        );

        $this->assertSame(100.0, $base);
    }

    public function testGetAffiliateSummaryUsesLedgerPendingWhenAvailable(): void
    {
        $service = new class() extends AffiliateService {
            protected function getAffiliateAccountOrCreate(int $customerId): array
            {
                return [
                    'affiliate_id' => 21,
                    'customer_id' => $customerId,
                    'referral_code' => 'REF00000021ABCD',
                    'commission_rate' => 0.2,
                    'total_commission' => 500.0,
                    'paid_commission' => 100.0,
                    'status' => 'active',
                ];
            }

            protected function getAffiliateMetrics(int $affiliateId): array
            {
                return [
                    'share_count' => 3,
                    'outbound_share_count' => 5,
                    'click_count' => 9,
                    'view_count' => 7,
                    'wishlist_count' => 2,
                    'add_to_cart_count' => 1,
                    'review_count' => 1,
                    'order_count' => 1,
                    'paid_count' => 0,
                    'cancelled_count' => 0,
                    'refunded_count' => 0,
                    'pending_commission' => 35.5,
                    'approved_commission' => 120.0,
                    'paid_ledger_commission' => 0.0,
                    'cancelled_commission' => 0.0,
                ];
            }

            public function getReferralLink(string $referralCode): string
            {
                return 'https://shop.example.test/customer/account/register?ref=' . rawurlencode($referralCode);
            }

            protected function getDefaultShareLinkData(int $customerId): array
            {
                return [];
            }

            protected function getAffiliateWorkspaceData(int $affiliateId): array
            {
                return [
                    'share_links' => [],
                    'referred_customers' => [],
                    'promoted_products' => [],
                    'affiliate_orders' => [],
                    'commission_ledger' => [],
                ];
            }
        };

        $summary = $service->getAffiliateSummary(21);

        $this->assertSame(35.5, $summary['pending_commission']);
        $this->assertSame(3, $summary['share_count']);
        $this->assertSame(120.0, $summary['approved_commission']);
    }

    public function testShareTargetUsesStableRelativeProductRoute(): void
    {
        $service = new AffiliateService();
        $share = new AffiliateShare();
        $share->setData(AffiliateShare::schema_fields_PRODUCT_ID, 652);
        $share->setData(AffiliateShare::schema_fields_TARGET_PATH, 'product/view');

        $method = new \ReflectionMethod(AffiliateService::class, 'resolveShareTargetUrl');
        $method->setAccessible(true);

        $this->assertSame('/product/frontend/product/view?id=652', $method->invoke($service, $share));
    }

    public function testShareTargetUsesPublicProductHandleWithoutExtraIdParam(): void
    {
        $service = new AffiliateService();
        $share = new AffiliateShare();
        $share->setData(AffiliateShare::schema_fields_PRODUCT_ID, 1075);
        $share->setData(AffiliateShare::schema_fields_TARGET_PATH, 'product/airpods-pro-magsafe');

        $method = new \ReflectionMethod(AffiliateService::class, 'resolveShareTargetUrl');
        $method->setAccessible(true);

        $this->assertSame('/product/airpods-pro-magsafe', $method->invoke($service, $share));
    }

    public function testProductShareRedirectRecordsProductViewFallbackForCachedProductPages(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../Service/AffiliateService.php');

        $this->assertStringContainsString('private const PRODUCT_VIEW_DEDUPE_SECONDS = 60;', $source);
        $this->assertStringContainsString('$this->recordEngagement(self::EVENT_PRODUCT_VIEWED', $source);
        $this->assertStringContainsString("'source' => 'product_share_redirect'", $source);
        $this->assertStringContainsString("'idempotency_key' => 'product_share_redirect:' . \$shareId . ':' . \$visitorKey", $source);
        $this->assertStringContainsString(
            '$this->hasRecentTouch($shareId, $eventType, $visitorKey, self::PRODUCT_VIEW_DEDUPE_SECONDS)',
            $source
        );
    }
}
