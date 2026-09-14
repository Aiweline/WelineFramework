<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** Cross-sell empty-state shell contract (hidden empty markup when <2 products). */
final class CrossSellEmptyShellContractTest extends TestCase
{
    public function testCrossSellEmitsHiddenEmptyShellInsteadOfBlankHtml(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/cross-sell.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('if (count($products) < 2) {', $source);
        self::assertStringContainsString('data-testid="cross-sell-empty"', $source);
        self::assertStringContainsString('hidden', $source);
        self::assertStringContainsString('aria-hidden="true"', $source);
        self::assertStringNotContainsString(
            "if (count(\$products) < 2) {\n    return;\n}",
            $source,
        );
    }
}
