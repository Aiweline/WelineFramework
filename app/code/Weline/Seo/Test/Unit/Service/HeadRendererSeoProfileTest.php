<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadRenderer;
use Weline\Seo\Service\Head\PageSeoContextResolver;

class HeadRendererSeoProfileTest extends TestCase
{
    public function testRendersNewsArticleProfileGraph(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'news_article',
            'site_name' => 'News Shop',
            'title' => 'Launch News',
            'description' => 'A new launch.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/blog/launch-news',
            'url' => 'https://shop.test/blog/launch-news',
            'image' => 'https://shop.test/media/news.jpg',
            'locale' => 'zh_Hans_CN',
            'organization' => ['name' => 'News Shop', 'url' => 'https://shop.test/', 'logo' => 'https://shop.test/logo.png'],
            'article' => [
                'headline' => 'Launch News',
                'description' => 'A new launch.',
                'datePublished' => '2026-05-25 10:00:00',
                'dateModified' => '2026-05-26 10:00:00',
                'author_name' => 'Editor',
                'articleSection' => 'Company News',
                'keywords' => ['launch', 'company'],
                'is_news' => true,
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('<meta name="robots" content="index,follow">', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('"@type": "NewsArticle"', $html);
        self::assertStringContainsString('"mainEntityOfPage": {', $html);
        self::assertStringContainsString('"articleSection": "Company News"', $html);
        self::assertStringContainsString('"inLanguage": "zh-Hans-CN"', $html);
    }

    public function testRendersCollectionPageItemListProfileGraph(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'page_type' => 'category',
            'site_name' => 'Shop',
            'title' => 'Dresses',
            'description' => 'Dress collection.',
            'robots' => 'index,follow',
            'canonical_url' => 'https://shop.test/category/dresses',
            'url' => 'https://shop.test/category/dresses',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
            'item_list' => [
                ['name' => 'Summer Dress', 'url' => 'https://shop.test/product/summer-dress', 'image' => 'https://shop.test/media/dress.jpg'],
            ],
        ]);

        $html = (new HeadRenderer($resolver))->render(new SeoProfileHeadTemplateStub());

        self::assertStringContainsString('"@type": "CollectionPage"', $html);
        self::assertStringContainsString('"@type": "ItemList"', $html);
        self::assertStringContainsString('"name": "Summer Dress"', $html);
        self::assertStringContainsString('"mainEntity": {', $html);
    }
}

final class SeoProfileHeadTemplateStub
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}
