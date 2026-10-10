<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Service\MarketingWebsiteScope;

final class MarketingWebsiteScopeContractTest extends TestCase
{
    public function testEntityAllIsNegativeOneAndZeroIsDefaultSite(): void
    {
        self::assertSame(-1, MarketingWebsiteScope::ENTITY_ALL);
        self::assertSame(-1, MarketingWebsiteScope::normalizeEntity(''));
        self::assertSame(-1, MarketingWebsiteScope::normalizeEntity(null));
        self::assertSame(0, MarketingWebsiteScope::normalizeEntity(0));
        self::assertSame(0, MarketingWebsiteScope::normalizeEntity('0'));
        self::assertSame([0, -1], MarketingWebsiteScope::listMatchIds(0));
        self::assertTrue(MarketingWebsiteScope::appliesToSite(-1, 0));
        self::assertTrue(MarketingWebsiteScope::appliesToSite(0, 0));
        self::assertFalse(MarketingWebsiteScope::appliesToSite(2, 0));
    }

    public function testRunnersUseGeZeroNotGtZero(): void
    {
        $files = [
            dirname(__DIR__, 3) . '/Service/WinbackUnpaidOrderRunner.php',
            dirname(__DIR__, 3) . '/Service/WinbackCheckoutAbandonRunner.php',
            dirname(__DIR__, 3) . '/Service/WinbackCartAbandonRunner.php',
        ];
        foreach ($files as $file) {
            $src = (string)file_get_contents($file);
            self::assertStringContainsString('if ($websiteId >= 0)', $src, $file);
            self::assertStringNotContainsString('if ($websiteId > 0)', $src, $file);
        }
    }
}
