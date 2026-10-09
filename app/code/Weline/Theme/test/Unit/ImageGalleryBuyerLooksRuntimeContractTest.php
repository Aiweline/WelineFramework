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
        self::assertStringContainsString('filterLooksItemsForWebsite', $widget);
        self::assertStringContainsString('resolveLooksWebsiteId', $widget);
        self::assertStringContainsString('editor_context', $widget);
        self::assertStringContainsString('galleryItems(max(1, (int)$columns), $looksWebsiteId)', $widget);
        self::assertStringContainsString('findById($websiteId, $productId)', $widget);
        self::assertStringContainsString('StorefrontCatalogProductVisibility', $widget);
        self::assertStringContainsString('isProductSellable', $widget);
        self::assertStringContainsString('晒出你的穿搭', $widget);
        self::assertStringNotContainsString("\$subtitle = '晒出你的汉服穿搭'", $widget);
        self::assertStringNotContainsString("/product/543#product-reviews", $widget);
        // Linkless config rows must not pass through (cross-site seed).
        self::assertStringContainsString('Unverifiable looks row', $widget);
        // Placeholder branch must replace $items (not append onto filtered seed).
        self::assertStringContainsString("\$items = [];", $widget);
        self::assertStringContainsString('Must replace $items', $widget);
    }

    public function testEditorJsDerivesSiteMountFromScopeIdentity(): void
    {
        $editor = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/ui/pages/weline-theme-editor.js'
        );
        $legacy = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/statics/js/theme-editor.js'
        );
        foreach ([$editor, $legacy] as $source) {
            self::assertStringContainsString('deriveStorefrontMountFromScopeIdentity', $source);
            self::assertStringContainsString("~site/' + code", $source);
            self::assertStringContainsString(
                'deriveStorefrontMountFromScopeIdentity(state.scopeIdentity)',
                $source
            );
        }
    }
}
