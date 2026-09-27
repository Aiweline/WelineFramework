<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\SeoPlatformCode;

final class SeoPlatformCodeContractTest extends TestCase
{
    public function testGoogleAliasesCanonicalize(): void
    {
        self::assertSame('google', SeoPlatformCode::canonicalize('google'));
        self::assertSame('google', SeoPlatformCode::canonicalize('google_search_console'));
        self::assertSame('google', SeoPlatformCode::canonicalize('google_indexing_api'));
        self::assertSame('google', SeoPlatformCode::canonicalize('GSC'));
        self::assertTrue(SeoPlatformCode::isGoogle('google_search_console'));
        self::assertFalse(SeoPlatformCode::isGoogle('bing'));
        self::assertSame('bing', SeoPlatformCode::canonicalize('bing'));
    }
}
