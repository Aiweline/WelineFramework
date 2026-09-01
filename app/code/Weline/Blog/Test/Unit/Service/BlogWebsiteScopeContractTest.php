<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogWebsiteScope;

final class BlogWebsiteScopeContractTest extends TestCase
{
    public function testGlobalWebsiteZeroMatchesAnyScopedWebsite(): void
    {
        self::assertTrue(BlogWebsiteScope::matchesWebsite(3, 0));
        self::assertSame([3, 0], BlogWebsiteScope::websiteIdsForQuery(3));
    }

    public function testScopedWebsiteDoesNotMatchForeignWebsite(): void
    {
        self::assertFalse(BlogWebsiteScope::matchesWebsite(3, 5));
        self::assertTrue(BlogWebsiteScope::matchesWebsite(3, 3));
    }
}
