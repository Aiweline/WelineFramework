<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Api\Coupon;

use PHPUnit\Framework\TestCase;

final class RandomCouponCampaignProviderContractTest extends TestCase
{
    public function testProviderIsRegisteredAndImplementsInterface(): void
    {
        $module = dirname(__DIR__, 4) . '/etc/module.php';
        $interface = dirname(__DIR__, 4) . '/Api/Coupon/RandomCouponCampaignProviderInterface.php';
        $service = dirname(__DIR__, 4) . '/Service/RandomCouponCampaignProvider.php';
        self::assertFileExists($module);
        self::assertFileExists($interface);
        self::assertFileExists($service);

        /** @var array<string, mixed> $meta */
        $meta = include $module;
        $provides = is_array($meta['provides'] ?? null) ? $meta['provides'] : [];
        self::assertArrayHasKey(
            \Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface::class,
            $provides
        );
        self::assertSame(
            \Weline\Marketing\Service\RandomCouponCampaignProvider::class,
            $provides[\Weline\Marketing\Api\Coupon\RandomCouponCampaignProviderInterface::class]
        );

        $svc = (string)file_get_contents($service);
        self::assertStringContainsString('implements RandomCouponCampaignProviderInterface', $svc);
        self::assertStringContainsString('issueRandomCoupon', $svc);
        self::assertStringContainsString('RULE_TYPE_COUPON', $svc);
        self::assertStringContainsString("'apply_to' => 'subtotal'", $svc);
        self::assertStringNotContainsString("'apply_to' => 'cart'", $svc);
    }
}
