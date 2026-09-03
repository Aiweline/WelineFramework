<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Product\Service\ProductAdminMediaPresenter;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class ProductAdminMediaPresenterTest extends TestCase
{
    public function testPresentAssignmentResolvesFileAssetPreviewUrl(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->expects(self::once())
            ->method('locale')
            ->with('73b8afbc-6539-4293-a4b3-ea6949934dce', 'zh_Hans_CN');
        $assets->expects(self::once())
            ->method('resolveUrl')
            ->with(
                '73b8afbc-6539-4293-a4b3-ea6949934dce',
                self::callback(static function (FileAccessContext $context): bool {
                    return $context->purpose === 'preview'
                        && $context->localeCode === 'zh_Hans_CN';
                }),
            )
            ->willReturn(new ResolvedStorageUrl(
                'https://cdn.example.test/media/preview.jpg',
                StorageUrlOptions::KIND_PUBLIC,
                true,
            ));

        $presenter = new ProductAdminMediaPresenter($assets);
        $row = $presenter->presentAssignment([
            'asset_id' => '73b8afbc-6539-4293-a4b3-ea6949934dce',
            'mime_type' => 'image/jpeg',
            'role' => 'main',
        ], 1);

        self::assertSame('https://cdn.example.test/media/preview.jpg', $row['preview_url']);
        self::assertSame('https://cdn.example.test/media/preview.jpg', $row['display_url']);
    }

    public function testPresentAssignmentReturnsEmptyPreviewWhenResolveFails(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $assets->method('locale')->willThrowException(new \RuntimeException('missing'));

        $presenter = new ProductAdminMediaPresenter($assets);
        $row = $presenter->presentAssignment([
            'asset_id' => '73b8afbc-6539-4293-a4b3-ea6949934dce',
        ], 1);

        self::assertSame('', $row['preview_url']);
    }

    public function testDisplayableImageUrlAllowsHttpAndRejectsTraversal(): void
    {
        $assets = $this->createMock(FileAssetManagerInterface::class);
        $presenter = new ProductAdminMediaPresenter($assets);

        self::assertSame(
            'https://cdn.example.test/a.jpg',
            $presenter->displayableImageUrl('https://cdn.example.test/a.jpg'),
        );
        self::assertSame('', $presenter->displayableImageUrl('../etc/passwd'));
    }
}
