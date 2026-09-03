<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * MCP_TARGET_UNAVAILABLE: sealed create of this new path was not materializable;
 * native exact-path create after apply_compact_edit on existing targets.
 */
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
