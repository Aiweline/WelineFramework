<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * PDP description imgs use data-src placeholders; reveal JS must hydrate them
 * via media wrappers + floor arm/show — never skip observeDescImages.
 */
final class ProductDetailRevealLazyHydrateContractTest extends TestCase
{
    public function testRevealScriptHydratesDescImagesOnArmShowAndWrapperIo(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/statics/js/widgets/product-detail-reveal.js';
        self::assertFileExists($path);
        $js = (string)\file_get_contents($path);

        self::assertStringContainsString('function hydrateDescImagesIn(root)', $js);
        self::assertStringContainsString('function observeDescImages(body)', $js);
        self::assertStringContainsString('MEDIA_WRAP_SEL', $js);
        self::assertStringContainsString('hydrateDescImagesIn(el);', $js);
        self::assertStringContainsString('threshold: 0.01', $js);
        self::assertStringContainsString('function hasBeenReached(el)', $js);
        self::assertStringContainsString('if (hasBeenReached(el))', $js);
        // Preload armIo callback must not add is-armed (opacity:0 stuck blank).
        $armIoPos = \strpos($js, 'var armIo = new IntersectionObserver');
        $showIoPos = \strpos($js, 'var showIo = new IntersectionObserver');
        self::assertNotFalse($armIoPos);
        self::assertNotFalse($showIoPos);
        self::assertLessThan($showIoPos, $armIoPos);
        $armIoBlock = \substr($js, $armIoPos, $showIoPos - $armIoPos);
        self::assertStringNotContainsString(
            "classList.add('is-armed')",
            $armIoBlock,
            'armIo must preload only — never add is-armed',
        );
        // Image hydrate is armed before floor pending/animation branches.
        $observePos = \strpos($js, 'function observe(body, list)');
        self::assertNotFalse($observePos);
        $hydratePos = \strpos($js, 'observeDescImages(body);', $observePos);
        $pendingPos = \strpos($js, 'if (!pending.length)', $observePos);
        self::assertNotFalse($hydratePos);
        self::assertNotFalse($pendingPos);
        self::assertLessThan(
            $pendingPos,
            $hydratePos,
            'observeDescImages must run before pending-empty early return',
        );
        // Scrolled-past imgs must hydrate (rect.top check only — no bottom > -280 gate).
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*rect\.top\s*<\s*vh\s*\+\s*280\s*\)\s*\{/',
            $js,
            'desc-image near/passed hydrate must use rect.top < vh+280 only',
        );
    }
}
