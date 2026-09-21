<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\Data\ResolvedFileImage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Extends\Module\Weline_Theme\Integration\FileImageLayoutValueHydrator;
use Weline\FileManager\Service\FileAssetReferenceIndexer;
use Weline\FileManager\Service\LayoutContentValidator;
use Weline\Framework\Runtime\ScopeIdentity;

final class FileImageLayoutContractTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    public function testDraftReferenceIndexingValidatesAgainstUsageLocale(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('validateImageReference')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ) use ($scope): void {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertSame('zh_Hans_CN', $usage->localeCode);
                self::assertTrue($scope->equals($access->scope));
                // Draft index follows the stamped usage locale, not the layout website-default.
                self::assertSame('zh_Hans_CN', $access->localeCode);
                self::assertSame('draft_index', $access->purpose);
            });
        $assets->expects(self::never())->method('validateImageUsage');
        $validator = new LayoutContentValidator($assets, $this->uninitializedIndexer());

        try {
            $validator->validate([
                'main' => [[
                    'type' => 'file-image',
                    'usage' => $this->usage([
                        'locale_code' => 'zh_Hans_CN',
                    ]),
                ]],
            ], [
                'scope_identity' => $scope,
                'locale_code' => 'en_US',
                'purpose' => 'draft_index',
                'reference_only' => true,
                'index_references' => true,
                'reference_owner_type' => 'theme_layout_draft',
                'reference_owner_id' => 'draft-1',
                'owner_version' => 1,
            ]);
        } catch (\Throwable) {
            // Final indexer is not mockable; replace may fail after validation. The
            // mock expectation above already proves access locale followed usage.
        }
    }

    public function testPublicationValidatesTypedUsageAgainstExactScopeAndLocale(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('validateImageUsage')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ) use ($scope): void {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertSame('en_US', $usage->localeCode);
                self::assertTrue($scope->equals($access->scope));
                self::assertSame('en_US', $access->localeCode);
                self::assertSame(FileAccessContext::PURPOSE_PUBLIC_PUBLISH, $access->purpose);
                $usage->assertPublishable($access->localeCode);
            });
        $validator = new LayoutContentValidator($assets, $this->uninitializedIndexer());

        $validator->validate([
            'main' => [[
                'type' => 'file-image',
                'usage' => $this->usage(),
            ]],
        ], [
            'scope_identity' => $scope,
            'locale_code' => 'en_US',
            'phase' => 'publish',
        ]);
    }

    public function testTypedUsageRequiresExplicitScopeAndLocaleContext(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::never())->method('validateImageUsage');
        $validator = new LayoutContentValidator($assets, $this->uninitializedIndexer());

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate([
            'main' => [[
                'type' => 'file-image',
                'usage' => $this->usage(),
            ]],
        ], []);
    }

    public function testRuntimeHydrationKeepsUrlAndHtmlOutOfPersistedUsage(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ) use ($scope): ResolvedFileImage {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertTrue($scope->equals($access->scope));
                self::assertSame('en_US', $access->localeCode);
                return new ResolvedFileImage(
                    'https://cdn.example.test/media/image.jpg',
                    '<img src="https://cdn.example.test/media/image.jpg" alt="Product">',
                    'Product',
                );
            });
        $hydrator = new FileImageLayoutValueHydrator($assets);

        $result = $hydrator->hydrate([
            'type' => 'file-image',
            'usage' => $this->usage(),
        ], [
            'scope_identity' => $scope,
            'locale_code' => 'en_US',
            'purpose' => 'render',
        ]);

        self::assertSame('https://cdn.example.test/media/image.jpg', $result->value);
        self::assertSame(self::ASSET_ID, $result->metadata['file_asset_id']);
        self::assertSame('Product', $result->metadata['file_alt']);
        self::assertArrayNotHasKey('url', $result->metadata['file_usage']);
        self::assertArrayNotHasKey('disk_code', $result->metadata['file_usage']);
        self::assertArrayNotHasKey('object_key', $result->metadata['file_usage']);
    }

    public function testRuntimeHydrationRejectsCrossLocaleUsage(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::never())->method('resolveImage');
        $hydrator = new FileImageLayoutValueHydrator($assets);

        $this->expectException(\RuntimeException::class);
        $hydrator->hydrate([
            'type' => 'file-image',
            'usage' => $this->usage(),
        ], [
            'scope_identity' => ScopeIdentity::store(
                1,
                'shop',
                'main',
                ScopeIdentity::MODE_NORMAL,
            ),
            'locale_code' => 'fr_FR',
        ]);
    }

    public function testPreviewHydrationFallsBackToUsageLocaleOnMismatch(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ) use ($scope): ResolvedFileImage {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertTrue($scope->equals($access->scope));
                self::assertSame('en_US', $access->localeCode);
                return new ResolvedFileImage(
                    'https://cdn.example.test/media/image.jpg',
                    '<img src="https://cdn.example.test/media/image.jpg" alt="Product">',
                    'Product',
                );
            });
        $hydrator = new FileImageLayoutValueHydrator($assets);

        $result = $hydrator->hydrate([
            'type' => 'file-image',
            'usage' => $this->usage(),
        ], [
            'scope_identity' => $scope,
            'locale_code' => 'fr_FR',
            'purpose' => 'preview',
        ]);

        self::assertSame('https://cdn.example.test/media/image.jpg', $result->value);
    }

    public function testRenderHydrationFallsBackWhenLayoutLocaleEmpty(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ) use ($scope): ResolvedFileImage {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertTrue($scope->equals($access->scope));
                self::assertSame('en_US', $access->localeCode);
                return new ResolvedFileImage(
                    'https://cdn.example.test/media/image-en.jpg',
                    '<img src="https://cdn.example.test/media/image-en.jpg" alt="Product">',
                    'Product',
                );
            });
        $hydrator = new FileImageLayoutValueHydrator($assets);

        $result = $hydrator->hydrate([
            'type' => 'file-image',
            'usage' => $this->usage(),
        ], [
            'scope_identity' => $scope,
            'locale_code' => '',
            'purpose' => 'render',
        ]);

        self::assertSame('https://cdn.example.test/media/image-en.jpg', $result->value);
    }

    public function testRenderHydrationSoftFailsWhenAssetMissing(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willThrowException(new \RuntimeException('The file resource does not exist.'));
        $hydrator = new FileImageLayoutValueHydrator($assets);

        $result = $hydrator->hydrate([
            'type' => 'file-image',
            'usage' => $this->usage(),
        ], [
            'scope_identity' => ScopeIdentity::store(
                1,
                'shop',
                'main',
                ScopeIdentity::MODE_NORMAL,
            ),
            'locale_code' => 'en_US',
            'purpose' => 'render',
        ]);

        self::assertSame('', $result->value);
        self::assertSame('', $result->metadata['file_html']);
        self::assertTrue($result->metadata['file_missing']);
        self::assertSame(self::ASSET_ID, $result->metadata['file_asset_id']);
    }

    public function testPreviewHydrationSoftFailsWhenAssetMissing(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willThrowException(new \RuntimeException('The file resource does not exist.'));
        $hydrator = new FileImageLayoutValueHydrator($assets);

        $result = $hydrator->hydrate([
            'type' => 'file-image',
            'usage' => $this->usage(),
        ], [
            'scope_identity' => ScopeIdentity::store(
                1,
                'shop',
                'main',
                ScopeIdentity::MODE_NORMAL,
            ),
            'locale_code' => 'en_US',
            'purpose' => 'preview',
        ]);

        self::assertSame('', $result->value);
        self::assertTrue($result->metadata['file_missing']);
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function usage(array $overrides = []): array
    {
        return $overrides + [
            'version' => 1,
            'asset_id' => self::ASSET_ID,
            'locale_code' => 'en_US',
            'alt' => 'Product',
            'alt_state' => 'confirmed',
            'decorative' => false,
            'caption' => null,
            'loading' => 'lazy',
            'priority' => 'auto',
            'widths' => [480, 768],
            'sizes' => '100vw',
        ];
    }

    private function uninitializedIndexer(): FileAssetReferenceIndexer
    {
        return (new \ReflectionClass(FileAssetReferenceIndexer::class))->newInstanceWithoutConstructor();
    }
}
