<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\ProductCommerceFactsResolver;
use Weline\Seo\Service\Merchant\MerchantInfoConfigResolver;

/**
 * Google return-policy contract for offers.hasMerchantReturnPolicy.
 *
 * Verified against https://developers.google.com/search/docs/appearance/structured-data/return-policy :
 * - FreeReturn and ReturnFeesCustomerResponsibility must NOT carry returnShippingFeesAmount.
 * - ReturnShippingFees MUST carry the non-zero returnShippingFeesAmount.
 * So the emitted node may never be a bare ReturnShippingFees; when the merchant
 * charges no fixed return shipping fee it must downgrade to ReturnFeesCustomerResponsibility.
 */
final class ProductCommerceFactsResolverReturnPolicyTest extends TestCase
{
    private const FREE_RETURN = 'https://schema.org/FreeReturn';
    private const SHIPPING_FEES = 'https://schema.org/ReturnShippingFees';
    private const CUSTOMER_RESPONSIBILITY = 'https://schema.org/ReturnFeesCustomerResponsibility';

    private function resolver(): ProductCommerceFactsResolver
    {
        return (new \ReflectionClass(ProductCommerceFactsResolver::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $info
     */
    private function seedMerchantInfo(ProductCommerceFactsResolver $resolver, array $info): void
    {
        $property = new \ReflectionProperty(ProductCommerceFactsResolver::class, 'merchantInfo');
        $property->setValue($resolver, $info);
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>|null
     */
    private function returnPolicy(array $info): ?array
    {
        $resolver = $this->resolver();
        $this->seedMerchantInfo($resolver, $info);
        $method = new \ReflectionMethod(ProductCommerceFactsResolver::class, 'returnPolicy');

        return $method->invoke($resolver, [], ['canonical_url' => 'https://shop.test/product/linen-dress']);
    }

    /**
     * @param array<string, mixed> $configured
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function merchantInfo(array $configured, array $overrides = []): array
    {
        return array_replace([
            'country' => 'WW',
            'return_days' => 30,
            'return_method' => MerchantInfoConfigResolver::RETURN_METHOD_MAIL,
            'return_fees' => MerchantInfoConfigResolver::RETURN_FEES_BUYER,
            'return_shipping_fee' => 0.0,
            'return_policy_url' => '/policy/refund',
            'configured' => $configured,
        ], $overrides);
    }

    public function testBuyerPaysWithoutSavedFeeDowngradesToCustomerResponsibility(): void
    {
        $policy = $this->returnPolicy($this->merchantInfo([
            MerchantInfoConfigResolver::KEY_RETURN_FEES => true,
            MerchantInfoConfigResolver::KEY_RETURN_SHIPPING_FEE => false,
        ]));

        self::assertIsArray($policy);
        self::assertSame(self::CUSTOMER_RESPONSIBILITY, $policy['returnFees']);
        self::assertArrayNotHasKey('returnShippingFeesAmount', $policy);
    }

    public function testBuyerPaysWithSavedZeroFeeDoesNotEmitBareShippingFees(): void
    {
        $policy = $this->returnPolicy($this->merchantInfo([
            MerchantInfoConfigResolver::KEY_RETURN_FEES => true,
            MerchantInfoConfigResolver::KEY_RETURN_SHIPPING_FEE => true,
        ]));

        self::assertIsArray($policy);
        self::assertNotSame(self::SHIPPING_FEES, $policy['returnFees']);
        self::assertArrayNotHasKey('returnShippingFeesAmount', $policy);
    }

    public function testBuyerPaysWithSavedPositiveFeeKeepsShippingFeesWithAmount(): void
    {
        $policy = $this->returnPolicy($this->merchantInfo([
            MerchantInfoConfigResolver::KEY_RETURN_FEES => true,
            MerchantInfoConfigResolver::KEY_RETURN_SHIPPING_FEE => true,
        ], ['return_shipping_fee' => 25.0]));

        self::assertIsArray($policy);
        self::assertSame(self::SHIPPING_FEES, $policy['returnFees']);
        self::assertArrayHasKey('returnShippingFeesAmount', $policy);
        self::assertSame('MonetaryAmount', $policy['returnShippingFeesAmount']['@type']);
        self::assertSame('25.00', $policy['returnShippingFeesAmount']['value']);
    }

    public function testFreeReturnPolicyNeverCarriesAmount(): void
    {
        $policy = $this->returnPolicy($this->merchantInfo([
            MerchantInfoConfigResolver::KEY_RETURN_FEES => true,
            MerchantInfoConfigResolver::KEY_RETURN_SHIPPING_FEE => true,
        ], ['return_fees' => MerchantInfoConfigResolver::RETURN_FEES_FREE, 'return_shipping_fee' => 25.0]));

        self::assertIsArray($policy);
        self::assertSame(self::FREE_RETURN, $policy['returnFees']);
        self::assertArrayNotHasKey('returnShippingFeesAmount', $policy);
    }

    public function testEmittedPolicyNeverPublishesShippingFeesWithoutAmount(): void
    {
        $combinations = [
            [true, false, 0.0],
            [true, true, 0.0],
            [true, true, 25.0],
            [true, false, 25.0],
        ];
        foreach ($combinations as [$feesConfigured, $feeConfigured, $fee]) {
            $policy = $this->returnPolicy($this->merchantInfo([
                MerchantInfoConfigResolver::KEY_RETURN_FEES => $feesConfigured,
                MerchantInfoConfigResolver::KEY_RETURN_SHIPPING_FEE => $feeConfigured,
            ], ['return_shipping_fee' => $fee]));

            self::assertIsArray($policy);
            if ($policy['returnFees'] === self::SHIPPING_FEES) {
                self::assertArrayHasKey('returnShippingFeesAmount', $policy);
            } else {
                self::assertArrayNotHasKey('returnShippingFeesAmount', $policy);
            }
        }
    }
}
