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
    public function testListingResolvesCardImageWithoutLoadingDescriptionAssets(): void
    {
        $assetId = '73ead77d-800c-4a08-ad46-ae4461996aaa';
        $detailAssetId = '12345678-1234-4123-8123-123456789abc';
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $locale = $this->getMockBuilder(FileAssetLocale::class)->disableOriginalConstructor()->getMock();
        $assets->expects(self::once())->method('locale')->with($assetId, 'en_US')->willReturn($locale);
        $assets->expects(self::once())->method('resolveUrl')->with($assetId, self::isInstanceOf(FileAccessContext::class))
            ->willReturn(new ResolvedStorageUrl('/card.jpg', StorageUrlOptions::KIND_PUBLIC, true));
        $description = '<div data-weline-product-description="1688"><img src="asset://' . $detailAssetId . '"></div>';
        $result = (new StorefrontProductMediaUrlResolver($assets))->resolveListingOffer([
            'image' => 'asset://' . $assetId, 'images' => ['asset://' . $assetId],
            'description' => $description, 'description_html' => '<p>old detail projection</p>',
        ], ScopeIdentity::website(0, 'default'), 'en_US');
        self::assertSame('/card.jpg', $result['image']);
        self::assertSame(['/card.jpg'], $result['images']);
        self::assertSame($description, $result['description']);
        self::assertArrayNotHasKey('description_html', $result);
    }

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

    public function testEnglishRequestFallsBackToChineseMetadataWhenDefaultLanguageMissing(): void
    {
        $assetId = '12345678-1234-4123-8123-1234567890ab';
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
                    if ($candidateLocale === 'en_US') {
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
                    static fn(FileAccessContext $context): bool => $context->localeCode === 'zh_Hans_CN'
                        && $context->purpose === FileAccessContext::PURPOSE_PUBLIC_PUBLISH,
                ),
            )
            ->willReturn(new ResolvedStorageUrl(
                '/pub/media/catalog/hanfu/from-zh.jpg',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));

        $resolver = new StorefrontProductMediaUrlResolver($assets);
        $url = $resolver->resolveReference(
            'asset://' . $assetId,
            ScopeIdentity::website(0, 'default'),
            'en_US',
        );

        self::assertSame('/pub/media/catalog/hanfu/from-zh.jpg', $url);
        self::assertSame(['en_US', 'zh_Hans_CN'], $attemptedLocales);
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

    public function testBulkReferencesPreserveOptionalCapabilityScopeAndLegacyFallback(): void
    {
        $assetId = '12345678-1234-4123-8123-123456789abc';
        $scope = ScopeIdentity::channel(7, 'site', 'shop', 'web', ScopeIdentity::MODE_NORMAL);
        $otherChannel = ScopeIdentity::channel(7, 'site', 'shop', 'feed', ScopeIdentity::MODE_NORMAL);
        $references = [
            'card' => 'asset://' . $assetId,
            7 => 'asset://' . $assetId,
            'legacy' => '/legacy.jpg',
            'invalid' => 'asset://not-a-uuid',
            'v1' => 'asset://12345678-1234-1123-8123-123456789abc',
        ];
        $locale = $this->getMockBuilder(FileAssetLocale::class)->disableOriginalConstructor()->getMock();
        $oldManager = $this->createMock(FileAssetManagerInterface::class);
        $attemptedLocales = [];
        $oldManager->method('locale')->willReturnCallback(static function (string $id, string $localeCode) use (&$attemptedLocales, $locale): FileAssetLocale {
            $attemptedLocales[] = $localeCode;
            if ($localeCode === 'fr_FR') { throw new \RuntimeException('missing requested locale'); }
            return $locale;
        });
        $oldManager->method('resolveUrl')->willReturnCallback(static function (string $id, FileAccessContext $context) use ($scope): ResolvedStorageUrl {
            self::assertSame($scope, $context->scope);
            self::assertSame('en_US', $context->localeCode);
            self::assertSame(FileAccessContext::PURPOSE_PUBLIC_PUBLISH, $context->purpose);
            return new ResolvedStorageUrl('/old-manager.jpg', StorageUrlOptions::KIND_PUBLIC, true);
        });
        $old = $this->resolveReferenceBatch(new StorefrontProductMediaUrlResolver($oldManager), $references, $scope, 'fr_FR');
        self::assertSame(['card' => '/old-manager.jpg', 7 => '/old-manager.jpg', 'legacy' => '/legacy.jpg', 'invalid' => '', 'v1' => ''], $old);
        self::assertSame(['fr_FR', 'en_US'], $attemptedLocales);

        $batches = [];
        $manager = $this->createMockForIntersectionOfInterfaces([
            FileAssetManagerInterface::class,
            \Weline\FileManager\Api\FileAssetBatchUrlResolverInterface::class,
        ]);
        $manager->method('locale')->willThrowException(new \LogicException('Bulk-capable manager must not take the legacy read path.'));
        $manager->method('resolveUrl')->willThrowException(new \LogicException('Bulk-capable manager must not take the legacy URL path.'));
        $manager->method('resolveUrls')->willReturnCallback(static function (array $requests) use (&$batches): array {
            $batches[] = $requests;
            $result = [];
            foreach ($requests as $key => $request) {
                $result[$key] = new ResolvedStorageUrl('/batch/' . count($batches) . '/' . $request['asset_id'] . '.jpg', StorageUrlOptions::KIND_PUBLIC, true);
            }
            return $result;
        });
        $resolver = new StorefrontProductMediaUrlResolver($manager);
        $resolved = $this->resolveReferenceBatch($resolver, $references, $scope, 'fr_FR');
        self::assertSame([
            'card' => '/batch/1/' . $assetId . '.jpg', 7 => '/batch/1/' . $assetId . '.jpg',
            'legacy' => '/legacy.jpg', 'invalid' => '', 'v1' => '',
        ], $resolved);
        self::assertCount(1, $batches);
        self::assertSame(['card', 7], array_keys($batches[0]), 'Preserve each input for independent access checks and URL resolution.');
        $request = array_values($batches[0])[0];
        self::assertSame($assetId, $request['asset_id']);
        self::assertSame(['fr_FR', 'en_US'], array_slice(array_map(static fn(FileAccessContext $context): string => $context->localeCode, $request['contexts']), 0, 2));
        foreach ($request['contexts'] as $context) {
            self::assertSame($scope, $context->scope);
            self::assertSame(FileAccessContext::PURPOSE_PUBLIC_PUBLISH, $context->purpose);
        }
        self::assertSame(
            ['same' => '/batch/1/' . $assetId . '.jpg'],
            $this->resolveReferenceBatch($resolver, ['same' => 'asset://' . $assetId], $scope, 'fr_FR'),
            'A bulk result must backfill the same-scope resolver memo.',
        );
        self::assertCount(1, $batches);
        $other = $this->resolveReferenceBatch($resolver, ['same' => 'asset://' . $assetId], $otherChannel, 'fr_FR');
        self::assertSame(['same' => '/batch/2/' . $assetId . '.jpg'], $other);
        self::assertCount(2, $batches, 'A later scope must resolve its own URL.');
        foreach (array_values($batches[1])[0]['contexts'] as $context) { self::assertSame($otherChannel, $context->scope); }
    }

    private function resolveReferenceBatch(StorefrontProductMediaUrlResolver $resolver, array $references, ScopeIdentity $scope, string $locale): array
    {
        return $resolver->resolveReferences($references, $scope, $locale);
    }

    public function testRenderDescriptionHtmlStripsPromoRecommendedProductTables(): void
    {
        $detailAsset = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $promoAsset = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $html = '<div data-weline-product-description="1688">'
            . '<table><tr><td>火爆大促销 欢迎你加入 五一狂欢购 <strong>WUYIKUANHUANGOU</strong></td></tr></table>'
            . '<table><tr><td><img src="asset://' . $promoAsset . '"></td></tr>'
            . '<tr><td>国潮风少女旗袍批发</td></tr>'
            . '<tr><td>￥55</td><td>55</td></tr></table>'
            . '<p>关于绣花颜色款式和面料。上衣面料柔软。</p>'
            . '<p><img src="asset://' . $detailAsset . '"></p>'
            . '</div>';

        $rendered = StorefrontProductMediaUrlResolver::renderDescriptionHtml(
            $html,
            static function (string $reference) use ($detailAsset, $promoAsset): string {
                return match ($reference) {
                    'asset://' . $detailAsset => '/pub/media/catalog/hanfu/detail-real.jpg',
                    'asset://' . $promoAsset => '/pub/media/catalog/hanfu/promo-other.jpg',
                    default => '',
                };
            },
        );

        self::assertStringContainsString('关于绣花颜色款式和面料', $rendered);
        self::assertStringContainsString('/pub/media/catalog/hanfu/detail-real.jpg', $rendered);
        self::assertMatchesRegularExpression(
            '/<img[^>]+src="\/pub\/media\/catalog\/hanfu\/detail-real\.jpg"[^>]+width="800"[^>]+height="800"/',
            $rendered,
        );
        self::assertStringNotContainsString('火爆大促销', $rendered);
        self::assertStringNotContainsString('WUYIKUANHUANGOU', $rendered);
        self::assertStringNotContainsString('国潮风少女旗袍', $rendered);
        self::assertStringNotContainsString('￥55', $rendered);
        self::assertStringNotContainsString('promo-other.jpg', $rendered);
    }

    public function testRenderDescriptionHtmlPreservesExplicitImageDimensions(): void
    {
        $detailAsset = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $html = '<div data-weline-product-description="1688">'
            . '<p><img src="asset://' . $detailAsset . '" width="790" height="1185" alt="尺码示意"></p>'
            . '</div>';

        $rendered = StorefrontProductMediaUrlResolver::renderDescriptionHtml(
            $html,
            static fn(string $reference): string => $reference === 'asset://' . $detailAsset
                ? '/pub/media/catalog/hanfu/size-chart.jpg'
                : '',
        );

        self::assertMatchesRegularExpression(
            '/<img[^>]+src="\/pub\/media\/catalog\/hanfu\/size-chart\.jpg"[^>]+width="790"[^>]+height="1185"/',
            $rendered,
        );
        self::assertStringContainsString('alt="尺码示意"', $rendered);
    }

    public function testRenderDescriptionHtmlPreservesAspectOrientationMarkers(): void
    {
        $detailAsset = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $html = '<div data-weline-product-description="1688">'
            . '<div class="weline-detail-figure-stack weline-detail-figure-stack--fullbleed weline-detail-orient--portrait">'
            . '<div class="weline-detail-figure"><img src="asset://' . $detailAsset . '" width="1200" height="1600" alt="竖图"></div>'
            . '</div>'
            . '<div class="weline-detail-feature weline-detail-orient--landscape" data-weline-orient="landscape" data-weline-pad="g02-blur-3x2">'
            . '<div class="weline-detail-feature__media"><img src="asset://' . $detailAsset . '" width="2400" height="1600" alt="扩横"></div>'
            . '<div class="weline-detail-feature__copy"><h3>交领</h3><p>旁文</p></div>'
            . '</div>'
            . '</div>';

        $rendered = StorefrontProductMediaUrlResolver::renderDescriptionHtml(
            $html,
            static fn(string $reference): string => $reference === 'asset://' . $detailAsset
                ? '/pub/media/catalog/hanfu/portrait.jpg'
                : '',
        );

        self::assertStringContainsString('weline-detail-orient--portrait', $rendered);
        self::assertStringContainsString('weline-detail-orient--landscape', $rendered);
        self::assertStringContainsString('data-weline-orient="landscape"', $rendered);
        self::assertStringContainsString('data-weline-pad="g02-blur-3x2"', $rendered);
        self::assertStringContainsString('width="1200"', $rendered);
        self::assertStringContainsString('height="1600"', $rendered);
    }

    public function testRenderDescriptionHtmlEmitsDetailSuiteSkipMarkerFromRoot(): void
    {
        $detailAsset = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $html = '<div data-weline-product-description="1688" data-weds="xq">'
            . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
            . '<div class="weline-detail-prose"><h3>扶摇</h3><p>旁文</p></div>'
            . '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>'
            . '<p><img src="asset://' . $detailAsset . '" width="787" height="1050" alt="着装"></p>'
            . '</div>';

        $rendered = StorefrontProductMediaUrlResolver::renderDescriptionHtml(
            $html,
            static fn(string $reference): string => $reference === 'asset://' . $detailAsset
                ? '/pub/media/catalog/hanfu/fuyao.jpg'
                : '',
        );

        self::assertStringContainsString('<!--weds:xq-->', $rendered);
        self::assertStringContainsString('data-weds="xq"', $rendered);
        self::assertStringContainsString('weline-detail-quiet-spacer', $rendered);
        self::assertStringContainsString('扶摇', $rendered);
        self::assertSame(1, substr_count($rendered, 'data-weds="xq"'));
    }

    public function testEnsureDescriptionImageAltsFillsEmptyAltWithProductName(): void
    {
        $html = '<p><img src="/media/a.jpg" alt="" width="800" height="800"></p>'
            . '<p><img src="/media/b.jpg" width="800" height="800"></p>'
            . '<p><img src="/media/c.jpg" alt="已有说明文案" width="800" height="800"></p>';

        $out = StorefrontProductMediaUrlResolver::ensureDescriptionImageAlts($html, '悦雅霓裳长安忆襦裙');

        self::assertStringContainsString('alt="悦雅霓裳长安忆襦裙 · 1"', $out);
        self::assertStringContainsString('alt="悦雅霓裳长安忆襦裙 · 2"', $out);
        self::assertStringContainsString('alt="已有说明文案"', $out);
        self::assertStringNotContainsString('alt=""', $out);
    }

    public function testNormalizeDescriptionLayoutGroupsImagesAndReplacesBrokenOcr(): void
    {
        $html = '<img src="/pub/media/a.jpg" width="790" height="1200" alt="1">'
            . '<img src="/pub/media/b.jpg" width="790" height="900" alt="2">'
            . '<h3>名 产品信息 ，皇</h3>'
            . '<ul><li>S 50 90 156 4 15 114</li><li>M 52 96 160 4 16 120</li><li>L 84 102 164 4 17 126</li></ul>'
            . '<p>以上数值来自商品详情图文字识别，手工测量可能存在 1-3 cm 误差。</p>'
            . '<img src="/pub/media/c.jpg" width="790" height="1000" alt="3">';

        $out = StorefrontProductMediaUrlResolver::normalizeDescriptionLayout($html);

        self::assertStringContainsString('weline-detail-figure-stack', $out);
        self::assertStringContainsString('weline-detail-text--size-chart', $out);
        self::assertStringContainsString('尺码参考表', $out);
        self::assertStringNotContainsString('名 产品信息', $out);
        self::assertSame(2, substr_count($out, 'weline-detail-figure-stack'));
    }

    public function testNormalizeDescriptionLayoutPairsPortraitImagesResponsively(): void
    {
        $base = '/pub/media/catalog/hanfu/1688/factory-yueya/731150010223';
        // ~0.73 / ~0.75 pairable portraits; ultra-tall calligraphy board stays solo.
        $html = '<img src="' . $base . '/detail-03-5e775f11bbad.jpg" width="800" height="800" alt="a">'
            . '<img src="' . $base . '/detail-04-3adc1cabe08c.jpg" width="800" height="800" alt="b">'
            . '<img src="' . $base . '/detail-16-b025f8b80432.jpg" width="800" height="800" alt="tall">';

        $out = StorefrontProductMediaUrlResolver::normalizeDescriptionLayout($html);

        self::assertStringContainsString('weline-detail-figure-row--pair', $out);
        self::assertStringContainsString('weline-detail-figure-row--solo', $out);
        self::assertGreaterThanOrEqual(1, substr_count($out, 'weline-detail-figure-row--pair'));
        self::assertStringContainsString('width="790"', $out);
    }

    public function testNormalizeDescriptionLayoutKeepsNearSquareCollageBoardsSolo(): void
    {
        $base = '/pub/media/catalog/hanfu/1688/factory-yueya/731150010223';
        // detail-02 ≈790×861 (~0.92) — 1688 multi-panel board, must not enter a half-column pair.
        $html = '<img src="' . $base . '/detail-02-89008dbf3174.jpg" width="800" height="800" alt="board-a">'
            . '<img src="' . $base . '/detail-05-3e614e1a7cfe.jpg" width="800" height="800" alt="board-b">';

        $out = StorefrontProductMediaUrlResolver::normalizeDescriptionLayout($html);

        self::assertStringNotContainsString('weline-detail-figure-row--pair', $out);
        self::assertSame(2, substr_count($out, 'weline-detail-figure-row--solo'));
    }

    public function testNormalizeDescriptionLayoutRePairsExistingFigureRows(): void
    {
        $base = '/pub/media/catalog/hanfu/1688/factory-yueya/731150010223';
        // Stale pair markup (old rule) must be re-evaluated into solos for collage boards.
        $html = '<div class="weline-detail-figure-stack">'
            . '<div class="weline-detail-figure-row weline-detail-figure-row--pair">'
            . '<figure class="weline-detail-figure"><img src="' . $base . '/detail-02-89008dbf3174.jpg" width="800" height="800" alt="a"></figure>'
            . '<figure class="weline-detail-figure"><img src="' . $base . '/detail-05-3e614e1a7cfe.jpg" width="800" height="800" alt="b"></figure>'
            . '</div></div>';

        $out = StorefrontProductMediaUrlResolver::normalizeDescriptionLayout($html);

        self::assertStringNotContainsString('weline-detail-figure-row--pair', $out);
        self::assertSame(2, substr_count($out, 'weline-detail-figure-row--solo'));
    }
}
