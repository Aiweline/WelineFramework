<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * deals-of-day countdown must not re-assign hidden every second after expiry
 * (MutationObserver thrash blanks Chrome DevTools Elements on large storefront DOMs).
 */
final class DealsOfDayCountdownMutationIdleContractTest extends TestCase
{
    public function testExpiredCountdownStopsIntervalAndGuardsHiddenWrite(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/widgets/widget-product-deals-of-day-default-0.js';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('stopCountdownTick', $src);
        self::assertStringContainsString('clearInterval(tickId)', $src);
        self::assertStringContainsString('if (!countdown.hidden)', $src);
        self::assertStringContainsString('tickId = setInterval(updateCountdown, 1000)', $src);
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*diff\s*<=\s*0\s*\)\s*\{\s*countdown\.hidden\s*=\s*true;\s*return;/',
            $src,
            'expired branch must not unconditionally re-assign hidden every tick'
        );
    }
}
