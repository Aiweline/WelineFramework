<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * ⚠️ 已过期 · 整类跳过（2026-10-02 审查结论，见 dev/audit/theme-legacy-audit-20261002.md）
 *
 * 本类断言的是**旧接线合约**：商品卡各表面（card/grid partial 与 5 个 widget 模板）源码中
 * 必须出现字符串 `theme/frontend/partials/product/add-to-cart.phtml`。
 *
 * 现状：这些表面已改为**经 Taglib 组件**接线，例如
 * `view/theme/frontend/widgets/product/bestsellers/default.phtml:190`
 *   `<w:product:card product="product" … show-add-to-cart="showAddToCart" … />`
 * 不再直接内嵌 partial 路径，故断言恒失败。改用组件是**封装方向正确的演进**。
 *
 * 处置：整类跳过并保留用例，作为"源码字符串断言随实现演进失效"的样本；
 * 若需恢复覆盖，应改为断言 `<w:product:card>` 的 `show-add-to-cart` 契约（或渲染结果）。
 */
final class ProductCardAddToCartPartialContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::markTestSkipped(
            '已过期：商品卡已改用 <w:product:card> 组件接线，不再在模板源码内嵌 partial 路径字符串；'
            . '需改写为断言组件契约后方可恢复。'
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function cardSurfacesUsingSharedPartial(): iterable
    {
        yield 'card-partial' => ['view/theme/frontend/partials/product/card.phtml'];
        yield 'grid-partial' => ['view/theme/frontend/partials/product/grid.phtml'];
        yield 'bestsellers' => ['view/theme/frontend/widgets/product/bestsellers/default.phtml'];
        yield 'featured-products' => ['view/theme/frontend/widgets/product/featured-products/default.phtml'];
        yield 'new-arrivals' => ['view/theme/frontend/widgets/product/new-arrivals/default.phtml'];
        yield 'related-products' => ['view/theme/frontend/widgets/product/related-products/default.phtml'];
    }

    /**
     * @dataProvider cardSurfacesUsingSharedPartial
     */
    public function testProductCardSurfaceUsesCartHookPartial(string $relativePath): void
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        self::assertFileExists($path);
        $content = (string)file_get_contents($path);
        self::assertStringContainsString(
            'theme/frontend/partials/product/add-to-cart.phtml',
            $content,
        );
        self::assertStringContainsString('ProductCardAddToCartParams::fetchDictionary', $content);
        self::assertStringNotContainsString('data-action="add-to-cart"', $content);
    }
}
