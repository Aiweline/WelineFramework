<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Model\Website;
use Weline\Websites\Observer\DetectWebsite;
use Weline\Websites\Service\Exception\ScopeResolutionException;
use Weline\Websites\Service\ProjectHostSiteMount;

final class DetectWebsiteProjectHostSiteCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DetectWebsite::clearProcessCache();
        RequestContext::resetWelineVars();
        RequestContext::init();
    }

    protected function tearDown(): void
    {
        DetectWebsite::clearProcessCache();
        RequestContext::resetWelineVars();
        RequestContext::init();
        parent::tearDown();
    }

    public function testVirtualMountIsPublishedOnProjectHost(): void
    {
        $observer = (new \ReflectionClass(DetectWebsite::class))->newInstanceWithoutConstructor();
        $add = new \ReflectionMethod($observer, 'addExpandedSiteUrls');
        $expanded = [];
        $seen = [];
        $site = [
            'website_id' => 158,
            Website::schema_fields_CODE => 'daocharms',
            'code' => 'daocharms',
        ];
        $mount = ProjectHostSiteMount::mountPathForCode('daocharms');
        $add->invokeArgs($observer, [
            &$expanded,
            &$seen,
            $site,
            'https://p05113ef3.test.weline.com' . $mount,
        ]);
        $urls = \array_column($expanded, 'url');
        self::assertContains('https://p05113ef3.test.weline.com/~site/daocharms', $urls);
        self::assertContains('https://www.p05113ef3.test.weline.com/~site/daocharms', $urls);
        self::assertNotContains('https://p05113ef3.test.weline.com', $urls);
    }

    public function testKnownCodeResolvesWithMountUrl(): void
    {
        RequestContext::set('websites.detect.website_rows', [[
            Website::schema_fields_ID => 158,
            Website::schema_fields_CODE => 'daocharms',
            Website::schema_fields_URL => 'https://daocharms.com',
            'website_id' => 158,
            'code' => 'daocharms',
            'url' => 'https://daocharms.com',
        ]]);

        $observer = (new \ReflectionClass(DetectWebsite::class))->newInstanceWithoutConstructor();
        $find = new \ReflectionMethod($observer, 'findSiteByProjectHostSiteCode');
        $websiteModel = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->getMock();

        $matched = $find->invoke(
            $observer,
            'https://p05113ef3.test.weline.com/~site/daocharms/en_US/',
            'p05113ef3.test.weline.com',
            $websiteModel,
        );

        self::assertIsArray($matched);
        self::assertSame(158, (int)($matched[Website::schema_fields_ID] ?? $matched['website_id'] ?? -1));
        self::assertSame(
            'https://p05113ef3.test.weline.com/~site/daocharms',
            (string)($matched[Website::schema_fields_URL] ?? $matched['url'] ?? '')
        );
    }

    public function testUnknownCodeFailClosed(): void
    {
        RequestContext::set('websites.detect.website_rows', [[
            Website::schema_fields_ID => 0,
            Website::schema_fields_CODE => 'default',
            'website_id' => 0,
            'code' => 'default',
        ]]);

        $observer = (new \ReflectionClass(DetectWebsite::class))->newInstanceWithoutConstructor();
        $find = new \ReflectionMethod($observer, 'findSiteByProjectHostSiteCode');
        $websiteModel = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->expectException(ScopeResolutionException::class);
        try {
            $find->invoke(
                $observer,
                'https://p05113ef3.test.weline.com/~site/missing/',
                'p05113ef3.test.weline.com',
                $websiteModel,
            );
        } catch (ScopeResolutionException $e) {
            self::assertSame(404, $e->httpStatus);
            throw $e;
        }
    }

    public function testInvalidSyntheticPrefixFailClosed(): void
    {
        $observer = (new \ReflectionClass(DetectWebsite::class))->newInstanceWithoutConstructor();
        $find = new \ReflectionMethod($observer, 'findSiteByProjectHostSiteCode');
        $websiteModel = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->expectException(ScopeResolutionException::class);
        $find->invoke(
            $observer,
            'https://p05113ef3.test.weline.com/~site/',
            'p05113ef3.test.weline.com',
            $websiteModel,
        );
    }

    public function testOrdinaryPathNotSynthetic(): void
    {
        $observer = (new \ReflectionClass(DetectWebsite::class))->newInstanceWithoutConstructor();
        $find = new \ReflectionMethod($observer, 'findSiteByProjectHostSiteCode');
        $websiteModel = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->getMock();

        self::assertNull($find->invoke(
            $observer,
            'https://p05113ef3.test.weline.com/products',
            'p05113ef3.test.weline.com',
            $websiteModel,
        ));
    }
}
