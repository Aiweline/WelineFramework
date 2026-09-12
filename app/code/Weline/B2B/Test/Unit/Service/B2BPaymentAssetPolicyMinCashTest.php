<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BPaymentAssetPolicyProvider;

final class B2BPaymentAssetPolicyMinCashTest extends TestCase
{
    public function testEnabledPolicyExposesMinCashFloorAndDiscountCap(): void
    {
        $provider = B2BPaymentAssetPolicyProvider::forTesting(true, 20);
        $policies = $provider->getAssetPolicies();
        $row = $policies[SystemVipLadder::ASSET_CODE_B2B_CREDIT] ?? null;
        self::assertIsArray($row);
        self::assertSame(2000, $row['min_cash_deposit_bps']);
        self::assertSame('0.8000', $row['max_discount_ratio']);
        self::assertSame(20, $provider->minCashDepositPercent());
    }

    public function testDisabledPolicyReturnsEmpty(): void
    {
        $provider = B2BPaymentAssetPolicyProvider::forTesting(false, 50);
        self::assertSame([], $provider->getAssetPolicies());
    }

    public function testZeroPercentAllowsFullCreditCap(): void
    {
        $provider = B2BPaymentAssetPolicyProvider::forTesting(true, 0);
        $row = $provider->getAssetPolicies()[SystemVipLadder::ASSET_CODE_B2B_CREDIT];
        self::assertSame(0, $row['min_cash_deposit_bps']);
        self::assertSame('1.0000', $row['max_discount_ratio']);
    }
}
