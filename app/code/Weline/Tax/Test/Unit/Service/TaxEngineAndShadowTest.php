<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Api\TaxEngineInterface;
use Weline\Tax\Service\TaxConflictException;
use Weline\Tax\Service\TaxEngine;
use Weline\Tax\Service\TaxLkgStore;
use Weline\Tax\Service\TaxShadowComparator;

/**
 * TEST-P3B-01 + engine/LKG fail-closed contracts（TASK-P3B-001）.
 */
final class TaxEngineAndShadowTest extends TestCase
{
    public function testP3b01ShadowObservationWindowConservesAndBuildsLkg(): void
    {
        $cmp = TaxShadowComparator::forTesting();
        $requests = [];
        for ($i = 0; $i < 100; $i++) {
            $class = ($i % 2 === 0) ? 'standard' : 'reduced';
            $jurisdiction = ($i % 5 === 0) ? 'US|CA' : 'CN|';
            if ($jurisdiction === 'US|CA') {
                $class = 'standard';
            }
            $requests[] = [
                'website_id' => 0,
                'store_id' => 0,
                'currency' => 'CNY',
                'jurisdiction_key' => $jurisdiction,
                'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                'lines' => [
                    [
                        'line_id' => 'L' . $i . 'a',
                        'tax_class_code' => $class,
                        'taxable_amount_minor' => 1000 + ($i * 17),
                    ],
                    [
                        'line_id' => 'L' . $i . 'b',
                        'tax_class_code' => $class,
                        'taxable_amount_minor' => 333 + ($i % 11),
                    ],
                ],
            ];
        }

        $report = $cmp->observe($requests);
        self::assertTrue($report['ok'], json_encode($report['diffs']));
        self::assertSame(100, $report['quote_count']);
        self::assertSame(0, $report['unclassified_diff_count']);
        self::assertTrue($report['conserved']);
        self::assertLessThanOrEqual(1.0, $report['max_line_rounding_drift']);
        self::assertNotNull($report['lkg_id']);

        $lkg = $cmp->lkg();
        self::assertNotNull($lkg);
        $snapshot = $lkg->requireVerified(
            TaxEngine::SCHEMA_VERSION,
            $report['rule_set_hash'],
            $report['scope_key'],
        );
        self::assertSame(TaxEngine::SNAPSHOT_VERSION, $snapshot['snapshot_version']);
        self::assertArrayHasKey('classes', $snapshot);
        self::assertArrayHasKey('rules', $snapshot);
        self::assertArrayNotHasKey('tax_amount_minor', $snapshot);

