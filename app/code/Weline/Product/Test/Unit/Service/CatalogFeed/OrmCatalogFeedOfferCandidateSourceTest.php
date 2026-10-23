<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\CatalogFeed;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Product\Service\CatalogFeed\OrmCatalogFeedOfferCandidateSource;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class OrmCatalogFeedOfferCandidateSourceTest extends TestCase
{
    public function testSiteRelativeAssetUrlIsPrefixedWithWebsiteOrigin(): void
    {
        $source = $this->source('/pub/media/catalog/main.jpg');

        self::assertSame(
            'https://shop.example.com/pub/media/catalog/main.jpg',
            $this->absoluteImage($source, 'asset://1a2b3c4d-0000-4000-8000-000000000001', 'https://shop.example.com'),
        );
    }

    public function testAbsoluteAssetUrlIsKept(): void
    {
        $source = $this->source('https://cdn.example.com/main.jpg');

        self::assertSame(
            'https://cdn.example.com/main.jpg',
            $this->absoluteImage($source, 'asset://1a2b3c4d-0000-4000-8000-000000000002', 'https://shop.example.com'),
        );
    }

    public function testRelativeAssetUrlWithoutWebsiteOriginIsRejected(): void
    {
        $source = $this->source('/pub/media/catalog/main.jpg');

        self::assertSame('', $this->absoluteImage($source, 'asset://1a2b3c4d-0000-4000-8000-000000000003', ''));
    }

    public function testLegacyRelativePathIsPrefixedWithWebsiteOrigin(): void
    {
        $source = $this->source('/unused.jpg');

        self::assertSame(
            'https://shop.example.com/pub/media/legacy.jpg',
            $this->absoluteImage($source, '/pub/media/legacy.jpg', 'https://shop.example.com'),
        );
    }

    private function source(string $resolvedUrl): OrmCatalogFeedOfferCandidateSource
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->method('locale')->willReturn(
            $this->getMockBuilder(FileAssetLocale::class)->disableOriginalConstructor()->getMock(),
        );
        $assets->method('resolveUrl')->willReturn(
            new ResolvedStorageUrl($resolvedUrl, StorageUrlOptions::KIND_PUBLIC, true),
        );
        $resolver = new StorefrontProductMediaUrlResolver($assets);
        $source = (new \ReflectionClass(OrmCatalogFeedOfferCandidateSource::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($resolver): void {
            $this->mediaUrls = $resolver;
        }, $source, OrmCatalogFeedOfferCandidateSource::class)();

        return $source;
    }

    private function absoluteImage(OrmCatalogFeedOfferCandidateSource $source, string $path, string $origin): string
    {
        $method = new \ReflectionMethod(OrmCatalogFeedOfferCandidateSource::class, 'absoluteImage');

        return $method->invoke($source, $path, $origin, 544, 'grocery', 'en_US');
    }
}
