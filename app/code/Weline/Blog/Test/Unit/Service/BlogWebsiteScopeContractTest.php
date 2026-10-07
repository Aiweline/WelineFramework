<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogWebsiteScope;

final class BlogWebsiteScopeContractTest extends TestCase
{
    public function testDefaultSiteZeroDoesNotLeakToOtherWebsites(): void
    {
        self::assertFalse(BlogWebsiteScope::matchesWebsite(3, 0));
        self::assertSame([3], BlogWebsiteScope::websiteIdsForQuery(3));
    }

    public function testScopedWebsiteDoesNotMatchForeignWebsite(): void
    {
        self::assertFalse(BlogWebsiteScope::matchesWebsite(3, 5));
        self::assertTrue(BlogWebsiteScope::matchesWebsite(3, 3));
    }

    public function testDefaultSiteQueriesOnlyDefaultSiteContent(): void
    {
        self::assertSame([0], BlogWebsiteScope::websiteIdsForQuery(0));
        self::assertTrue(BlogWebsiteScope::matchesWebsite(0, 0));
        self::assertFalse(BlogWebsiteScope::matchesWebsite(0, 544));
    }
}
