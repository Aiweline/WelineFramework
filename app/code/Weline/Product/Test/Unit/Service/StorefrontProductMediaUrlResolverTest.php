<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class StorefrontProductMediaUrlResolverTest extends TestCase
{
    public function testAssetReferenceUsesExactLocaleMetadataAndPublicFileManagerUrl(): void
    {
        $assetId = '73ead77d-800c-4a08-ad46-ae4461996aaa';
        $scope = ScopeIdentity::website(0, 'default');
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $locale = $this->getMockBuilder(FileAssetLocale::class)
            ->disableOriginalConstructor()
            ->getMock();

        $assets->expects(self::once())
            ->method('locale')
            ->with($assetId, 'en_US')
            ->willReturn($locale);
        $assets->expects(self::once())
            ->method('resolveUrl')
            ->with(
                $assetId,
                self::callback(static fn(FileAccessContext $context): bool =>
                    $context->scope === $scope
                    && $context->localeCode === 'en_US'
                    && $context->purpose === FileAccessContext::PURPOSE_PUBLIC_PUBLISH),
            )
            ->willReturn(new ResolvedStorageUrl(
                '/pub/media/catalog/hanfu/r2/products/crane-01.webp',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));

        $resolved = (new StorefrontProductMediaUrlResolver($assets))->resolveOffer([
            'image' => 'asset://' . $assetId,
            'images' => ['asset://' . $assetId, '/pub/media/catalog/legacy.webp'],
            'variant_axes' => [[
                'code' => 'color',
                'options' => [[
                    'value' => '7',
                    'label' => '红色',
                    'swatch_image' => 'asset://' . $assetId,
                    'gallery_images' => [
                        'asset://' . $assetId,
                        '/pub/media/catalog/legacy.webp',
                    ],
                    'gallery_by_color' => [
                        '7' => ['asset://' . $assetId],
                    ],
                ]],
            ]],
        ], $scope, 'en_US');

        self::assertSame('/pub/media/catalog/hanfu/r2/products/crane-01.webp', $resolved['image']);
        self::assertSame([
            '/pub/media/catalog/hanfu/r2/products/crane-01.webp',
            '/pub/media/catalog/legacy.webp',
        ], $resolved['images']);
        $option = $resolved['variant_axes'][0]['options'][0];
        self::assertSame(
            '/pub/media/catalog/hanfu/r2/products/crane-01.webp',
            $option['swatch_image'],
        );
        self::assertSame([
            '/pub/media/catalog/hanfu/r2/products/crane-01.webp',
            '/pub/media/catalog/legacy.webp',
        ], $option['gallery_images']);
        self::assertSame([
            '7' => ['/pub/media/catalog/hanfu/r2/products/crane-01.webp'],
        ], $option['gallery_by_color']);
    }

    public function testAssetReferenceFallsBackToEnglishMetadataBeforeWebsiteDefault(): void
    {
        $assetId = '12345678-1234-4123-8123-123456789abc';
        $attemptedLocales = [];
        $localeMetadata = $this->getMockBuilder(FileAssetLocale::class)
            ->disableOriginalConstructor()
            ->getMock();
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::exactly(2))
            ->method('locale')
            ->willReturnCallback(
                static function (string $candidateAssetId, string $candidateLocale) use (
                    $assetId,
                    &$attemptedLocales,
                    $localeMetadata,
                ): FileAssetLocale {
                    self::assertSame($assetId, $candidateAssetId);
                    $attemptedLocales[] = $candidateLocale;
                    if ($candidateLocale === 'ar_SA') {
                        throw new \RuntimeException('missing target locale metadata');
                    }

                    return $localeMetadata;
                },
            );
        $assets->expects(self::once())
            ->method('resolveUrl')
            ->with(
                $assetId,
                self::callback(
                    static fn(FileAccessContext $context): bool => $context->localeCode === 'en_US'
                        && $context->purpose === FileAccessContext::PURPOSE_PUBLIC_PUBLISH,
                ),
            )
            ->willReturn(new ResolvedStorageUrl(
                '/pub/media/catalog/hanfu/example.jpg',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));

        $resolver = new StorefrontProductMediaUrlResolver($assets);
        $url = $resolver->resolveReference(
            'asset://' . $assetId,
            ScopeIdentity::website(1, 'default'),
            'ar_SA',
        );

        self::assertSame('/pub/media/catalog/hanfu/example.jpg', $url);
        self::assertSame(['ar_SA', 'en_US'], $attemptedLocales);
    }

    public function testLegacyPublicPathRemainsLocaleNeutral(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::never())->method('locale');
        $assets->expects(self::never())->method('resolveUrl');

        $resolver = new StorefrontProductMediaUrlResolver($assets);

        self::assertSame(
            '/pub/media/catalog/hanfu/legacy.jpg',
            $resolver->resolveReference(
                '/pub/media/catalog/hanfu/legacy.jpg',
                ScopeIdentity::website(1, 'default'),
                'ar_SA',
            ),
        );
    }


    public function testCachedOfferRowsResolveBeforeWidgetProjection(): void
    {
        $assetId = '73ead77d-800c-4a08-ad46-ae4461996aaa';
        $scope = ScopeIdentity::website(0, 'default');
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $locale = $this->getMockBuilder(FileAssetLocale::class)
            ->disableOriginalConstructor()
            ->getMock();

        $assets->expects(self::once())
            ->method('locale')
            ->with($assetId, 'zh_Hans_CN')
            ->willReturn($locale);
        $assets->expects(self::once())
            ->method('resolveUrl')
            ->willReturn(new ResolvedStorageUrl(
                '/pub/media/catalog/hanfu/r2/products/crane-01.webp',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));

        $resolved = (new StorefrontProductMediaUrlResolver($assets))->resolveOffers([
            ['product_id' => 101, 'image' => 'asset://' . $assetId],
            ['product_id' => 102, 'image' => '/pub/media/catalog/hanfu/r2/products/legacy-01.webp'],
        ], $scope, 'zh_Hans_CN');

        self::assertSame('/pub/media/catalog/hanfu/r2/products/crane-01.webp', $resolved[0]['image']);
        self::assertSame('/pub/media/catalog/hanfu/r2/products/legacy-01.webp', $resolved[1]['image']);
        self::assertSame([101, 102], array_column($resolved, 'product_id'));
    }

    public function testInvalidAssetFallsBackToTheNextCompatibleMediaPath(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::never())->method('locale');
        $assets->expects(self::never())->method('resolveUrl');

        $resolved = (new StorefrontProductMediaUrlResolver($assets))->resolveOffer([
            'image' => 'asset://not-a-uuid',
            'images' => ['/pub/media/catalog/legacy.webp'],
        ], ScopeIdentity::website(0, 'default'), 'zh_Hans_CN');

        self::assertSame('/pub/media/catalog/legacy.webp', $resolved['image']);
        self::assertSame(['/pub/media/catalog/legacy.webp'], $resolved['images']);
    }

    public function testResolveReferenceIsMemoizedAcrossOffersInOneResolverInstance(): void
    {
        $assetId = '73ead77d-800c-4a08-ad46-ae4461996bbb';
        $scope = ScopeIdentity::website(0, 'default');
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $locale = $this->getMockBuilder(FileAssetLocale::class)
            ->disableOriginalConstructor()
            ->getMock();

        $assets->expects(self::once())
            ->method('locale')
            ->with($assetId, 'zh_Hans_CN')
            ->willReturn($locale);
        $assets->expects(self::once())
            ->method('resolveUrl')
            ->willReturn(new ResolvedStorageUrl(
                '/pub/media/catalog/hanfu/r2/products/crane-memo.webp',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));

        $resolver = new StorefrontProductMediaUrlResolver($assets);
        $resolved = $resolver->resolveOffers([
            ['product_id' => 1, 'image' => 'asset://' . $assetId],
            ['product_id' => 2, 'image' => 'asset://' . $assetId],
        ], $scope, 'zh_Hans_CN');

        self::assertSame(
            '/pub/media/catalog/hanfu/r2/products/crane-memo.webp',
            $resolved[0]['image'],
        );
        self::assertSame(
            '/pub/media/catalog/hanfu/r2/products/crane-memo.webp',
            $resolved[1]['image'],
        );
    }
}
