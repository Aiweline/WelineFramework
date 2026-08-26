<?php

declare(strict_types=1);

namespace Weline\Subscription\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Api\Data\ProductValidationContext;
use Weline\Product\Api\ProductProviderV2Interface;
use Weline\Product\Service\ProductProviderRegistry;
use Weline\Subscription\Extends\Module\Weline_Product\ProductProvider\SubscriptionProductProvider;
use Weline\Subscription\Service\SubscriptionProviderRegistry;

final class SubscriptionProductProviderTest extends TestCase
{
    private const OFFER_A = '11111111-1111-4111-8111-111111111111';
    private const OFFER_B = '22222222-2222-4222-8222-222222222222';

    public function testRealExtensionDescriptorRegistersAProductProviderV2(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/extends/module/Weline_Product/ProductProvider/SubscriptionProductProvider.php';
        self::assertFileExists($sourceFile);
        $registry = ProductProviderRegistry::forTesting();
        $instantiate = new \ReflectionMethod(ProductProviderRegistry::class, 'instantiateExtension');
        $provider = $instantiate->invoke($registry, [
            'relative_path' => 'extends/module/Weline_Product/ProductProvider/SubscriptionProductProvider.php',
            'source_file' => $sourceFile,
        ]);

        self::assertInstanceOf(ProductProviderV2Interface::class, $provider);
        $registry->register($provider);
        self::assertSame($provider, $registry->getByType('subscription'));
        self::assertContains('interval_monthly', $provider->getMetadata()['subscription_provider_codes']);

        $definition = $provider->getDefinition();
        self::assertSame('subscription', $definition->code);
        self::assertSame(1, $definition->minimumOffers);
        self::assertNull($definition->maximumOffers);
        self::assertTrue($definition->supportsPricing);
        self::assertFalse($definition->tracksInventory);
        self::assertFalse($definition->requiresShipping);
        self::assertFalse($definition->supportsDigitalDelivery);
        self::assertFalse($definition->supportsComposition);
    }

    public function testValidInheritedExplicitZeroPriceAndIntervalPlanCanPublish(): void
    {
        $provider = $this->provider();
        $result = $provider->validateForPublish(new ProductValidationContext(
            productType: 'subscription',
            product: ['name' => 'Pro Membership'],
            offers: [[
                'global_offer_uuid' => self::OFFER_A,
                'sku' => 'SUB-PRO-MONTH',
                'requires_shipping' => false,
            ]],
            prices: [[
                'global_offer_uuid' => self::OFFER_A,
                'store_id' => 0,
                'currency' => 'CNY',
                'amount_minor' => 0,
                'scope_state' => 'explicit',
            ]],
            storeIds: [2],
            typeConfiguration: [
                'plans' => [[
                    'global_offer_uuid' => self::OFFER_A,
                    'provider_code' => 'interval_monthly',
                    'plan_code' => 'pro_monthly',
                ]],
            ],
            currency: 'CNY',
        ));

        self::assertTrue($result->isValid(), json_encode($result->toArray()));
        self::assertSame([], $result->errors);
    }

    public function testMissingPlanPriceAndShippingAreBlockedWithStableCodes(): void
    {
        $result = $this->provider()->validateForPublish(new ProductValidationContext(
            productType: 'subscription',
            product: ['name' => 'Broken'],
            offers: [[
                'global_offer_uuid' => self::OFFER_A,
                'sku' => '',
                'requires_shipping' => true,
            ]],
            storeIds: [0],
            typeConfiguration: [],
        ));
        $codes = array_column($result->errors, 'code');

        self::assertContains('offer_sku_required', $codes);
        self::assertContains('offer_price_required', $codes);
        self::assertContains('subscription_shipping_not_supported', $codes);
        self::assertContains('subscription_plan_configuration_required', $codes);
    }

    public function testUnknownProviderAndDuplicatePlanAreBlocked(): void
    {
        $unknown = $this->provider()->validateForPublish($this->contextWithPlans([
            [
                'global_offer_uuid' => self::OFFER_A,
                'provider_code' => 'missing_provider',
                'plan_code' => 'pro',
            ],
            [
                'global_offer_uuid' => self::OFFER_B,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'basic',
            ],
        ]));
        self::assertContains(
            'subscription_plan_provider_unavailable',
            array_column($unknown->errors, 'code'),
        );

        $duplicate = $this->provider()->validateForPublish($this->contextWithPlans([
            [
                'global_offer_uuid' => self::OFFER_A,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'same_plan',
            ],
            [
                'global_offer_uuid' => self::OFFER_B,
                'provider_code' => 'interval_monthly',
                'plan_code' => 'same_plan',
            ],
        ]));
        self::assertContains('subscription_plan_duplicate', array_column($duplicate->errors, 'code'));
    }

    private function provider(): SubscriptionProductProvider
    {
        return new SubscriptionProductProvider(SubscriptionProviderRegistry::forTesting());
    }

    /** @param list<array<string,mixed>> $plans */
    private function contextWithPlans(array $plans): ProductValidationContext
    {
        return new ProductValidationContext(
            productType: 'subscription',
            product: ['name' => 'Membership'],
            offers: [
                [
                    'global_offer_uuid' => self::OFFER_A,
                    'sku' => 'SUB-A',
                    'requires_shipping' => false,
                ],
                [
                    'global_offer_uuid' => self::OFFER_B,
                    'sku' => 'SUB-B',
                    'requires_shipping' => false,
                ],
            ],
            prices: [
                [
                    'global_offer_uuid' => self::OFFER_A,
                    'store_id' => 0,
                    'currency' => 'CNY',
                    'amount_minor' => 1000,
                ],
                [
                    'global_offer_uuid' => self::OFFER_B,
                    'store_id' => 0,
                    'currency' => 'CNY',
                    'amount_minor' => 2000,
                ],
            ],
            storeIds: [0],
            typeConfiguration: ['plans' => $plans],
            currency: 'CNY',
        );
    }
}
