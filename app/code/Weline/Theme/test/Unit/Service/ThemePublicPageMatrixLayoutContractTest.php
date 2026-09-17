<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeLayout;

final class ThemePublicPageMatrixLayoutContractTest extends TestCase
{
    public function testApprovedFrontendPageTypesHaveDefaultLayouts(): void
    {
        $base = $this->root() . '/app/code/Weline/Theme/view/theme/frontend/layouts';
        $pageTypes = [
            ThemeLayout::PAGE_TYPE_HOME,
            ThemeLayout::PAGE_TYPE_CATEGORY,
            ThemeLayout::PAGE_TYPE_PRODUCT,
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
            ThemeLayout::PAGE_TYPE_SEARCH,
            ThemeLayout::PAGE_TYPE_PROMOTION,
            ThemeLayout::PAGE_TYPE_ACTIVITY,
            ThemeLayout::PAGE_TYPE_CART,
            ThemeLayout::PAGE_TYPE_CHECKOUT,
            ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS,
            ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
            ThemeLayout::PAGE_TYPE_ACCOUNT,
            ThemeLayout::PAGE_TYPE_BLOG,
            ThemeLayout::PAGE_TYPE_BLOG_CATEGORY,
            ThemeLayout::PAGE_TYPE_CMS,
            ThemeLayout::PAGE_TYPE_FAQ,
            ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE,
            ThemeLayout::PAGE_TYPE_GUIDE,
            ThemeLayout::PAGE_TYPE_ABOUT,
            ThemeLayout::PAGE_TYPE_CONTACT,
            ThemeLayout::PAGE_TYPE_QA,
            ThemeLayout::PAGE_TYPE_RMA,
            ThemeLayout::PAGE_TYPE_POLICY,
            ThemeLayout::PAGE_TYPE_TERMS,
            ThemeLayout::PAGE_TYPE_NOT_FOUND,
            ThemeLayout::PAGE_TYPE_DEFAULT,
        ];

        $moduleOwned = [
            ThemeLayout::PAGE_TYPE_PROMOTION => '/app/code/Weline/Promotion/view/theme/frontend/layouts/promotion/default.phtml',
            ThemeLayout::PAGE_TYPE_PRODUCT => '/app/code/Weline/Product/view/theme/frontend/layouts/product/default.phtml',
            ThemeLayout::PAGE_TYPE_CATEGORY => '/app/code/Weline/Product/view/theme/frontend/layouts/category/default.phtml',
            ThemeLayout::PAGE_TYPE_PRODUCT_LIST => '/app/code/Weline/Product/view/theme/frontend/layouts/products/default.phtml',
            ThemeLayout::PAGE_TYPE_BLOG => '/app/code/Weline/Blog/view/theme/frontend/layouts/blog/default.phtml',
            ThemeLayout::PAGE_TYPE_BLOG_CATEGORY => '/app/code/Weline/Blog/view/theme/frontend/layouts/blog_category/default.phtml',
            ThemeLayout::PAGE_TYPE_FAQ => '/app/code/Weline/Faq/view/theme/frontend/layouts/faq/default.phtml',
            ThemeLayout::PAGE_TYPE_CMS => '/app/code/Weline/Cms/view/theme/frontend/layouts/cms_page/default.phtml',
            ThemeLayout::PAGE_TYPE_PAYMENT_GUIDE => '/app/code/Weline/Payment/view/theme/frontend/layouts/payment_guide/default.phtml',
            ThemeLayout::PAGE_TYPE_CART => '/app/code/Weline/Cart/view/theme/frontend/layouts/cart/default.phtml',
            ThemeLayout::PAGE_TYPE_CHECKOUT => '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/default.phtml',
            ThemeLayout::PAGE_TYPE_CHECKOUT_SUCCESS => '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/success/default.phtml',
            ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE => '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/failure/default.phtml',
            ThemeLayout::PAGE_TYPE_ACCOUNT => '/app/code/Weline/Customer/view/theme/frontend/layouts/account/default.phtml',
            ThemeLayout::PAGE_TYPE_CONTACT => '/app/code/Weline/Customer/view/theme/frontend/layouts/contact/default.phtml',
            ThemeLayout::PAGE_TYPE_SEARCH => '/app/code/Weline/Search/view/theme/frontend/layouts/search/default.phtml',
            ThemeLayout::PAGE_TYPE_RMA => '/app/code/Weline/Rma/view/theme/frontend/layouts/rma/default.phtml',
        ];

        foreach ($pageTypes as $pageType) {
            if (isset($moduleOwned[$pageType])) {
                self::assertFileExists($this->root() . $moduleOwned[$pageType], $pageType);
                continue;
            }
            self::assertFileExists($base . '/' . $pageType . '/default.phtml', $pageType);
        }

        self::assertFileExists(
            $this->root() . '/app/code/Weline/Theme/view/theme/backend/layouts/dashboard/default.phtml',
            ThemeLayout::PAGE_TYPE_DASHBOARD
        );
    }