        $replay = TaxEngine::fromSnapshot($snapshot)->calculate($requests[99]);
        $current = $cmp->primary()->calculate($requests[99]);
        self::assertSame($current['tax_amount_minor'], $replay['tax_amount_minor']);
        self::assertSame($current['rule_set_hash'], $replay['rule_set_hash']);
    }

    public function testEngineFailureNeverReturnsZeroTax(): void
    {
        $engine = TaxEngine::forTesting();
        $engine->setDown(true);
        try {
            $engine->calculate([
                'website_id' => 0,
                'store_id' => 0,
                'currency' => 'CNY',
                'jurisdiction_key' => 'CN|',
                'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                'lines' => [
                    ['line_id' => '1', 'tax_class_code' => 'standard', 'taxable_amount_minor' => 1000],
                ],
            ]);
            self::fail('must throw');
        } catch (TaxConflictException $e) {
            self::assertSame(TaxEngineInterface::ERROR_ENGINE_DOWN, $e->errorCode());
        }

        $engine->setDown(false);
        try {
            $engine->calculate([
                'website_id' => 0,
                'store_id' => 0,
                'currency' => 'CNY',
                'jurisdiction_key' => 'JP|',
                'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                'lines' => [
                    ['line_id' => '1', 'tax_class_code' => 'standard', 'taxable_amount_minor' => 1000],
                ],
            ]);
            self::fail('missing rule must throw');
        } catch (TaxConflictException $e) {
            self::assertSame(TaxEngineInterface::ERROR_NO_RULE, $e->errorCode());
        }
    }

    public function testLkgRejectsCrossVersion(): void
    {
        $lkg = TaxLkgStore::forTesting();
        $engine = TaxEngine::forTesting();
        $hash = $engine->ruleSetHash();
        $lkg->save(TaxEngine::SCHEMA_VERSION, $hash, ['tax_amount_minor' => 130], verified: true);

        self::assertNull($lkg->readVerified('tax-schema-v2', $hash));
        $this->expectException(TaxConflictException::class);
        $lkg->requireVerified('tax-schema-v2', $hash);
    }

    public function testHalfUpLineRounding(): void
    {
        $engine = TaxEngine::forTesting();
        // 1 * 7.25% = 0.0725 → half-up 0? 1*725=725; 725/10000=0 rem 725 → 0
        // 7 * 7.25% = 0.5075 → 1
        $out = $engine->calculate([
            'website_id' => 0,
            'store_id' => 0,
            'currency' => 'USD',
            'jurisdiction_key' => 'US|CA',
            'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
            'lines' => [
                ['line_id' => 'a', 'tax_class_code' => 'standard', 'taxable_amount_minor' => 7],
            ],
        ]);
        self::assertSame(1, $out['tax_amount_minor']);
        self::assertSame(TaxEngine::SOURCE_ENGINE, $out['source']);
    }

    public function testIndependentShadowMutationIsClassifiedAndDoesNotPublishLkg(): void
    {
        $comparator = TaxShadowComparator::forTesting();
        $comparator->shadow()->seedRule(0, 'standard', 'CN|', 1700, 2);

        $report = $comparator->observe($this->requests(100));

        self::assertFalse($report['ok']);
        self::assertGreaterThan(0, $report['classified_diff_count']);
        self::assertSame(0, $report['unclassified_diff_count']);
        self::assertNull($report['lkg_id']);
        self::assertFalse($report['conserved']);
    }

    public function testDuplicateObservationAndDuplicateLineFailClosed(): void
    {
        $request = $this->requests(1)[0];
        $report = TaxShadowComparator::forTesting()->observe(array_fill(0, 100, $request));
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['unique_quote_count']);
        self::assertNull($report['lkg_id']);
        self::assertNotEmpty(array_filter(
            $report['diffs'],
            static fn (array $diff): bool => $diff['code'] === 'duplicate_request',
        ));

        $request['lines'][] = $request['lines'][0];
        try {
            TaxEngine::forTesting()->calculate($request);
            self::fail('duplicate line IDs must fail closed');
        } catch (TaxConflictException $exception) {
            self::assertSame(TaxEngineInterface::ERROR_DUPLICATE_LINE, $exception->errorCode());
        }
    }

    public function testIntegerOverflowFailsClosed(): void
    {
        $request = $this->requests(1)[0];
        $request['lines'][0]['taxable_amount_minor'] = PHP_INT_MAX;

        try {
            TaxEngine::forTesting()->calculate($request);
            self::fail('multiplication overflow must fail closed');
        } catch (TaxConflictException $exception) {
            self::assertSame(TaxEngineInterface::ERROR_OVERFLOW, $exception->errorCode());
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function requests(int $count): array
    {
        $requests = [];
        for ($index = 0; $index < $count; $index++) {
            $class = $index % 2 === 0 ? 'standard' : 'reduced';
            $jurisdiction = $index % 5 === 0 ? 'US|CA' : 'CN|';
            if ($jurisdiction === 'US|CA') {
                $class = 'standard';
            }
            $requests[] = [
                'website_id' => 0,
                'store_id' => 0,
                'currency' => $jurisdiction === 'US|CA' ? 'USD' : 'CNY',
                'jurisdiction_key' => $jurisdiction,
                'rule_schema_version' => TaxEngine::SCHEMA_VERSION,
                'lines' => [
                    [
                        'line_id' => 'unit-' . $index,
                        'tax_class_code' => $class,
                        'taxable_amount_minor' => 1000 + ($index * 17),
                    ],
                ],
            ];
        }

        return $requests;
    }
}
