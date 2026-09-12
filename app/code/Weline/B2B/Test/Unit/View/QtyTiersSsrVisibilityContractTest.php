<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Retail SSR must still emit qty-tiers DOM (hidden) so selling-mode.js can unhide on tob switch.
 */
final class QtyTiersSsrVisibilityContractTest extends TestCase
{
    public function testQtyTiersDoesNotEarlyReturnOnNonTobCookie(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/partials/qty-tiers.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('data-b2b-qty-tiers="1"', $src);
        self::assertStringContainsString('hidden', $src);
        // Must not blank-return solely because cookie is not tob.
        self::assertStringNotContainsString(
            "if (\$mode !== 'tob') {\n    return;\n}",
            $src
        );
        self::assertStringContainsString("\$mode !== 'tob'", $src);
    }

    public function testSellingModeJsTogglesQtyTiersHidden(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/selling-mode.js';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("tiers.hidden = mode !== 'tob'", $src);
        self::assertStringContainsString('data-b2b-qty-tiers', $src);
    }
}
