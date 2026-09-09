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
            ThemeLayout::PAGE_TYPE_REVIEW,
            ThemeLayout::PAGE_TYPE_QA,
            ThemeLayout::PAGE_TYPE_RMA,
            ThemeLayout::PAGE_TYPE_POLICY,
            ThemeLayout::PAGE_TYPE_TERMS,
            ThemeLayout::PAGE_TYPE_NOT_FOUND,
            ThemeLayout::PAGE_TYPE_DEFAULT,
        ];

        foreach ($pageTypes as $pageType) {
            self::assertFileExists($base . '/' . $pageType . '/default.phtml', $pageType);
        }

        self::assertFileExists(
            $this->root() . '/app/code/Weline/Theme/view/theme/backend/layouts/dashboard/default.phtml',
            ThemeLayout::PAGE_TYPE_DASHBOARD
        );
    }

    public function testNewCanonicalSkeletonsAreContentNeutralAndSlotDriven(): void
    {
        foreach ([
            ThemeLayout::PAGE_TYPE_PROMOTION,
            ThemeLayout::PAGE_TYPE_QA,
            ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE,
        ] as $pageType) {
            $path = $this->root()
                . '/app/code/Weline/Theme/view/theme/frontend/layouts/'
                . $pageType
                . '/default.phtml';
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
            ThemeLayout::PAGE_TYPE_CHECKOUT_FAILURE => 'storefront-checkout-failure-page',
        ];

        foreach ($expectedFallbacks as $pageType => $testId) {
            $source = (string)\file_get_contents(
                $this->root()
                . '/app/code/Weline/Theme/view/theme/frontend/layouts/'
                . $pageType
                . '/default.phtml'
            );

            self::assertStringContainsString('data-testid="' . $testId . '"', $source, $pageType);
            self::assertStringContainsString('<?php else: ?>', $source, $pageType);
            self::assertStringContainsString('$renderedTemplateContent', $source, $pageType);
            self::assertStringContainsString("trim(\$renderedTemplateContent) !== ''", $source, $pageType);
        }
    }

    public function testCanonicalFailureRouteAndPublicLayoutAllowlistStayAligned(): void
    {
        $router = (string)\file_get_contents(
            $this->root() . '/app/code/Weline/Theme/Controller/Router.php'
        );
        self::assertStringContainsString(
            "'checkout/failure' => ['layout_type' => 'checkout_failure'",
            $router
        );
        self::assertStringContainsString(
            "'checkout/failer' => ['layout_type' => 'checkout_failer'",
            $router
        );

        $policy = (string)\file_get_contents(
            $this->root() . '/app/code/Weline/Theme/Controller/Frontend/Policy.php'
        );
        foreach (['checkout_failure', 'promotion', 'qa', 'payment_guide', 'not_found'] as $pageType) {
            self::assertStringContainsString("'{$pageType}' => ['default']", $policy, $pageType);
        }
    }

    public function testLayoutCatalogNamesTheCanonicalMatrix(): void
    {
        $catalog = (string)\file_get_contents(
            $this->root() . '/app/code/Weline/Theme/Service/LayoutDataService.php'
        );

        foreach (['checkout_failure', 'promotion', 'qa', 'payment_guide', 'guide', 'terms'] as $pageType) {
            self::assertStringContainsString("'{$pageType}' =>", $catalog, $pageType);
        }
    }

    private function root(): string
    {
        return \dirname(__DIR__, 7);
    }
}
