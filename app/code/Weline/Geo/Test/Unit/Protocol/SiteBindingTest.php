<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Model\FeedItem;
use Weline\Geo\Service\Protocol\SiteBindingResolver;
use Weline\Geo\Service\Protocol\UrlDeduper;
use Weline\Seo\Service\SeoWebsiteDirectory;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * E9 门禁：GEO 协议文本必须绑定到被生成的站点，且条目按规范化 URL 去重。
 */
final class SiteBindingTest extends TestCase
{
    public function testPublicSiteNameIsBoundToTheGeneratedWebsite(): void
    {
        $directory = new class extends SeoWebsiteDirectory {
            public function getWebsiteById(int $websiteId, bool $forceReload = false): ?array
            {
                $rows = [
                    3 => ['website_id' => 3, 'name' => 'PlayingInChina', 'url' => 'https://playinginchina.com'],
                    7 => ['website_id' => 7, 'name' => 'Amayum', 'url' => 'https://amayum.com'],
                ];

                return $rows[$websiteId] ?? null;
            }

            public function matchWebsiteByUrl(string $url): ?array
            {
                return null;
            }
        };

        $resolver = new SiteBindingResolver($directory);

        self::assertSame(
            'PlayingInChina',
            $resolver->publicSiteName((object)['id' => 3, 'url' => 'https://playinginchina.com', 'name' => ''])
        );
        self::assertSame(
            'Amayum',
            $resolver->publicSiteName((object)['id' => 7, 'url' => 'https://amayum.com', 'name' => ''])
        );
    }

    public function testPlaceholderRowFallsBackToHostMatchedWebsite(): void
    {
        $directory = new class extends SeoWebsiteDirectory {
            public function getWebsiteById(int $websiteId, bool $forceReload = false): ?array
            {
                return ['website_id' => 5, 'name' => 'Default Website', 'url' => 'https://playinginchina.com'];
            }

            public function matchWebsiteByUrl(string $url): ?array
            {
                return ['website_id' => 5, 'name' => 'PlayingInChina', 'url' => 'https://playinginchina.com'];
            }
        };

        $resolver = new SiteBindingResolver($directory);

        self::assertSame(
            'PlayingInChina',
            $resolver->publicSiteName((object)['id' => 5, 'url' => 'https://playinginchina.com', 'name' => 'Default Website'])
        );
    }

    public function testPlaceholderWithoutHostMatchFallsBackToHostNotOtherSiteBrand(): void
    {
        $directory = new class extends SeoWebsiteDirectory {
            public function getWebsiteById(int $websiteId, bool $forceReload = false): ?array
            {
                return ['website_id' => 9, 'name' => 'Default Website', 'url' => 'https://playinginchina.com'];
            }

            public function matchWebsiteByUrl(string $url): ?array
            {
                return null;
            }
        };

        $resolver = new SiteBindingResolver($directory);

        self::assertSame(
            'playinginchina.com',
            $resolver->publicSiteName((object)['id' => 9, 'url' => 'https://playinginchina.com', 'name' => 'Amayum'])
        );
    }

    public function testUrlDeduperCollapsesSchemeAndWwwVariants(): void
    {
        $items = [
            [FeedItem::schema_fields_URL => 'http://www.playinginchina.com/p/hanfu/'],
            [FeedItem::schema_fields_URL => 'https://playinginchina.com/p/hanfu'],
            [FeedItem::schema_fields_URL => 'https://playinginchina.com/p/hanfu?utm_source=feed'],
            [FeedItem::schema_fields_URL => 'https://playinginchina.com/p/other'],
        ];

        $deduped = (new UrlDeduper())->dedupe($items);

        self::assertCount(3, $deduped);
        self::assertSame('http://www.playinginchina.com/p/hanfu/', (string)$deduped[0][FeedItem::schema_fields_URL]);
        self::assertSame('https://playinginchina.com/p/hanfu?utm_source=feed', (string)$deduped[1][FeedItem::schema_fields_URL]);
        self::assertSame('https://playinginchina.com/p/other', (string)$deduped[2][FeedItem::schema_fields_URL]);
    }

    public function testUrlDeduperResolvesRelativeUrlsAgainstBaseUrl(): void
    {
        $items = [
            [FeedItem::schema_fields_URL => '/p/hanfu'],
            [FeedItem::schema_fields_URL => 'https://playinginchina.com/p/hanfu'],
        ];

        self::assertCount(1, (new UrlDeduper())->dedupe($items, 'https://playinginchina.com'));
    }
}
