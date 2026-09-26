<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Sitemap\BlogSitemapContentSourceInterface;
use Weline\Blog\Service\BlogSitemapUrlBuilder;
use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;
use Weline\Websites\Model\WebsiteLanguage;

final class BlogSitemapUrlBuilderHreflangTest extends TestCase
{
    public function testBuildForWebsiteEmitsPerLocaleRowsWithAlternates(): void
    {
        $articleZh = new BlogArticle(
            contentKind: BlogArticle::KIND_POST,
            websiteId: 0,
            locale: 'zh_Hans_CN',
            slug: 'hanfu-guide',
            identifier: 'blog/hanfu-guide',
            title: '汉服指南',
            excerpt: '',
            publishedAt: '2026-01-01',
            updatedAt: '2026-01-02',
            author: null,
            coverImage: null,
            categories: [],
            canonicalUrl: 'https://shop.test/blog/hanfu-guide',
            publicUrl: '/blog/hanfu-guide',
            sourceRef: ['kind' => BlogArticle::KIND_POST, 'post_id' => 10, 'storage_slug' => 'hanfu-guide'],
        );
        $articleEn = new BlogArticle(
            contentKind: BlogArticle::KIND_POST,
            websiteId: 0,
            locale: 'en_US',
            slug: 'hanfu-guide',
            identifier: 'blog/hanfu-guide',
            title: 'Hanfu Guide',
            excerpt: '',
            publishedAt: '2026-01-01',
            updatedAt: '2026-01-03',
            author: null,
            coverImage: null,
            categories: [],
            canonicalUrl: 'https://shop.test/en_US/blog/hanfu-guide',
            publicUrl: '/blog/hanfu-guide',
            sourceRef: ['kind' => BlogArticle::KIND_POST, 'post_id' => 11, 'storage_slug' => 'hanfu-guide-en'],
        );

        $resolver = $this->createMock(BlogSitemapContentSourceInterface::class);
        $resolver->method('listPublishedArticles')->willReturnCallback(
            static function (int $websiteId, string $locale) use ($articleZh, $articleEn): array {
                return match ($locale) {
                    'zh_Hans_CN' => [$articleZh],
                    'en_US' => [$articleEn],
                    default => [$articleZh],
                };
            }
        );
        $resolver->method('publicSlugForLocale')->willReturnCallback(
            static fn (string $slug, string $locale): string => str_ends_with($slug, '-en') ? substr($slug, 0, -3) : $slug
        );

        $urlBuilder = $this->createMock(LocalizedUrlBuilderInterface::class);
        $urlBuilder->method('build')->willReturnCallback(
            static function (string $base, string $path, string $locale, string $default) {
                $path = '/' . trim($path, '/');
                if ($locale === $default || $locale === '') {
                    return rtrim($base, '/') . ($path === '/' ? '' : $path);
                }
                return rtrim($base, '/') . '/' . $locale . ($path === '/' ? '' : $path);
            }
        );

        $languages = $this->createMock(WebsiteLanguage::class);
        $languages->method('getWebsiteLanguageCodes')->willReturn(['zh_Hans_CN', 'en_US', 'fr_FR']);

        $builder = new BlogSitemapUrlBuilder(
            $resolver,
            $urlBuilder,
            $languages,
        );

        $rows = $builder->buildForWebsite(0, 'https://shop.test');
        $hubs = array_values(array_filter($rows, static fn (array $r): bool => ($r['url_key'] ?? '') === 'blog-list'));
        $articles = array_values(array_filter($rows, static fn (array $r): bool => str_starts_with((string)($r['url_key'] ?? ''), 'blog-article-')));

        self::assertCount(3, $hubs);
        self::assertSame('zh_Hans_CN', $hubs[0]['locale']);
        self::assertSame('https://shop.test/blog', $hubs[0]['loc']);
        self::assertSame('https://shop.test/en_US/blog', $hubs[1]['loc']);
        self::assertArrayHasKey('x-default', $hubs[0]['metadata']['alternates']);
        self::assertSame('https://shop.test/blog', $hubs[0]['metadata']['alternates']['x-default']);
        self::assertSame('https://shop.test/en_US/blog', $hubs[0]['metadata']['alternates']['en_US']);

        self::assertGreaterThanOrEqual(3, count($articles));
        $byLocale = [];
        foreach ($articles as $row) {
            self::assertSame('blog-article-hanfu-guide', $row['url_key']);
            $byLocale[$row['locale']] = $row;
        }
        self::assertArrayHasKey('zh_Hans_CN', $byLocale);
        self::assertArrayHasKey('en_US', $byLocale);
        self::assertArrayHasKey('fr_FR', $byLocale);
        self::assertSame('https://shop.test/blog/hanfu-guide', $byLocale['zh_Hans_CN']['loc']);
        self::assertSame('https://shop.test/en_US/blog/hanfu-guide', $byLocale['en_US']['loc']);
        self::assertSame('https://shop.test/fr_FR/blog/hanfu-guide', $byLocale['fr_FR']['loc']);
        self::assertSame(
            'https://shop.test/blog/hanfu-guide',
            $byLocale['en_US']['metadata']['alternates']['x-default'],
        );
        self::assertSame(
            'https://shop.test/fr_FR/blog/hanfu-guide',
            $byLocale['zh_Hans_CN']['metadata']['alternates']['fr_FR'],
        );
    }
}
