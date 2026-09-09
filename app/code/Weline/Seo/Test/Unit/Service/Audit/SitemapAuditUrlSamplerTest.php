<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Audit\SitemapAuditUrlSampler;

final class SitemapAuditUrlSamplerTest extends TestCase
{
    public function testKeepsSingletonsAndOnePerRepeatingStructure(): void
    {
        $sampler = new SitemapAuditUrlSampler();
        $sample = $sampler->sample([
            'https://shop.test/',
            'https://shop.test/products',
            'https://shop.test/product/100',
            'https://shop.test/product/hanfu-a',
            'https://shop.test/product/hanfu-b',
            'https://shop.test/blog',
            'https://shop.test/blog/post-a',
            'https://shop.test/blog/post-b',
            'https://shop.test/blog/category/tips',
            'https://shop.test/blog/category/news',
            'https://shop.test/category/women/mamian',
            'https://shop.test/category/men',
            'https://shop.test/help',
            'https://shop.test/help/shipping',
            'https://shop.test/help/returns',
            'https://shop.test/promotion',
            'https://shop.test/promotion/deals',
            'https://shop.test/promotion/sale',
            'https://shop.test/about',
            'https://shop.test/en_US/product/hanfu-c',
        ]);

        self::assertSame(20, $sample['discovered']);
        self::assertSame(12, $sample['sampled']);
        self::assertSame(8, $sample['collapsed']);
        self::assertContains('https://shop.test/', $sample['urls']);
        self::assertContains('https://shop.test/products', $sample['urls']);
        self::assertContains('https://shop.test/blog', $sample['urls']);
        self::assertContains('https://shop.test/help', $sample['urls']);
        self::assertContains('https://shop.test/promotion', $sample['urls']);
        self::assertContains('https://shop.test/about', $sample['urls']);
        self::assertContains('https://shop.test/product/100', $sample['urls']);
        self::assertNotContains('https://shop.test/product/hanfu-a', $sample['urls']);
        self::assertNotContains('https://shop.test/product/hanfu-b', $sample['urls']);
        self::assertNotContains('https://shop.test/en_US/product/hanfu-c', $sample['urls']);
        self::assertContains('https://shop.test/blog/post-a', $sample['urls']);
        self::assertNotContains('https://shop.test/blog/post-b', $sample['urls']);
        self::assertContains('https://shop.test/blog/category/tips', $sample['urls']);
        self::assertNotContains('https://shop.test/blog/category/news', $sample['urls']);
        self::assertContains('https://shop.test/help/shipping', $sample['urls']);
        self::assertNotContains('https://shop.test/help/returns', $sample['urls']);
        self::assertContains('https://shop.test/promotion/deals', $sample['urls']);
        self::assertNotContains('https://shop.test/promotion/sale', $sample['urls']);
        self::assertSame(3, $sample['structures']['product/*']['skipped']);
        self::assertSame(1, $sample['structures']['blog/*']['skipped']);
        self::assertSame(1, $sample['structures']['blog/category/*']['skipped']);
        self::assertSame(1, $sample['structures']['category/*']['skipped']);
        self::assertSame(1, $sample['structures']['help/*']['skipped']);
        self::assertSame(1, $sample['structures']['promotion/*']['skipped']);
    }

    public function testLocaleProductSharesProductPattern(): void
    {
        $sampler = new SitemapAuditUrlSampler();
        self::assertSame('product/*', $sampler->repeatingStructurePattern('https://shop.test/en_US/product/foo-bar'));
        self::assertSame('blog/*', $sampler->repeatingStructurePattern('https://shop.test/zh_Hans_CN/blog/my-post'));
        self::assertNull($sampler->repeatingStructurePattern('https://shop.test/products'));
        self::assertNull($sampler->repeatingStructurePattern('https://shop.test/blog'));
        self::assertNull($sampler->repeatingStructurePattern('https://shop.test/product'));
        self::assertNull($sampler->repeatingStructurePattern('https://shop.test/about'));
    }

    public function testGroupUrlsByParentStructure(): void
    {
        $sampler = new SitemapAuditUrlSampler();
        $groups = $sampler->groupUrls([
            'https://shop.test/product/a',
            'https://shop.test/product/b',
            'https://shop.test/blog/hello',
            'https://shop.test/about',
            'https://shop.test/products',
        ]);

        self::assertSame('product/*', $groups[0]['key']);
        self::assertSame('商品详情', $groups[0]['label']);
        self::assertSame('/product', $groups[0]['parentPath']);
        self::assertSame(2, $groups[0]['count']);
        self::assertSame('blog/*', $groups[1]['key']);
        self::assertSame(1, $groups[1]['count']);

        $keys = \array_column($groups, 'key');
        self::assertContains('singleton:/about', $keys);
        self::assertContains('singleton:/products', $keys);
    }
}