    public function testNewCanonicalSkeletonsAreContentNeutralAndSlotDriven(): void
    {
        // Checkout success/failure shells are module-owned (passthrough + storefront CTA),
        // not Theme-neutral skeletons like promotion/qa.
        foreach ([
            ThemeLayout::PAGE_TYPE_PROMOTION,
            ThemeLayout::PAGE_TYPE_QA,
        ] as $pageType) {
            $path = match ($pageType) {
                ThemeLayout::PAGE_TYPE_PROMOTION => $this->root()
                    . '/app/code/Weline/Promotion/view/theme/frontend/layouts/promotion/default.phtml',
                default => $this->root()
                    . '/app/code/Weline/Theme/view/theme/frontend/layouts/'
                    . $pageType
                    . '/default.phtml',
            };
            self::assertFileExists($path, $pageType);
            $source = (string)\file_get_contents($path);

            self::assertStringContainsString('<w:slot id="content"', $source, $pageType);
            self::assertStringContainsString(
                'Weline_Theme::frontend::layouts::' . $pageType . '::content',
                $source,
                $pageType
            );
            self::assertStringNotContainsString('amazon', \strtolower($source), $pageType);
            self::assertStringNotContainsString('data:image/svg+xml', \strtolower($source), $pageType);
            self::assertStringNotContainsString('stub', \strtolower($source), $pageType);
            self::assertStringNotContainsString('演示商品', $source, $pageType);
        }
    }

    public function testNewCanonicalSkeletonsExposeHonestPublicFallbackStates(): void
    {
        $expectedFallbacks = [
            ThemeLayout::PAGE_TYPE_PROMOTION => 'storefront-promotion-empty',
            ThemeLayout::PAGE_TYPE_QA => 'storefront-qa-page',
        ];

        foreach ($expectedFallbacks as $pageType => $testId) {
            $path = match ($pageType) {
                ThemeLayout::PAGE_TYPE_PROMOTION => $this->root()
                    . '/app/code/Weline/Promotion/view/theme/frontend/layouts/promotion/default.phtml',
                default => $this->root()
                    . '/app/code/Weline/Theme/view/theme/frontend/layouts/'
                    . $pageType
                    . '/default.phtml',
            };
            $source = (string)\file_get_contents($path);

            self::assertStringContainsString('data-testid="' . $testId . '"', $source, $pageType);
            self::assertStringContainsString('<?php else: ?>', $source, $pageType);
            self::assertStringContainsString('$renderedTemplateContent', $source, $pageType);
            self::assertStringContainsString("trim(\$renderedTemplateContent) !== ''", $source, $pageType);
        }
    }

    public function testCheckoutFailureLayoutKeepsSlashPathAndStorefrontFallback(): void
    {
        $path = $this->root()
            . '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/failure/default.phtml';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('data-layout="checkout/failure"', $source);
        self::assertStringContainsString('data-testid="storefront-checkout-failure-page"', $source);
        self::assertStringContainsString('<w:slot id="content"', $source);
        self::assertStringContainsString('checkout-failure-content--passthrough', $source);
        self::assertDirectoryDoesNotExist(
            $this->root() . '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout_failure'
        );
        self::assertDirectoryDoesNotExist(
            $this->root() . '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout_failer'
        );
        self::assertDirectoryDoesNotExist(
            $this->root() . '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout_success'
        );
    }

    public function testCanonicalFailureRouteAndPublicLayoutAllowlistStayAligned(): void
    {
        $router = (string)\file_get_contents(
            $this->root() . '/app/code/Weline/Theme/Controller/Router.php'
        );
        // Path↔layout via layout_resolve — no defaultPublicRouteMap alias table.
        self::assertStringNotContainsString('defaultPublicRouteMap', $router);
        self::assertStringContainsString('LayoutResolveService', $router);
        self::assertStringContainsString('resolveFromPath', $router);

        $policy = (string)\file_get_contents(
            $this->root() . '/app/code/Weline/Theme/Controller/Frontend/Policy.php'
        );
        foreach (['checkout/failure', 'promotion', 'qa', 'payment_guide', 'not_found', 'products'] as $pageType) {
            self::assertStringContainsString("'{$pageType}' => ['default']", $policy, $pageType);
        }
        foreach (['checkout_failure', 'checkout_failer', 'checkout_success'] as $legacyPageType) {
            self::assertStringNotContainsString("'{$legacyPageType}' => ['default']", $policy, $legacyPageType);
        }

        self::assertFileExists(
            $this->root() . '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/failure/default.phtml'
        );
        self::assertFileExists(
            $this->root() . '/app/code/Weline/Checkout/view/theme/frontend/layouts/checkout/success/default.phtml'
        );
        self::assertFileExists(
            $this->root() . '/app/code/Weline/Product/view/theme/frontend/layouts/products/default.phtml'
        );
    }

    public function testLayoutCatalogNamesTheCanonicalMatrix(): void
    {
        $catalog = (string)\file_get_contents(
            $this->root() . '/app/code/Weline/Theme/Service/LayoutDataService.php'
        );

        foreach (['checkout/failure', 'checkout/success', 'promotion', 'qa', 'payment_guide', 'guide', 'terms'] as $pageType) {
            self::assertStringContainsString("'{$pageType}' =>", $catalog, $pageType);
        }
        foreach (['checkout_failure', 'checkout_failer', 'checkout_success'] as $legacyPageType) {
            self::assertStringNotContainsString("'{$legacyPageType}' =>", $catalog, $legacyPageType);
        }
    }

    private function root(): string
    {
        return \dirname(__DIR__, 7);
    }
}
