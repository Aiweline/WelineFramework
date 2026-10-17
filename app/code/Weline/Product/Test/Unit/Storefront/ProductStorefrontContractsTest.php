<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Product\Controller\Frontend\NewArrivals;
use Weline\Product\Controller\Router;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductStorefrontContractsPass().
 */
final class ProductStorefrontContractsTest extends TestCase
{
    public function testAllProductStorefrontContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'NewArrivalsPage::testRouterAliasesResolveToDedicatedController' => function () {
            $this->assert_NewArrivalsPage_testRouterAliasesResolveToDedicatedController();
        },
            'NewArrivalsPage::testLocalizedSurfaceCarriesInternationalSeoContract' => function () {
            $this->assert_NewArrivalsPage_testLocalizedSurfaceCarriesInternationalSeoContract();
        },
            'NewArrivalsPage::testControllerUsesPresentationNotWebsiteCodeBranches' => function () {
            $this->assert_NewArrivalsPage_testControllerUsesPresentationNotWebsiteCodeBranches();
        },
            'NewArrivalsPage::testProductCardsUseUrlHelperForWebsiteMountNotPrefixSplice' => function () {
            $this->assert_NewArrivalsPage_testProductCardsUseUrlHelperForWebsiteMountNotPrefixSplice();
        },
            'NewArrivalsPage::testControllerAndTemplateUseRealCatalogQueryAndStableFallback' => function () {
            $this->assert_NewArrivalsPage_testControllerAndTemplateUseRealCatalogQueryAndStableFallback();
        },
        ];
    }

private function assert_NewArrivalsPage_testRouterAliasesResolveToDedicatedController(): void
{
    foreach (['new-arrivals', 'newarrivals', 'new_arrivals'] as $alias) {
        $path = $alias;
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('weline_product/frontend/new-arrivals', $path);
        self::assertSame('Weline_Product', $rule['module'] ?? null);
    }
}

private function assert_NewArrivalsPage_testLocalizedSurfaceCarriesInternationalSeoContract(): void
{
    $resolver = new StorefrontCatalogSurfaceResolver();
    $zh = $resolver->resolveSupported('/new-arrivals', 'zh_Hans_CN', 'hanfu');
    $en = $resolver->resolveSupported('/new-arrivals', 'en_US', 'hanfu');
    $fallback = $resolver->resolveSupported('/new-arrivals', 'ar_SA', 'hanfu');
    self::assertSame('新品上架', $zh['title'] ?? null);
    self::assertSame('New Hanfu Arrivals', $en['seo_title'] ?? null);
    self::assertSame('New Arrivals', $fallback['title'] ?? null);
    self::assertSame('new-arrivals', $en['public_route'] ?? null);
}

private function assert_NewArrivalsPage_testControllerUsesPresentationNotWebsiteCodeBranches(): void
{
    $source = (string) file_get_contents((new ReflectionClass(NewArrivals::class))->getFileName());
    self::assertStringNotContainsString("websiteCode === 'daocharms'", $source);
    self::assertStringContainsString("presentation['card_limit']", $source);
    self::assertStringContainsString('show_filters', $source);
}

private function assert_NewArrivalsPage_testProductCardsUseUrlHelperForWebsiteMountNotPrefixSplice(): void
{
    $catalog = new ReflectionClass(StorefrontProductWidgetCatalog::class);
    $source = file_get_contents($catalog->getFileName());
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $templatePaths = [$moduleRoot . '/view/templates/frontend/widgets/recommended-products.phtml', $moduleRoot . '/view/templates/frontend/widgets/related-products.phtml', $moduleRoot . '/view/templates/frontend/widgets/you-may-like.phtml', $moduleRoot . '/view/templates/frontend/widgets/cross-sell.phtml'];
    self::assertIsString($source);
    self::assertStringContainsString('ProductCardRenderer::buildStorefrontCardHref($productPath)', $source);
    self::assertStringNotContainsString('$route = rtrim(Url::getPrefix(), \'/\') . $productPath;', $source);
    foreach ($templatePaths as $templatePath) {
        self::assertFileExists($templatePath);
        $templateSource = file_get_contents($templatePath);
        self::assertIsString($templateSource);
        self::assertStringContainsString('ProductCardRenderer::buildStorefrontCardHref($route)', $templateSource);
        self::assertStringNotContainsString('Url::getPrefix()', $templateSource);
        self::assertStringNotContainsString('$hasCurrentPrefix = $prefix !== \'\'', $templateSource);
    }
}

private function assert_NewArrivalsPage_testControllerAndTemplateUseRealCatalogQueryAndStableFallback(): void
{
    $controller = new ReflectionClass(NewArrivals::class);
    $catalog = new ReflectionClass(StorefrontProductWidgetCatalog::class);
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Product';
    $controllerSource = file_get_contents($controller->getFileName());
    $catalogLines = file($catalog->getFileName());
    $cardsMethod = $catalog->getMethod('cards');
    $template = $moduleRoot . '/view/templates/frontend/new-arrivals/index.phtml';
    $templateSource = file_get_contents($template);
    self::assertTrue($controller->hasMethod('index'));
    self::assertTrue($catalog->hasMethod('newArrivalCards'));
    self::assertStringContainsString("\$this->layoutType = 'products'", (string) $controllerSource);
    self::assertStringContainsString('newArrivalCards($cardLimit, 3650)', (string) $controllerSource);
    self::assertStringContainsString('cards($cardLimit)', (string) $controllerSource);
    self::assertStringContainsString("presentation['card_limit']", (string) $controllerSource);
    self::assertIsArray($catalogLines);
    $cardsSource = implode('', array_slice($catalogLines, $cardsMethod->getStartLine() - 1, $cardsMethod->getEndLine() - $cardsMethod->getStartLine() + 1));
    self::assertSame(2, substr_count($cardsSource, 'foreach ($offers as $offer)'));
    self::assertStringContainsString('$fallbackProductId', $cardsSource);
    self::assertFileExists($template);
    self::assertStringContainsString('data-testid="storefront-new-arrivals"', (string) $templateSource);
    self::assertStringContainsString('data-testid="storefront-new-arrivals-card"', (string) $templateSource);
}
}
