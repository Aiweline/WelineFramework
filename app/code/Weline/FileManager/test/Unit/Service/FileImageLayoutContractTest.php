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

    /** @return array<string,mixed> */
    private function usage(): array
    {
        return [
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
