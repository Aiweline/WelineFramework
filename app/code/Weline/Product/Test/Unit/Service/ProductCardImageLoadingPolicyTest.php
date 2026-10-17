<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\ProductCardRenderer;

final class ProductCardImageLoadingPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::init();
    }

    public function testListingIndexIsPreservedAndFirstViewportUsesHighPriorityImage(): void
    {
        $product = ['card_index' => 0];
        self::assertSame(
            ['loading' => 'eager', 'fetchpriority' => 'high'],
            ProductCardRenderer::imageLoadingAttributes($product),
        );
    }

    public function testImagesOutsideInitialViewportRemainNativeLazy(): void
    {
        $product = ['card_index' => 8];
        self::assertSame(
            ['loading' => 'lazy'],
            ProductCardRenderer::imageLoadingAttributes($product),
        );
    }

    public function testExplicitLazyOverridesCardIndexForFragmentBodies(): void
    {
        self::assertSame(
            ['loading' => 'lazy'],
            ProductCardRenderer::imageLoadingAttributes([
                'card_index' => 0,
                'image_loading' => 'lazy',
            ]),
        );
    }

    public function testStampUpgradesLazyImageWithinPageEagerBudget(): void
    {
        $lazy = '<article><img class="wpc-image" src="/a.jpg" loading="lazy" decoding="async"></article>';
        $stamped = [];
        for ($i = 0; $i < 8; ++$i) {
            $stamped[] = ProductCardRenderer::stampImageLoadingAttributes($lazy, $i);
            self::assertStringContainsString('loading="eager"', $stamped[$i], 'slot ' . $i);
            self::assertStringContainsString('fetchpriority="high"', $stamped[$i], 'slot ' . $i);
        }

        // Second shelf resets local indexes but page budget is exhausted.
        for ($i = 0; $i < 8; ++$i) {
            $out = ProductCardRenderer::stampImageLoadingAttributes($lazy, $i);
            self::assertStringContainsString('loading="lazy"', $out, 'overflow slot ' . $i);
            self::assertStringNotContainsString('fetchpriority', $out, 'overflow slot ' . $i);
        }
    }

    public function testStampLeavesLazyWhenCardIndexOutsideViewport(): void
    {
        $lazy = '<img class="wpc-image" loading="lazy">';
        $out = ProductCardRenderer::stampImageLoadingAttributes($lazy, 8);
        self::assertSame($lazy, $out);
    }
}
