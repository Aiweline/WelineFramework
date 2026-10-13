<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Header nav logical locale segment must delegate to WidgetI18n path-first authority.
 */
final class StorefrontHeaderNavLocaleAuthorityContractTest extends TestCase
{
    public function testStorefrontLocaleSegmentDelegatesToWidgetI18n(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/StorefrontHeaderNavFragmentCache.php',
        );

        self::assertStringContainsString('WidgetI18n::storefrontLocale()', $src);
        self::assertStringContainsString('function storefrontLocaleSegment()', $src);
        self::assertStringContainsString('requestOriginSegment()', $src);
        self::assertStringNotContainsString('State::getLangLocal()', $src);
    }
}
