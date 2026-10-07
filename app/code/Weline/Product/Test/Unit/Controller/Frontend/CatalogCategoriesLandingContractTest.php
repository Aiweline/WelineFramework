<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Root /category|/categories must render a department landing, not the product PLP.
 */
final class CatalogCategoriesLandingContractTest extends TestCase
{
    public function testCatalogBranchesCategoriesSurfaceToLandingTemplate(): void
    {
        $root = dirname(__DIR__, 4);
        $controller = (string)file_get_contents(
            $root . '/Controller/Frontend/Catalog.php'
        );
        $template = (string)file_get_contents(
            $root . '/view/templates/frontend/catalog/categories.phtml'
        );

        self::assertStringContainsString("=== 'categories'", $controller);
        self::assertStringContainsString('renderCategoriesLanding', $controller);
        self::assertStringContainsString('resolveRootLanding', $controller);
        self::assertStringContainsString(
            "Weline_Product::templates/frontend/catalog/categories.phtml",
            $controller
        );
        self::assertStringContainsString("assign('showFilters', false)", $controller);
        self::assertStringNotContainsString(
            'publishedListingCandidates',
            $this->methodBody($controller, 'renderCategoriesLanding')
        );

        self::assertStringContainsString('data-testid="storefront-categories-hub"', $template);
        self::assertStringContainsString('data-testid="storefront-categories-hub-grid"', $template);
        self::assertStringContainsString("@url{'products'}", $template);
        self::assertStringNotContainsString('storefront-product-catalog', $template);
        self::assertStringNotContainsString('ProductCardRenderer', $template);
    }

    public function testCategoryViewServiceExposesRootLanding(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/StorefrontCategoryViewService.php'
        );

        self::assertStringContainsString('function resolveRootLanding(', $source);
        self::assertStringContainsString('StorefrontCategoryPublicFilter::shouldHideFromCustomers', $source);
    }

    private function methodBody(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        $start = strpos($source, $needle);
        self::assertNotFalse($start, $method . ' missing');
        $brace = strpos($source, '{', $start);
        self::assertNotFalse($brace);
        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        self::fail('unclosed method ' . $method);

        return '';
    }
}
