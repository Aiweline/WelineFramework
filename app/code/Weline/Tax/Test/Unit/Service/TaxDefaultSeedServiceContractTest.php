<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\TaxDefaultSeedService;

final class TaxDefaultSeedServiceContractTest extends TestCase
{
    public function testDefaultSeedTableMatchesEngineHarness(): void
    {
        self::assertSame(0, TaxDefaultSeedService::WEBSITE_ID);
        self::assertCount(2, TaxDefaultSeedService::CLASSES);
        self::assertCount(4, TaxDefaultSeedService::RULES);

        $rules = [];
        foreach (TaxDefaultSeedService::RULES as $rule) {
            $rules[$rule['class_code'] . '@' . $rule['jurisdiction_key']] = $rule['rate_bps'];
        }
        self::assertSame(1300, $rules['standard@CN|']);
        self::assertSame(900, $rules['reduced@CN|']);
        self::assertSame(700, $rules['standard@US|']);
        self::assertSame(725, $rules['standard@US|CA']);
    }
}
