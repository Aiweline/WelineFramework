<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Category PLP mobile: filter sidebar becomes a drawer, but must not leave a
 * 220px empty grid track that squeezes the product column.
 */
final class CategoryLayoutMobileFilterGridContractTest extends TestCase
{
    public function testMobileMediaQueryResetsWithFiltersSpecificity(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/category/default.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);

        self::assertMatchesRegularExpression(
            '/\.category-layout__container--with-filters\s*,\s*'
            . '\.category-layout__container:has\(\s*>\s*\.category-layout__sidebar\s*\)\s*\{'
            . '[\s\S]*?grid-template-columns:\s*220px\s+minmax\(0,\s*1fr\)/i',
            $src,
            'Desktop filter layout must keep sidebar + main columns'
        );

        self::assertMatchesRegularExpression(
            '/@media\s+screen\s+and\s*\(\s*max-width:\s*900px\s*\)\s*\{[\s\S]*?'
            . '\.category-layout__container--with-filters\s*,[\s\S]*?'
            . '\.category-layout__container:has\(\s*>\s*\.category-layout__sidebar\s*\)\s*\{[\s\S]*?'
            . 'grid-template-columns:\s*minmax\(0,\s*1fr\)/i',
            $src,
            'Mobile must override --with-filters/:has(sidebar) at equal-or-higher specificity'
        );
    }
}
