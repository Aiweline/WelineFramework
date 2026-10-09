<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Shelf widgets must use literal px in @media max-width — var() is dropped by engines.
 */
final class RecommendedProductsMobileMediaQueryContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function shelfCssPaths(): array
    {
        $root = dirname(__DIR__, 3) . '/view/statics/css/widgets';

        return [
            $root . '/recommended-products.css',
            $root . '/you-may-like.css',
            $root . '/related-products.css',
        ];
    }

    public function testShelfCssUsesLiteralMobileBreakpointsNotCssVars(): void
    {
        foreach ($this->shelfCssPaths() as $path) {
            self::assertFileExists($path, $path);
            $css = (string)file_get_contents($path);
            self::assertNotSame('', $css, $path);
            self::assertDoesNotMatchRegularExpression(
                '/@media\s*\(\s*max-width:\s*var\(/i',
                $css,
                $path . ' must not use var() inside max-width media queries'
            );
            self::assertMatchesRegularExpression(
                '/@media\s*\(\s*max-width:\s*767px\s*\)/i',
                $css,
                $path . ' must declare a literal 767px mobile breakpoint'
            );
            self::assertMatchesRegularExpression(
                '/@media\s*\(\s*max-width:\s*480px\s*\)/i',
                $css,
                $path . ' must declare a literal 480px single-column breakpoint'
            );
        }
    }
}
