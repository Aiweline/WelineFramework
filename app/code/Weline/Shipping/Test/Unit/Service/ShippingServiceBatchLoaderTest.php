<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Shipping\Service\ShippingServiceBatchLoader;

/**
 * Batch + request memo contract for the shipping service/region fan-out that
 * head rendering performs once per rendered product.
 *
 * The loader is the only seam that can be unit tested without a database: the
 * SQL is injected as a closure, so these tests prove the *shape* (one IN query
 * per table per request, negative caching, request scope) and the equivalence
 * against per-service lookups.
 */
final class ShippingServiceBatchLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('shipping-batch-loader-test');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    /**
     * @param list<array<string, mixed>> $serviceRows
     * @param list<array<string, mixed>> $regionRows
     * @param list<list<int>> $serviceCalls
     * @param list<list<int>> $regionCalls
     */
    private function loader(
        array $serviceRows,
        array $regionRows,
        array &$serviceCalls,
        array &$regionCalls,
    ): ShippingServiceBatchLoader {
        return new ShippingServiceBatchLoader(
            null,
            static function (array $ids) use ($serviceRows, &$serviceCalls): array {
                $serviceCalls[] = $ids;

                return $serviceRows;
            },
            static function (array $ids) use ($regionRows, &$regionCalls): array {
                $regionCalls[] = $ids;

                return $regionRows;
            },
        );
    }

    public function testServicesAreFetchedInOneBatchAndMemoized(): void
    {
        $serviceCalls = [];
        $regionCalls = [];
        $loader = $this->loader(
            [
                ['service_id' => 11, 'is_active' => 1],
                ['service_id' => 12, 'is_active' => 1],
            ],
            [],
            $serviceCalls,
            $regionCalls,
        );

        $first = $loader->loadActiveServices([11, 12, 13]);
        self::assertSame([[11, 12, 13]], $serviceCalls, 'one IN query for the whole id set');
        self::assertSame([11, 12], \array_keys($first));
        self::assertSame(11, (int)$first[11]->getId());
        self::assertSame(12, (int)$first[12]->getId());

        $second = $loader->loadActiveServices([11, 12, 13]);
        self::assertCount(1, $serviceCalls, 'second lookup must be served from the request memo');
        self::assertSame([11, 12], \array_keys($second));
    }

    public function testDisabledOrMissingServicesAreNegativelyCached(): void
    {
        $serviceCalls = [];
        $regionCalls = [];
        $loader = $this->loader(
            [['service_id' => 11, 'is_active' => 1]],
            [],
            $serviceCalls,
            $regionCalls,
        );

        self::assertSame([11], \array_keys($loader->loadActiveServices([11, 12])));
        self::assertCount(1, $serviceCalls);

        // 12 was proven absent/inactive; asking again must not hit the database.
        self::assertSame([11], \array_keys($loader->loadActiveServices([11, 12])));
        self::assertCount(1, $serviceCalls, 'negative cache must suppress the second query');

        // A brand new id still queries, and only for the new id.
        $loader->loadActiveServices([11, 12, 13]);
        self::assertSame([[11, 12], [13]], $serviceCalls);
    }

    public function testRegionCountryCodesAreGroupedDedupedAndOrdered(): void
    {
        $serviceCalls = [];
        $regionCalls = [];
        $loader = $this->loader(
            [],
            [
                ['service_id' => 11, 'country_code' => 'cn', 'service_region_id' => 1],
                ['service_id' => 11, 'country_code' => 'CN', 'service_region_id' => 2],
                ['service_id' => 11, 'country_code' => 'us', 'service_region_id' => 3],
                ['service_id' => 12, 'country_code' => ' jp ', 'service_region_id' => 4],
                ['service_id' => 12, 'country_code' => '', 'service_region_id' => 5],
            ],
            $serviceCalls,
            $regionCalls,
        );

        $codes = $loader->loadActiveRegionCountryCodes([11, 12, 13]);
        self::assertSame([[11, 12, 13]], $regionCalls, 'one IN query for all service ids');
        self::assertSame(['CN', 'US'], $codes[11], 'upper-cased, de-duplicated, first-seen order');
        self::assertSame(['JP'], $codes[12]);
        self::assertSame([], $codes[13], 'service without lanes resolves to an empty list');

        $loader->loadActiveRegionCountryCodes([11, 12, 13]);
        self::assertCount(1, $regionCalls, 'region lookup must be memoized too');
    }

    public function testProfileServiceIdsAreMemoized(): void
    {
        $serviceCalls = [];
        $regionCalls = [];
        $profileCalls = [];
        $loader = new ShippingServiceBatchLoader(
            null,
            static function (array $ids) use (&$serviceCalls): array {
                $serviceCalls[] = $ids;

                return [];
            },
            static function (array $ids) use (&$regionCalls): array {
                $regionCalls[] = $ids;

                return [];
            },
            static function (int $profileId) use (&$profileCalls): array {
                $profileCalls[] = $profileId;

                return [11, '12', 0, 11];
            },
        );

        self::assertSame([11, 12], $loader->serviceIdsForProfile(7));
        self::assertSame([11, 12], $loader->serviceIdsForProfile(7));
        self::assertSame([7], $profileCalls, 'profile ids resolved once per request');
        self::assertSame([], $loader->serviceIdsForProfile(0), 'invalid profile never queries');
    }

    /**
     * Without a request context the memo must be bypassed instead of leaking
     * into (or creating) a context: correctness over speed.
     */
    public function testMemoIsBypassedWithoutRequestContext(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        self::assertFalse(Context::hasCurrent());

        $serviceCalls = [];
        $regionCalls = [];
        $loader = $this->loader(
            [['service_id' => 11, 'is_active' => 1]],
            [['service_id' => 11, 'country_code' => 'CN']],
            $serviceCalls,
            $regionCalls,
        );

        $loader->loadActiveServices([11]);
        $loader->loadActiveServices([11]);
        self::assertCount(2, $serviceCalls, 'no request scope means no cross-request cache');
        self::assertFalse(Context::hasCurrent(), 'the loader must not create a request context');
    }

    /**
     * Equivalence: the batched answer must be exactly what the per-service
     * queries returned (same rows, same filtering, same order).
     */
    public function testBatchResultsMatchPerServiceQueries(): void
    {
        $serviceRows = [
            ['service_id' => 11, 'is_active' => 1],
            ['service_id' => 12, 'is_active' => 1],
        ];
        $regionRows = [
            ['service_id' => 11, 'country_code' => 'CN', 'service_region_id' => 1],
            ['service_id' => 11, 'country_code' => 'US', 'service_region_id' => 2],
            ['service_id' => 12, 'country_code' => 'JP', 'service_region_id' => 3],
        ];
        $serviceCalls = [];
        $regionCalls = [];
        $loader = $this->loader($serviceRows, $regionRows, $serviceCalls, $regionCalls);
        $ids = [11, 12, 13];

        $batchServices = $loader->loadActiveServices($ids);
        $batchCodes = $loader->loadActiveRegionCountryCodes($ids);

        // Naive reference implementation: what the old per-service loop did.
        $expectedServices = [];
        $expectedCodes = [];
        foreach ($ids as $id) {
            foreach ($serviceRows as $row) {
                if ((int)$row['service_id'] === $id && (int)$row['is_active'] === 1) {
                    $expectedServices[$id] = $id;
                }
            }
            $codes = [];
            foreach ($regionRows as $row) {
                if ((int)$row['service_id'] !== $id) {
                    continue;
                }
                $code = \strtoupper(\trim((string)$row['country_code']));
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
            $expectedCodes[$id] = \array_keys($codes);
        }

        self::assertSame(\array_keys($expectedServices), \array_keys($batchServices));
        self::assertSame($expectedCodes, $batchCodes);
    }
}
