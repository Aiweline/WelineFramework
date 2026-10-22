<?php

declare(strict_types=1);

namespace Weline\Geo\Test\Unit\Service\Protocol;

use PHPUnit\Framework\TestCase;
use Weline\Geo\Model\FeedItem;
use Weline\Geo\Service\Protocol\GeoWebsiteFeedScope;
use Weline\Seo\Service\SeoWebsiteDirectory;

class GeoWebsiteFeedScopeTest extends TestCase
{
    public function testItemBelongsToWebsiteFiltersByMetadataAndHost(): void
    {
        $directory = $this->createMock(SeoWebsiteDirectory::class);
        $directory->method('getWebsiteById')->willReturn([
            'website_id' => 0,
            'url' => 'https://p05113ef3.test.weline.com',
        ]);
        $directory->method('listPublicOrigins')->willReturn([
            'https://p05113ef3.test.weline.com',
        ]);

        $scope = new GeoWebsiteFeedScope($directory);
        $hosts = $scope->allowedHosts(0, 'https://p05113ef3.test.weline.com');

        $own = [
            FeedItem::schema_fields_URL => 'https://p05113ef3.test.weline.com/product/a',
            FeedItem::schema_fields_METADATA => ['website_id' => 0],
        ];
        $foreignMeta = [
            FeedItem::schema_fields_URL => 'https://p05113ef3.test.weline.com/product/b',
            FeedItem::schema_fields_METADATA => ['website_id' => 7],
        ];
        $foreignHost = [
            FeedItem::schema_fields_URL => 'https://www.daocharms.com/product/c',
            FeedItem::schema_fields_METADATA => ['website_id' => 0],
        ];
        $relative = [
            FeedItem::schema_fields_URL => '/blog/hello',
            FeedItem::schema_fields_METADATA => ['website_id' => 0],
        ];

        self::assertTrue($scope->itemBelongsToWebsite($own, 0, $hosts));
        self::assertFalse($scope->itemBelongsToWebsite($foreignMeta, 0, $hosts));
        self::assertFalse($scope->itemBelongsToWebsite($foreignHost, 0, $hosts));
        self::assertTrue($scope->itemBelongsToWebsite($relative, 0, $hosts));
        self::assertSame(
            $scope->siteFeedDirectory(3) . '/site.json',
            $scope->siteJsonPath(3)
        );
    }
}
