<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class RecommendedProductsEmptyShellContractTest extends TestCase
{
    public function testRecommendedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/recommended-products.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('if ($products === []) {', $source);
        self::assertStringContainsString('data-testid="recommended-products-empty"', $source);
        self::assertStringContainsString('hidden', $source);
        self::assertStringContainsString('aria-hidden="true"', $source);
        self::assertStringNotContainsString(
            "if (\$products === []) {\n    return;\n}",
            $source,
        );
    }

    public function testRelatedProductsEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/related-products.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('data-testid="related-products-empty"', $source);
        self::assertStringContainsString('hidden', $source);
        self::assertStringContainsString('aria-hidden="true"', $source);
    }
}
