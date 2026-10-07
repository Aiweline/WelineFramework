<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\TaxDefaultSeedService;
use Weline\Tax\Service\TaxSeedRateCatalog;

final class TaxDefaultSeedServiceContractTest extends TestCase
{
    public function testSeedRevisionAndCoreCountries(): void
    {
        self::assertSame(0, TaxDefaultSeedService::WEBSITE_ID);
        self::assertSame(TaxSeedRateCatalog::SEED_REVISION, TaxDefaultSeedService::SEED_REVISION);
        self::assertCount(2, TaxDefaultSeedService::CLASSES);

        $rules = [];
        foreach (TaxDefaultSeedService::rules() as $rule) {
            $rules[$rule['class_code'] . '@' . $rule['jurisdiction_key']] = $rule['rate_bps'];
        }

        self::assertGreaterThanOrEqual(80, count($rules));
        self::assertSame(1300, $rules['standard@CN|']);
        self::assertSame(900, $rules['reduced@CN|']);
        self::assertSame(1900, $rules['standard@DE|']);
        self::assertSame(2000, $rules['standard@GB|']);
        self::assertSame(1000, $rules['standard@AU|']);
        self::assertSame(1000, $rules['standard@JP|']);
        self::assertSame(700, $rules['standard@US|']);
        self::assertSame(725, $rules['standard@US|CA']);
    }
}
