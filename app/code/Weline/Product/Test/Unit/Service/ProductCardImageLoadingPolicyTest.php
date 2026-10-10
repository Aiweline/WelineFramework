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

    public function testPageLevelEagerBudgetCapsMultiShelfResets(): void
    {
        $method = new \ReflectionMethod(ProductCardRenderer::class, 'bucketCardIndexForFragmentReuse');
        $method->setAccessible(true);

        for ($i = 0; $i < 8; ++$i) {
            $bucketed = $method->invoke(null, ['id' => 100 + $i, 'card_index' => $i]);
            self::assertSame(0, (int)$bucketed['card_index'], 'first shelf index ' . $i);
            self::assertSame(
                ['loading' => 'eager', 'fetchpriority' => 'high'],
                ProductCardRenderer::imageLoadingAttributes($bucketed),
            );
        }

        // Second shelf resets local indexes to 0..7 but page budget is exhausted.
        for ($i = 0; $i < 8; ++$i) {
            $bucketed = $method->invoke(null, ['id' => 200 + $i, 'card_index' => $i]);
            self::assertSame(8, (int)$bucketed['card_index'], 'second shelf index ' . $i);
            self::assertSame(
                ['loading' => 'lazy'],
                ProductCardRenderer::imageLoadingAttributes($bucketed),
            );
        }
    }
}
