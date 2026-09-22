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
        self::assertStringContainsString('CouponSourceAttribution', $svc);
        self::assertStringContainsString('schema_fields_SOURCE_TYPE', $svc);
        self::assertStringContainsString('resolveIssueValidDays', $svc);
        self::assertStringContainsString("86400 * \$validDays", $svc);
        self::assertStringNotContainsString('86400 * 30', $svc);
    }

    public function testIssueValidDaysFromContextDefaultsToThirty(): void
    {
        $provider = new \Weline\Marketing\Service\RandomCouponCampaignProvider();
        $method = new \ReflectionMethod($provider, 'resolveIssueValidDays');
        $method->setAccessible(true);

        self::assertSame(30, $method->invoke($provider, []));
        self::assertSame(30, $method->invoke($provider, ['valid_days' => 0]));
        self::assertSame(30, $method->invoke($provider, ['valid_days' => -1]));
        self::assertSame(30, $method->invoke($provider, ['valid_days' => '14']));
        self::assertSame(14, $method->invoke($provider, ['valid_days' => 14]));
        self::assertSame(7, $method->invoke($provider, ['valid_days' => 7]));

        $endWith14 = \gmdate('Y-m-d H:i:s', \time() + 86400 * 14);
        $endDefault = \gmdate('Y-m-d H:i:s', \time() + 86400 * 30);
        $approx14 = \gmdate('Y-m-d H:i:s', \time() + 86400 * (int)$method->invoke($provider, ['valid_days' => 14]));
        $approx30 = \gmdate('Y-m-d H:i:s', \time() + 86400 * (int)$method->invoke($provider, []));
        self::assertSame($endWith14, $approx14);
        self::assertSame($endDefault, $approx30);
    }
}
