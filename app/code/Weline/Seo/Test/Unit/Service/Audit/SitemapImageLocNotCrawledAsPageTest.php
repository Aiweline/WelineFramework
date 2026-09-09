<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Seo\Service\Audit\SitemapCrawlerAuditService;

final class SitemapImageLocNotCrawledAsPageTest extends TestCase
{
    public function testUrlsetKeepsPageLocAndIgnoresNestedImageLoc(): void
    {
        $xml = \simplexml_load_string(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <url>
    <loc>https://shop.test/blog/hanfu-europe</loc>
    <image:image>
      <image:loc>https://shop.test/pub/media/blog/hanfu/cover.webp</image:loc>
    </image:image>
  </url>
  <url>
    <loc>https://shop.test/product/hanfu-a</loc>
  </url>
</urlset>
XML);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $service = new SitemapCrawlerAuditService();
        $method = new ReflectionMethod($service, 'xmlUrlsetEntries');
        $method->setAccessible(true);
        /** @var list<array{loc:string,images:list<string>}> $entries */
        $entries = $method->invoke($service, $xml);

        self::assertCount(2, $entries);
        self::assertSame('https://shop.test/blog/hanfu-europe', $entries[0]['loc']);
        self::assertSame(['https://shop.test/pub/media/blog/hanfu/cover.webp'], $entries[0]['images']);
        self::assertSame('https://shop.test/product/hanfu-a', $entries[1]['loc']);
        self::assertSame([], $entries[1]['images']);
    }

    public function testMediaPathsAreNotAuditableHtmlPages(): void
    {
        $service = new SitemapCrawlerAuditService();
        $method = new ReflectionMethod($service, 'isNonHtmlAssetUrl');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, 'https://shop.test/pub/media/blog/hanfu/cover.webp'));
        self::assertTrue($method->invoke($service, 'https://shop.test/assets/app.js'));
        self::assertFalse($method->invoke($service, 'https://shop.test/blog/hanfu-europe'));
        self::assertFalse($method->invoke($service, 'https://shop.test/product/hanfu-a'));
    }
}
