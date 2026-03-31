<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Provider\ShippingFilterProvider;

class ShippingFilterProviderTest extends TestCase
{
    public function testGetOptionsBuildsDataDrivenShippingFacetsIncludingExpress(): void
    {
        $provider = new class([
            [
                'product_id' => 11,
                'free_shipping' => true,
                'delivery_days' => 0,
                'shipping_options' => '["free_shipping","fedex"]',
            ],
            [
                'product_id' => 12,
                'next_day_delivery' => 1,
                'shipping_methods' => 'dhl,express',
            ],
            [
                'product_id' => 13,
                'delivery_speed' => 'overnight',
            ],
        ]) extends ShippingFilterProvider {
            /**
             * @param array<int, array<string, mixed>> $products
             */
            public function __construct(private readonly array $products)
            {
                parent::__construct();
            }

            protected function loadProducts(array $productIds): array
            {
                return array_values(array_filter(
                    $this->products,
                    static fn (array $product): bool => in_array((int) ($product['product_id'] ?? 0), $productIds, true)
                ));
            }
        };

        $options = $provider->getOptions(0, [11, 12, 13], [
            'shipping' => ['express'],
        ]);

        $this->assertSame(
            [
                ShippingFilterProvider::SHIPPING_FREE,
                ShippingFilterProvider::SHIPPING_SAME_DAY,
                ShippingFilterProvider::SHIPPING_NEXT_DAY,
                ShippingFilterProvider::SHIPPING_EXPRESS,
            ],
            array_column($options, 'value')
        );
        $this->assertSame([1, 1, 3, 3], array_column($options, 'count'));
        $this->assertFalse((bool) $options[0]['selected']);
        $this->assertTrue((bool) $options[3]['selected']);
    }

    public function testApplyUsesExplicitShippingMetadataInsteadOfLegacyPriceOrStockHeuristics(): void
    {
        $provider = new class([
            [
                'product_id' => 21,
                'price' => 999.0,
                'stock' => 999,
            ],
            [
                'product_id' => 22,
                'is_free_shipping' => 'yes',
            ],
            [
                'product_id' => 23,
                'same_day_delivery' => true,
                'stock' => 0,
            ],
            [
                'product_id' => 24,
                'shipping_speed' => 'next-day',
            ],
        ]) extends ShippingFilterProvider {
            /**
             * @param array<int, array<string, mixed>> $products
             */
            public function __construct(private readonly array $products)
            {
                parent::__construct();
            }

            protected function loadProducts(array $productIds): array
            {
                return array_values(array_filter(
                    $this->products,
                    static fn (array $product): bool => in_array((int) ($product['product_id'] ?? 0), $productIds, true)
                ));
            }
        };

        $result = $provider->apply([21, 22, 23, 24], [
            ShippingFilterProvider::SHIPPING_FREE,
            ShippingFilterProvider::SHIPPING_NEXT_DAY,
        ]);

        $this->assertSame([22, 23, 24], $result);
    }

    public function testGetOptionsStaysConservativeWhenNoShippingMetadataExists(): void
    {
        $provider = new class([
            [
                'product_id' => 31,
                'price' => 199.0,
                'stock' => 88,
            ],
            [
                'product_id' => 32,
                'price' => 29.0,
                'stock' => 2,
            ],
        ]) extends ShippingFilterProvider {
            /**
             * @param array<int, array<string, mixed>> $products
             */
            public function __construct(private readonly array $products)
            {
                parent::__construct();
            }

            protected function loadProducts(array $productIds): array
            {
                return array_values(array_filter(
                    $this->products,
                    static fn (array $product): bool => in_array((int) ($product['product_id'] ?? 0), $productIds, true)
                ));
            }
        };

        $options = $provider->getOptions(0, [31, 32]);

        $this->assertSame([], $options);
    }
}
