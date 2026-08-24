<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\Data\ResolvedFileImage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Extends\Module\Weline_Widget\Integration\WidgetFileImagePreviewResolver;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class WidgetFileImagePreviewResolverTest extends TestCase
{
    private const ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';

    protected function tearDown(): void
    {
        RequestContext::resetWelineVars();
        parent::tearDown();
    }

    public function testResolvePreviewUrlUsesPreviewPurposeAndUsageLocale(): void
    {
        $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        RequestContext::installScopeIdentity($scope);

        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ) use ($scope): ResolvedFileImage {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertSame('zh_Hans_CN', $usage->localeCode);
                self::assertTrue($scope->equals($access->scope));
                self::assertSame('preview', $access->purpose);

                return new ResolvedFileImage(
                    'https://cdn.example.test/media/hero.jpg',
                    '<img src="https://cdn.example.test/media/hero.jpg" alt="Hero">',
                );
            });

        $resolver = new WidgetFileImagePreviewResolver($assets);
        $url = $resolver->resolvePreviewUrl([
            'type' => 'file-image',
            'usage' => [
                'version' => 1,
                'asset_id' => self::ASSET_ID,
                'locale_code' => 'zh_Hans_CN',
                'alt' => 'Hero',
            ],
        ]);

        self::assertSame('https://cdn.example.test/media/hero.jpg', $url);
    }

    public function testResolvePreviewUrlFallsBackToGlobalScopeWhenScopeMissing(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('resolveImage')
            ->willReturnCallback(static function (
                ImageUsage $usage,
                FileAccessContext $access,
            ): ResolvedFileImage {
                self::assertSame(self::ASSET_ID, $usage->assetId);
                self::assertSame('zh_Hans_CN', $usage->localeCode);
                self::assertTrue($access->scope->equals(ScopeIdentity::global()));
                self::assertSame('preview', $access->purpose);

                return new ResolvedFileImage(
                    'https://cdn.example.test/media/hero.jpg',
                    '<img src="https://cdn.example.test/media/hero.jpg" alt="Hero">',
                );
            });

        $resolver = new WidgetFileImagePreviewResolver($assets);
        self::assertSame('https://cdn.example.test/media/hero.jpg', $resolver->resolvePreviewUrl([
            'type' => 'file-image',
            'usage' => [
                'version' => 1,
                'asset_id' => self::ASSET_ID,
                'locale_code' => 'zh_Hans_CN',
                'alt' => 'Hero',
            ],
        ]));
    }
}
