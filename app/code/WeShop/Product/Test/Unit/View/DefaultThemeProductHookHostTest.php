<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeProductHookHostTest extends TestCase
{
    public function testProductViewHostsModernDetailHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/product/view.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Product::frontend::product::detail::after-add-to-cart', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::product::add-to-cart::options-popup', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::tabs-content', $template);
        $this->assertStringContainsString('WeShop_Product::detail::after_add_to_cart', $template);
        $this->assertStringContainsString('class="add-to-cart', $template);
        $this->assertStringContainsString('data-product-id', $template);
        $this->assertStringContainsString('You Might Also Like', $template);
        $this->assertStringNotContainsString('Customer Reviews', $template);
        $this->assertStringNotContainsString('WeShop_Review::product::summary_before', $template);
        $this->assertStringNotContainsString('WeShop_Review::product::summary_after', $template);
    }

    public function testCanonicalTabsHookHostsExpandedReviewAndQaSections(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Product/frontend/layouts/product/tabs-content.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::description-content<else/>', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::specifications-content<else/>', $template);
        $this->assertStringContainsString('$productSource = $this->getData(\'product\') ?? [];', $template);
        $this->assertStringContainsString('method_exists($productSource, \'getData\')', $template);
        $this->assertStringContainsString("\$attributesSource = \$this->getData('attributes') ?? [];", $template);
        $this->assertStringContainsString('$pageProduct = is_array($pageData[\'product\'] ?? null) ? $pageData[\'product\'] : [];', $template);
        $this->assertStringContainsString("(\$product['specifications'] ?? []) === []", $template);
        $this->assertStringContainsString('$normalizeSpecifications = static function (array $specifications): array', $template);
        $this->assertStringContainsString('$specifications = $normalizeSpecifications((array)($product[\'specifications\'] ?? []));', $template);
        $this->assertStringContainsString('$specifications === [] && $attributes !== []', $template);
        $this->assertStringContainsString("\$product['specifications'] = \$specifications;", $template);
        $this->assertStringContainsString('weshop.product.view.page_data', $template);
        $this->assertStringContainsString("\$this->assign('product', \$product);", $template);
        $this->assertStringContainsString("\$this->assign('reviews', \$reviews);", $template);
        $this->assertStringContainsString("\$this->assign('qa', \$qa);", $template);
        $this->assertStringContainsString("\$this->assign('attributes', \$attributes);", $template);
        $this->assertStringContainsString('getAverageRating($productId)', $template);
        $this->assertStringContainsString("\$product['rating_distribution'] = \$ratingDistribution;", $template);
        $this->assertStringContainsString('WeShop_QA::frontend::layouts::product-questions::content', $template);
        $this->assertStringContainsString('WeShop_Review::frontend::layouts::product-reviews::content', $template);
        $this->assertStringContainsString('WeShop_QA::frontend::layouts::product-questions::content<else/>', $template);
        $this->assertStringContainsString('WeShop_Review::frontend::layouts::product-reviews::content<else/>', $template);
        $this->assertStringContainsString("__('No product description available.')", $template);
        $this->assertStringContainsString("__('No specifications available.')", $template);
        $this->assertStringContainsString("__('Product questions will appear here when available.')", $template);
        $this->assertStringContainsString("__('Customer reviews will appear here when available.')", $template);
        $this->assertStringContainsString('window.WeShopProductTabs', $template);
        $this->assertStringContainsString('reply_id', $template);
        $this->assertStringContainsString('review-reply-', $template);
        $this->assertStringContainsString('data-review-deep-link-target', $template);
        $this->assertStringContainsString('product-tabs-shell', $template);
        $this->assertStringContainsString('product-detail-sections-shell', $template);
        $this->assertStringContainsString('data-product-detail-sections', $template);
        $this->assertStringContainsString('data-product-tabs-mode="sections"', $template);
        $this->assertStringContainsString('data-product-section="description"', $template);
        $this->assertStringContainsString('data-product-section="specifications"', $template);
        $this->assertStringContainsString('data-product-section="qa"', $template);
        $this->assertStringContainsString('data-product-section="reviews"', $template);
        $this->assertStringContainsString('role="region"', $template);
        $this->assertStringNotContainsString('role="tablist"', $template);
        $this->assertStringNotContainsString('class="product-tab is-active"', $template);
        $this->assertStringNotContainsString('product-tab-panel[hidden]', $template);
        $this->assertStringNotContainsString('class="product-tab-panel tab-content"', $template);
        $this->assertStringNotContainsString('whitespace-nowrap py-4', $template);
    }

    public function testProductLayoutSlotsHostCanonicalHooksWithFallbacks(): void
    {
        $template = file_get_contents(BP . '/app/code/Weline/Theme/view/theme/frontend/layouts/product/default.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('<w:slot id="product-main"', $template);
        $this->assertStringContainsString('<w:hook>WeShop_Product::frontend::layouts::product::main-content<else/>', $template);
        $this->assertStringContainsString('<section class="product-detail-layout__tabs">', $template);
        $this->assertStringContainsString('<w:slot id="product-tabs"', $template);
        $this->assertStringContainsString('class="product-detail-layout__tabs-slot"', $template);
        $this->assertStringContainsString('<w:hook>WeShop_Product::frontend::layouts::product::tabs-content<else/>', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::recommendations-content<else/>', $template);
        $this->assertStringContainsString('<w:slot id="product-related-products"', $template);
        $this->assertStringContainsString('accept="related-products,you-may-like,product-carousel"', $template);
        $this->assertStringContainsString('<w:slot id="product-bestsellers"', $template);
        $this->assertStringContainsString('accept="bestsellers,product-carousel"', $template);
        $this->assertStringContainsString('<w:slot id="product-recently-viewed"', $template);
        $this->assertStringContainsString('accept="recently-viewed,product-carousel"', $template);
        $this->assertStringContainsString('<w:slot id="product-cross-sell"', $template);
        $this->assertStringContainsString('accept="cross-sell,up-sell,product-carousel"', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::related-products</w:hook>', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::bestsellers</w:hook>', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::recently-viewed</w:hook>', $template);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::cross-sell</w:hook>', $template);
        $this->assertStringNotContainsString('accept="related-products,you-may-like,recently-viewed,cross-sell,up-sell,product-carousel,bestsellers"', $template);
        $this->assertStringContainsString('</w:hook>', $template);
        $this->assertStringContainsString('</w:slot>', $template);
    }

    public function testProductHookRegistryExposesGranularDetailExtensionPoints(): void
    {
        $registry = file_get_contents(__DIR__ . '/../../../hook.php');
        $this->assertIsString($registry);

        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::description-content', $registry);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::specifications-content', $registry);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::recommendations-content', $registry);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::related-products', $registry);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::bestsellers', $registry);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::recently-viewed', $registry);
        $this->assertStringContainsString('WeShop_Product::frontend::layouts::product::cross-sell', $registry);
    }

    public function testProductMainContentDoesNotRenderSecondDetailPanels(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Product/frontend/layouts/product/main-content.phtml');
        $this->assertIsString($template);

        $this->assertStringNotContainsString('product-description-panel', $template);
        $this->assertStringNotContainsString('product-qa-panel', $template);
        $this->assertStringNotContainsString('product-reviews-panel', $template);
        $this->assertStringNotContainsString('product-review-layout', $template);
        $this->assertStringNotContainsString("__('鍟嗗搧璇︽儏')", $template);
        $this->assertStringNotContainsString("__('鍟嗗搧闂瓟')", $template);
        $this->assertStringNotContainsString("__('椤惧璇勪环')", $template);
    }

    public function testBackendProductEditHostsReviewManagementHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Backend/Product/Edit/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('WeShop_Product::backend::product::edit::nav-after', $template);
        $this->assertStringContainsString('WeShop_Product::backend::product::edit::content-after', $template);
    }
}
