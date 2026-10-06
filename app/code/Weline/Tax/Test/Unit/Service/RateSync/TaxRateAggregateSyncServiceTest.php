<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service\RateSync;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Api\TaxRateRemoteProviderInterface;
use Weline\Tax\Service\RateSync\GenericHttpTaxRateProvider;
use Weline\Tax\Service\RateSync\TaxRateAggregateSyncService;

final class TaxRateAggregateSyncServiceTest extends TestCase
{
    public function testFreeUnionTakesMaxRateOnConflict(): void
    {
        $low = $this->freeProvider('a', [
            ['jurisdiction_key' => 'DE|', 'class_code' => 'standard', 'rate_bps' => 1600, 'source' => 'a'],
        ]);
        $high = $this->freeProvider('b', [
            ['jurisdiction_key' => 'DE|', 'class_code' => 'standard', 'rate_bps' => 1900, 'source' => 'b'],
        ]);
        $upserted = [];
        $sync = TaxRateAggregateSyncService::forTesting(
            [$low, $high],
            static function (array $input) use (&$upserted): array {
                $upserted[] = $input;

                return ['action' => 'created', 'rule' => $input];
            },
            [
                TaxRateAggregateSyncService::KEY_FREE_PROVIDERS => 'a,b',
                TaxRateAggregateSyncService::KEY_PRO_ENABLED => false,
            ],
        );

        $result = $sync->sync(0, true);
        self::assertTrue($result['ok']);
        self::assertSame(1, $result['conflict_max_count']);
        self::assertCount(1, $upserted);
        self::assertSame(1900, $upserted[0]['rate_bps']);
        self::assertSame('DE|', $upserted[0]['jurisdiction_key']);
    }

    public function testProfessionalOverlayWinsEvenWhenLower(): void
    {
        $free = $this->freeProvider('static', [
            ['jurisdiction_key' => 'DE|', 'class_code' => 'standard', 'rate_bps' => 1900, 'source' => 'static'],
            ['jurisdiction_key' => 'FR|', 'class_code' => 'standard', 'rate_bps' => 2000, 'source' => 'static'],
        ]);
        $pro = $this->proProvider(GenericHttpTaxRateProvider::CODE, [
            ['jurisdiction_key' => 'DE|', 'class_code' => 'standard', 'rate_bps' => 1500, 'source' => GenericHttpTaxRateProvider::CODE],
        ]);
        $byKey = [];
        $sync = TaxRateAggregateSyncService::forTesting(
            [$free, $pro],
            static function (array $input) use (&$byKey): array {
                $byKey[$input['jurisdiction_key']] = $input['rate_bps'];

                return ['action' => 'updated', 'rule' => $input];
            },
            [
                TaxRateAggregateSyncService::KEY_FREE_PROVIDERS => 'static',
                TaxRateAggregateSyncService::KEY_PRO_ENABLED => true,
                TaxRateAggregateSyncService::KEY_PRO_PROVIDER => GenericHttpTaxRateProvider::CODE,
            ],
        );

        $result = $sync->sync(0, true);
        self::assertSame(1, $result['professional_overlay_count']);
        self::assertSame(1500, $byKey['DE|']);
        self::assertSame(2000, $byKey['FR|'], 'uncovered stays free union');
    }

    public function testFreeProviderFailureDoesNotBlockOthers(): void
    {
        $ok = $this->freeProvider('static', [
            ['jurisdiction_key' => 'CN|', 'class_code' => 'standard', 'rate_bps' => 1300, 'source' => 'static'],
        ]);
        $fail = new class implements TaxRateRemoteProviderInterface {
            public function code(): string
            {
                return 'vatcomply';
            }

            public function label(): string
            {
                return 'fail';
            }

            public function tier(): string
            {
                return self::TIER_FREE;
            }

            public function fetchCandidates(int $websiteId): array
            {
                throw new \RuntimeException('vatcomply_fetch_failed:transport_failed');
            }
        };
        $upserted = [];
        $sync = TaxRateAggregateSyncService::forTesting(
            [$ok, $fail],
            static function (array $input) use (&$upserted): array {
                $upserted[] = $input;

                return ['action' => 'created', 'rule' => $input];
            },
            [
                TaxRateAggregateSyncService::KEY_FREE_PROVIDERS => 'static,vatcomply',
                TaxRateAggregateSyncService::KEY_PRO_ENABLED => false,
            ],
        );

        $result = $sync->sync(0, true);
        self::assertTrue($result['ok']);
        self::assertCount(1, $result['failed_sources']);
        self::assertSame('vatcomply', $result['failed_sources'][0]['code']);
        self::assertCount(1, $upserted);
        self::assertSame('CN|', $upserted[0]['jurisdiction_key']);
    }

    /**
     * @param list<array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string}> $rows
     */
    private function freeProvider(string $code, array $rows): TaxRateRemoteProviderInterface
    {
        return new class ($code, $rows) implements TaxRateRemoteProviderInterface {
            /** @param list<array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string}> $rows */
            public function __construct(private readonly string $codeValue, private readonly array $rows)
            {
            }

            public function code(): string
            {
                return $this->codeValue;
            }

            public function label(): string
            {
                return $this->codeValue;
            }

            public function tier(): string
            {
                return self::TIER_FREE;
            }

            public function fetchCandidates(int $websiteId): array
            {
                return $this->rows;
            }
        };
    }

    /**
     * @param list<array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string}> $rows
     */
    private function proProvider(string $code, array $rows): TaxRateRemoteProviderInterface
    {
        return new class ($code, $rows) implements TaxRateRemoteProviderInterface {
            /** @param list<array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string}> $rows */
            public function __construct(private readonly string $codeValue, private readonly array $rows)
            {
            }

            public function code(): string
            {
                return $this->codeValue;
            }

            public function label(): string
            {
                return $this->codeValue;
            }

            public function tier(): string
            {
                return self::TIER_PROFESSIONAL;
            }

            public function fetchCandidates(int $websiteId): array
            {
                return $this->rows;
            }
        };
    }
}
