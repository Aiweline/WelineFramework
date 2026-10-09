<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trust badge grids must reset .columns-N on mobile so 3 cards do not form a 2+1 L.
 */
final class TrustBadgesMobileGridContractTest extends TestCase
{
    public function testMobileBreakpointsOverrideColumnsClasses(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/css/widgets/widget-content-trust-badges-default.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*768px\s*\)\s*\{[\s\S]*?'
            . '\.badges-grid\.columns-3[\s\S]*?grid-template-columns:/i',
            $css,
            '768px must override .columns-3'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*768px\s*\)\s*\{[\s\S]*?'
            . 'style-card[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/i',
            $css,
            'Card-style badges stack to one column by tablet width'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(\s*max-width:\s*480px\s*\)\s*\{[\s\S]*?'
            . '\.badges-grid\.columns-3[\s\S]*?grid-template-columns:\s*minmax\(0,\s*1fr\)/i',
            $css,
            '480px must force single column including .columns-3'
        );
    }
}
