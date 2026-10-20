<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteCatalog;

/**
 * Ch4：WebsiteCatalog::count() 预热后进程内二次获取 0 SQL。
 */
final class WebsiteCatalogCountCacheTest extends TestCase
{
    protected function setUp(): void
    {
        WebsiteCatalog::clearProcessCache();
    }

    protected function tearDown(): void
    {
        WebsiteCatalog::clearProcessCache();
    }

    public function testCountIsMemoizedInProcessBag(): void
    {
        $website = new WebsiteCatalogCountWebsiteFixture(3);
        $catalog = new WebsiteCatalog($website);

        self::assertSame(3, $catalog->count());
        self::assertSame(1, $website->countCalls);

        self::assertSame(3, $catalog->count());
        self::assertSame(1, $website->countCalls, 'second count() must be served from the process bag');

        WebsiteCatalog::clearProcessCache();
        self::assertSame(3, $catalog->count());
        self::assertSame(2, $website->countCalls, 'clearProcessCache must force a reload');
    }
}

final class WebsiteCatalogCountWebsiteFixture extends Website
{
    public int $countCalls = 0;

    public function __construct(private readonly int $total)
    {
    }

    public function count()
    {
        $this->countCalls++;

        return $this->total;
    }

    public function __call($method, $args)
    {
        return $this;
    }
}
