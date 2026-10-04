<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Observer\DetectWebsite;

final class DetectWebsiteReservedMountTest extends TestCase
{
    public function testReservedHostRetainsExplicitMountsAndOrdinaryDomainAliases(): void
    {
        $observer = (new \ReflectionClass(DetectWebsite::class))->newInstanceWithoutConstructor();
        $add = new \ReflectionMethod($observer, 'addExpandedSiteUrls');
        $expanded = [];
        $seen = [];
        $site = ['website_id' => 158, 'code' => 'fixture-mount'];
        foreach ([
            'https://p05113ef3.test.weline.com',
            'https://p05113ef3.test.weline.com/daocharms',
            'https://other.example.test',
        ] as $url) {
            $add->invokeArgs($observer, [&$expanded, &$seen, $site, $url]);
        }

        $urls = array_column($expanded, 'url');
        self::assertNotContains('https://p05113ef3.test.weline.com', $urls);
        self::assertNotContains('https://www.p05113ef3.test.weline.com', $urls);
        self::assertContains('https://p05113ef3.test.weline.com/daocharms', $urls);
        self::assertContains('https://www.p05113ef3.test.weline.com/daocharms', $urls);
        self::assertContains('https://other.example.test', $urls);
        self::assertContains('https://www.other.example.test', $urls);
        self::assertCount(4, $urls);
        self::assertSame([158], array_values(array_unique(array_column($expanded, 'website_id'))));
        $add->invokeArgs($observer, [&$expanded, &$seen, $site, 'https://p05113ef3.test.weline.com/daocharms']);
        self::assertCount(4, $expanded);
    }
}
