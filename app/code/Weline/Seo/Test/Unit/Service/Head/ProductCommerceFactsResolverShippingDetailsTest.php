<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\ProductCommerceFactsResolver;
use Weline\Shipping\Model\ShippingService;

/**
 * Equivalence guard for the batched OfferShippingDetails assembly.
 *
 * The resolver used to query the service and its lane regions once per service
 * id. It now receives both maps from ShippingServiceBatchLoader. This test pins
 * the pure assembly step against a reference implementation of the old
 * per-service behaviour, so the JSON-LD output must stay byte identical.
 */
final class ProductCommerceFactsResolverShippingDetailsTest extends TestCase
{
    private function resolver(): ProductCommerceFactsResolver
    {
        return (new \ReflectionClass(ProductCommerceFactsResolver::class))
            ->newInstanceWithoutConstructor();
    }

    /** @param array<string, mixed> $data */
    private function service(array $data = []): ShippingService
    {
        /** @var ShippingService $service */
        $service = (new \ReflectionClass(ShippingService::class))->newInstanceWithoutConstructor();
        $service->setData([
            ShippingService::schema_fields_ID => 1,
            ShippingService::schema_fields_IS_ACTIVE => 1,
            ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 0,
            ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 0,
            ...$data,
        ]);

        return $service;
    }

    /**
     * @param list<int> $serviceIds
     * @param array<int, ShippingService> $services
     * @param array<int, list<string>> $codes
     * @return list<array<string, mixed>>
     */
    private function build(array $serviceIds, array $services, array $codes, string $currency): array
    {
        $method = new \ReflectionMethod(ProductCommerceFactsResolver::class, 'buildShippingDetails');
        $method->setAccessible(true);

        return $method->invoke($this->resolver(), $serviceIds, $services, $codes, $currency);
    }

    /**
     * Reference implementation of the removed per-service loop.
     *
     * @param list<int> $serviceIds
     * @param array<int, ShippingService> $services
     * @param array<int, list<string>> $codes
     * @return list<array<string, mixed>>
     */
    private function reference(array $serviceIds, array $services, array $codes, string $currency): array
    {
        $details = [];
        foreach ($serviceIds as $serviceId) {
            $service = $services[(int)$serviceId] ?? null;
            if (!$service instanceof ShippingService) {
                continue;
            }
            $serviceCodes = $codes[(int)$serviceId] ?? [];
            if ($serviceCodes === []) {
                continue;
            }
            $addressCountry = \count($serviceCodes) === 1
                ? \reset($serviceCodes)
                : \array_values($serviceCodes);
            $detail = [
                '@type' => 'OfferShippingDetails',
                'shippingDestination' => [
                    '@type' => 'DefinedRegion',
                    'addressCountry' => $addressCountry,
                ],
            ];
            $min = \max(0, (int)$service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MIN));
            $max = \max(0, (int)$service->getData(ShippingService::schema_fields_ESTIMATED_DAYS_MAX));
            if ($max > 0) {
                if ($min > $max) {
                    [$min, $max] = [$max, $min];
                }
                $detail['deliveryTime'] = [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => 0,
                        'maxValue' => 1,
                        'unitCode' => 'DAY',
                    ],
                    'transitTime' => [
                        '@type' => 'QuantitativeValue',
                        'minValue' => $min,
                        'maxValue' => $max,
                        'unitCode' => 'DAY',
                    ],
                ];
            }
            $detail['shippingRate'] = [
                '@type' => 'MonetaryAmount',
                'value' => 0,
                'currency' => $currency,
            ];
            $details[] = $detail;
        }

        return $details;
    }

    public function testBatchedAssemblyMatchesPerServiceReference(): void
    {
        $services = [
            11 => $this->service([
                ShippingService::schema_fields_ID => 11,
                ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 3,
                ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 7,
            ]),
            12 => $this->service([ShippingService::schema_fields_ID => 12]),
            14 => $this->service([
                ShippingService::schema_fields_ID => 14,
                ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 9,
                ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 4,
            ]),
        ];
        $codes = [
            11 => ['CN', 'US'],
            12 => ['JP'],
            // 13 exists in the id list but has no active lane: dropped by both paths.
            14 => ['DE'],
        ];
        $ids = [11, 12, 13, 14];

        $batched = $this->build($ids, $services, $codes, 'USD');
        $expected = $this->reference($ids, $services, $codes, 'USD');

        self::assertSame($expected, $batched, 'array shape and order must match the old loop');
        self::assertSame(
            \json_encode($expected),
            \json_encode($batched),
            'JSON-LD bytes must be identical',
        );
        self::assertCount(3, $batched, 'id 13 has no lane and must be dropped');
    }

    public function testMultiCountryLaneKeepsCodeOrder(): void
    {
        $services = [11 => $this->service([ShippingService::schema_fields_ID => 11])];
        $codes = [11 => ['CN', 'US', 'JP']];

        $batched = $this->build([11], $services, $codes, 'CNY');

        self::assertSame(['CN', 'US', 'JP'], $batched[0]['shippingDestination']['addressCountry']);
        self::assertSame($this->reference([11], $services, $codes, 'CNY'), $batched);
    }

    public function testSingleCountryLaneStaysScalar(): void
    {
        $services = [11 => $this->service([ShippingService::schema_fields_ID => 11])];
        $codes = [11 => ['CN']];

        $batched = $this->build([11], $services, $codes, 'CNY');

        self::assertSame('CN', $batched[0]['shippingDestination']['addressCountry']);
        self::assertSame($this->reference([11], $services, $codes, 'CNY'), $batched);
    }

    public function testMissingServiceAndEmptyLaneAreDroppedLikeTheOldLoop(): void
    {
        $services = [11 => $this->service([ShippingService::schema_fields_ID => 11])];
        $codes = [11 => []];

        self::assertSame([], $this->build([11, 12], $services, $codes, 'CNY'));
        self::assertSame($this->reference([11, 12], $services, $codes, 'CNY'), $this->build([11, 12], $services, $codes, 'CNY'));
    }

    public function testDeliveryWindowSwapsInvertedBoundsAndSkipsZeroMax(): void
    {
        $services = [
            11 => $this->service([
                ShippingService::schema_fields_ID => 11,
                ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 9,
                ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 4,
            ]),
            12 => $this->service([
                ShippingService::schema_fields_ID => 12,
                ShippingService::schema_fields_ESTIMATED_DAYS_MIN => 3,
                ShippingService::schema_fields_ESTIMATED_DAYS_MAX => 0,
            ]),
        ];
        $codes = [11 => ['CN'], 12 => ['US']];

        $batched = $this->build([11, 12], $services, $codes, 'CNY');

        self::assertSame(4, $batched[0]['deliveryTime']['transitTime']['minValue']);
        self::assertSame(9, $batched[0]['deliveryTime']['transitTime']['maxValue']);
        self::assertArrayNotHasKey('deliveryTime', $batched[1], 'max=0 keeps the node free of deliveryTime');
        self::assertSame($this->reference([11, 12], $services, $codes, 'CNY'), $batched);
    }
}
