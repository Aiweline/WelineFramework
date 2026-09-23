<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * wave8-8s2: PublishedSlotHost must prime fragments before w:slot and must not
 * sticky-cache reactive=true when theme_id/layout_type are not ready.
 */
final class PublishedSlotHostPrimeContractTest extends TestCase
{
    public function testHostExposesPrimeStorefrontAndAvoidsStickyNotReadyMiss(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPublishedSlotHost.php'
        );

        self::assertStringContainsString('function primeStorefront', $src);
        self::assertStringContainsString('Clear sticky negatives', $src);
        self::assertStringContainsString('Not ready: do NOT sticky-cache', $src);
        self::assertStringContainsString('DO NOT sticky-cache this decision', $src);
        self::assertStringContainsString('resolvePageTypeForFragments', $src);
        self::assertStringContainsString('renderPublishedSolidifiedFragments', $src);
        // Must not set CTX_FRAGMENTS=false merely because theme_id is missing.
        self::assertMatchesRegularExpression(
            '/if \(\$themeId < 1\) \{\s*return null;/s',
            $src,
        );
    }

    public function testFetchFileBeforePrimesHostBeforeSlots(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/ControllerFetchFileBefore.php'
        );

        self::assertStringContainsString('primeStorefront(', $src);
        self::assertStringContainsString('prime PublishedSlotHost BEFORE any layout/partial w:slot', $src);
        self::assertGreaterThanOrEqual(2, \substr_count($src, 'primeStorefront('));
    }

    public function testLayoutSlotRendererEarlyReturnsWithoutReactiveMarkers(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('shouldForcePublishedZeroRuntimeFill', $src);
        self::assertStringContainsString('zero-runtime-fill', $src);
        self::assertStringContainsString("strpos(\$html, 'data-wslot')", $src);
        self::assertStringContainsString('!$hasSlotMarkers && !$isEditorOrPreview', $src);
    }
}
