<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\AssetAllocationService;
use Weline\Payment\Service\AssetPaymentService;

final class PaymentAssetPolicySpiContractTest extends TestCase
{
    public function testBuildAssetPolicyPreservesRequiredOrderTypes(): void
    {
        $service = new AssetPaymentService();
        $policy = $service->buildAssetPolicy([
            'b2b_credit' => [
                'enabled' => true,
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT => false,
                    AssetAllocationService::ROLE_DISCOUNT => true,
                ],
                'exchange_ratio' => '1',
                'required_order_types' => ['tob'],
            ],
        ]);

        self::assertTrue($policy['b2b_credit']['enabled']);
        self::assertTrue($policy['b2b_credit']['roles'][AssetAllocationService::ROLE_DISCOUNT]);
        self::assertSame(['tob'], $policy['b2b_credit']['required_order_types']);
    }

    public function testAssertAssetPayableAllowedRequiresOrderType(): void
    {
        $service = new AssetPaymentService();
        $policy = $service->buildAssetPolicy([
            'b2b_credit' => [
                'enabled' => true,
                'roles' => [
                    AssetAllocationService::ROLE_DISCOUNT => true,
                ],
                'exchange_ratio' => '1',
                'required_order_types' => ['tob'],
            ],
        ]);

        $service->assertAssetPayableAllowed('b2b_credit', ['order_type' => 'tob'], $policy);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('payment_asset_order_type_not_allowed:b2b_credit:toc');
        $service->assertAssetPayableAllowed('b2b_credit', ['order_type' => 'toc'], $policy);
    }

    public function testProviderCapabilityPrefixIsStable(): void
    {
        self::assertSame(
            'payment.asset_policy.',
            \Weline\Payment\Api\PaymentAssetPolicyProviderInterface::CAPABILITY_PREFIX,
        );
        $module = include dirname(__DIR__, 4) . '/B2B/etc/module.php';
        self::assertSame(
            \Weline\B2B\Service\B2BPaymentAssetPolicyProvider::class,
            $module['provides']['payment.asset_policy.Weline_B2B'] ?? null,
        );
    }
}
