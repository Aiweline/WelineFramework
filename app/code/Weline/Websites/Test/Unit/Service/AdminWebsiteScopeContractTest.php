<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\AdminWebsiteScope;

final class AdminWebsiteScopeContractTest extends TestCase
{
    public function testParseNonNegativeIdTreatsZeroAsDefaultSiteNotAll(): void
    {
        self::assertSame(0, AdminWebsiteScope::parseNonNegativeId(0));
        self::assertSame(0, AdminWebsiteScope::parseNonNegativeId('0'));
        self::assertNull(AdminWebsiteScope::parseNonNegativeId(''));
        self::assertNull(AdminWebsiteScope::parseNonNegativeId('all'));
        self::assertNull(AdminWebsiteScope::parseNonNegativeId('ALL'));
        self::assertNull(AdminWebsiteScope::parseNonNegativeId(null));
        self::assertNull(AdminWebsiteScope::parseNonNegativeId('-1'));
        self::assertSame(23, AdminWebsiteScope::parseNonNegativeId('23'));
    }

    public function testBuildSelectViewKeepsZeroValue(): void
    {
        $view = AdminWebsiteScope::buildSelectView(0);
        self::assertSame('0', $view['value']);
        self::assertArrayHasKey('options_json', $view);
        self::assertArrayHasKey('display', $view);

        $all = AdminWebsiteScope::buildSelectView(null);
        self::assertSame('', $all['value']);
    }

    public function testFilterAllConstantIsNull(): void
    {
        self::assertNull(AdminWebsiteScope::FILTER_ALL);
    }
}
