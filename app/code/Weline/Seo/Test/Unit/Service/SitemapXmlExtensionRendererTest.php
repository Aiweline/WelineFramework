<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Model\SitemapUrl;
use Weline\Seo\Service\Sitemap\SitemapXmlExtensionRenderer;

class SitemapXmlExtensionRendererTest extends TestCase
{
    public function testRendersImageVideoNewsAndAlternateSitemapExtensions(): void
    {
        $renderer = new SitemapXmlExtensionRenderer();
        $url = [
            SitemapUrl::schema_fields_URL => 'https://shop.test/news/launch',
            SitemapUrl::schema_fields_METADATA => json_encode([
                'images' => [
                    ['loc' => 'https://shop.test/media/news.jpg', 'title' => 'Launch News'],
                ],
                'videos' => [
                    [
                        'thumbnail_loc' => 'https://shop.test/media/video.jpg',
                        'title' => 'Launch Video',
                        'description' => 'Launch details.',
                        'publication_date' => '2026-05-25 10:00:00',
                    ],
                ],
                'news' => [
                    'publication' => ['name' => 'Shop News', 'language' => 'zh_Hans_CN'],
                    'publication_date' => '2026-05-25 10:00:00',
                    'title' => 'Launch News',
                ],
                'alternates' => [
                    'zh_Hans_CN' => 'https://shop.test/news/launch',
                    'en_US' => 'https://shop.test/en/news/launch',
                ],
            ], JSON_UNESCAPED_SLASHES),
        ];

        $openTag = $renderer->urlsetOpenTag([$url]);
        $extensions = $renderer->renderUrlExtensions($url);

        self::assertStringContainsString('xmlns:image=', $openTag);
        self::assertStringContainsString('xmlns:video=', $openTag);
        self::assertStringContainsString('xmlns:news=', $openTag);
        self::assertStringContainsString('xmlns:xhtml=', $openTag);
        self::assertStringContainsString('<image:loc>https://shop.test/media/news.jpg</image:loc>', $extensions);
        self::assertStringContainsString('<video:thumbnail_loc>https://shop.test/media/video.jpg</video:thumbnail_loc>', $extensions);
        self::assertStringContainsString('<news:language>zh-cn</news:language>', $extensions);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="en-US" href="https://shop.test/en/news/launch" />', $extensions);
    }

    public function testRelativeImageLocIsAbsolutizedAgainstPageUrl(): void
    {
        $renderer = new SitemapXmlExtensionRenderer();
        $url = [
            SitemapUrl::schema_fields_URL => 'https://www.changanhanfu.com/blog/hanfu-guide',
            SitemapUrl::schema_fields_METADATA => [
                'images' => [
                    [
                        'loc' => '/pub/media/blog/hanfu/r2/covers/core/aliexpress-hanfu-europe-sea-57bdc2ba575e.webp',
                        'title' => 'Hanfu Guide',
                    ],
                ],
            ],
        ];

        $extensions = $renderer->renderUrlExtensions($url);

        self::assertStringContainsString(
            '<image:loc>https://www.changanhanfu.com/pub/media/blog/hanfu/r2/covers/core/aliexpress-hanfu-europe-sea-57bdc2ba575e.webp</image:loc>',
            $extensions,
        );
        self::assertStringNotContainsString('<image:loc>/pub/media/', $extensions);
    }

    public function testRelativeImageLocWithoutPageOriginIsOmitted(): void
    {
        $renderer = new SitemapXmlExtensionRenderer();
        $extensions = $renderer->renderUrlExtensions([
            SitemapUrl::schema_fields_METADATA => [
                'images' => [['loc' => '/pub/media/blog/cover.webp']],
            ],
        ]);

        self::assertSame('', $extensions);
    }
}
