<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

final class ThemeDataMetaListHotCacheContractTest extends TestCase
{
    public function testGetMetaListUsesStorefrontScopeHotCache(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/ThemeData.php');

        self::assertStringContainsString("resource: 'theme.meta_list'", $src);
        self::assertStringContainsString("pool: 'theme'", $src);
        self::assertStringContainsString('global/storefront/theme', $src);
        self::assertStringContainsString('rememberForRequest', $src);
        self::assertStringContainsString('rememberPolicy', $src);
        self::assertMatchesRegularExpression(
            '/function getMetaList[\s\S]{0,1200}StorefrontScopeHotCache/',
            $src,
        );
    }
}
