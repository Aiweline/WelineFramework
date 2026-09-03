<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Product\Controller\Frontend\NewArrivals;
use Weline\Product\Controller\Router;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

final class NewArrivalsPageContractTest extends TestCase
{
    public function testRouterAliasesResolveToDedicatedController(): void
    {
        foreach (['new-arrivals', 'newarrivals', 'new_arrivals'] as $alias) {
            $path = $alias;
            $rule = [];
            Router::process($path, $rule);
            self::assertSame('weline_product/frontend/new-arrivals', $path);
            self::assertSame('Weline_Product', $rule['module'] ?? null);
        }
    }

    public function testLocalizedSurfaceCarriesInternationalSeoContract(): void
    {
        $resolver = new StorefrontCatalogSurfaceResolver();
        $zh = $resolver->resolveSupported('/new-arrivals', 'zh_Hans_CN');
        $en = $resolver->resolveSupported('/new-arrivals', 'en_US');
        $fallback = $resolver->resolveSupported('/new-arrivals', 'ar_SA');

        self::assertSame('新品上架', $zh['title'] ?? null);
        self::assertSame('New Hanfu Arrivals', $en['seo_title'] ?? null);
        self::assertSame('New Arrivals', $fallback['title'] ?? null);
        self::assertSame('new-arrivals', $en['public_route'] ?? null);
    }

    public function testProductCardsPreserveTheCurrentLocalePrefixExactlyOnce(): void
    {
        $catalog = new ReflectionClass(StorefrontProductWidgetCatalog::class);
        $source = file_get_contents($catalog->getFileName());
        $moduleRoot = dirname(__DIR__, 3);
        $templatePaths = [
            $moduleRoot . '/view/templates/frontend/widgets/recommended-products.phtml',
            $moduleRoot . '/view/templates/frontend/widgets/related-products.phtml',
        ];

        self::assertIsString($source);
        self::assertStringContainsString('Url::getPrefix()', $source);
        self::assertStringContainsString(
            '$route = rtrim(Url::getPrefix(), \'/\') . $productPath;',
            $source,
        );

        foreach ($templatePaths as $templatePath) {
            self::assertFileExists($templatePath);
            $templateSource = file_get_contents($templatePath);
            self::assertIsString($templateSource);
            self::assertStringContainsString(
                '$hasCurrentPrefix = $prefix !== \'\'',
                $templateSource,
            );
            self::assertStringContainsString(
                'str_starts_with($path, $prefix . \'/\')',
                $templateSource,
            );
        }
    }


    public function testControllerAndTemplateUseRealCatalogQueryAndStableFallback(): void
    {
        $controller = new ReflectionClass(NewArrivals::class);
        $catalog = new ReflectionClass(StorefrontProductWidgetCatalog::class);
        $moduleRoot = dirname(__DIR__, 3);
        $controllerSource = file_get_contents($controller->getFileName());
        $catalogLines = file($catalog->getFileName());
        $cardsMethod = $catalog->getMethod('cards');
        $template = $moduleRoot . '/view/templates/frontend/new-arrivals/index.phtml';
        $templateSource = file_get_contents($template);

        self::assertTrue($controller->hasMethod('index'));
        self::assertTrue($catalog->hasMethod('newArrivalCards'));
        self::assertStringContainsString("\$this->layoutType = 'product_list'", (string)$controllerSource);
        self::assertStringContainsString('newArrivalCards(24, 3650)', (string)$controllerSource);
        self::assertStringContainsString('cards(24)', (string)$controllerSource);
        self::assertIsArray($catalogLines);
        $cardsSource = implode('', array_slice(
            $catalogLines,
            $cardsMethod->getStartLine() - 1,
            $cardsMethod->getEndLine() - $cardsMethod->getStartLine() + 1,
        ));
        self::assertSame(2, substr_count($cardsSource, 'foreach ($offers as $offer)'));
        self::assertStringContainsString('$fallbackProductId', $cardsSource);
        self::assertFileExists($template);
        self::assertStringContainsString('data-testid="storefront-new-arrivals"', (string)$templateSource);
        self::assertStringContainsString('data-testid="storefront-new-arrivals-card"', (string)$templateSource);
    }
}
