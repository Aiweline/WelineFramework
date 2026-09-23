<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * WO-BUYER-SHOW-03：image-gallery looks 运行时优先 Review 聚合，静态 items 兜底。
 */
final class ImageGalleryBuyerLooksRuntimeContractTest extends TestCase
{
    public function testLooksPrefersReviewGalleryThenConfigThenPlaceholder(): void
    {
        $widget = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/widgets/content/image-gallery/default.phtml'
        );

        self::assertStringContainsString('BuyerLooksGalleryInterface', $widget);
        self::assertStringContainsString('BuyerLooksGalleryService', $widget);
        self::assertStringContainsString('galleryItems(', $widget);
        self::assertStringContainsString("\$looksSource = 'review'", $widget);
        self::assertStringContainsString("\$looksSource = 'config'", $widget);
        self::assertStringContainsString("\$looksSource = 'placeholder'", $widget);
        self::assertStringContainsString('data-looks-source=', $widget);
        self::assertStringContainsString('sb-looks-media-link', $widget);
        self::assertStringContainsString('WO-BUYER-SHOW-03', $widget);
    }
}
