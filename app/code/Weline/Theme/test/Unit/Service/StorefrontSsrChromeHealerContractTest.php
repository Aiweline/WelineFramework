<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * W4: SSR-slim Healer must NOT depend on chrome.rendered locale snapshots.
 * Required page slots: presence gate must accept reactive data-wslot (Overlay-aligned).
 */
final class StorefrontSsrChromeHealerContractTest extends TestCase
{
    public function testHealerDoesNotDependOnChromeRenderedDiskSplice(): void
    {
        $healer = dirname(__DIR__, 3) . '/Service/StorefrontSsrChromeHealer.php';
        $filler = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php';
        $markers = dirname(__DIR__, 3) . '/Service/SlotBoundaryMarkers.php';
        self::assertFileExists($healer);
        $healerSrc = (string)file_get_contents($healer);
        self::assertStringNotContainsString('splicePublishedChromeFromDisk', $healerSrc);
        self::assertStringNotContainsString('chrome.rendered.', $healerSrc);
        self::assertStringContainsString('healPublishedPlaceholderShell', $healerSrc);
        self::assertStringContainsString('shellNeedsRuntimeSafetyNetFill', $healerSrc);
        self::assertStringContainsString('ThemeContextService', $healerSrc);
        self::assertStringContainsString('resolveTheme', $healerSrc);
        self::assertStringContainsString('SlotBoundaryMarkers::strip', $healerSrc);
        self::assertStringContainsString('htmlHasInjectableSlotDestinations', $healerSrc);
        self::assertStringContainsString('solidifyMissingPublishedPageIfNeeded', $healerSrc);
        self::assertStringNotContainsString('->fill(', $healerSrc);
        self::assertStringContainsString('publishedSolidifiedArtifactLoaded', $healerSrc);
        $gatePos = \strpos($healerSrc, 'publishedSolidifiedArtifactLoaded');
        self::assertNotFalse($gatePos);
        $fillPos = \strpos($healerSrc, '$this->fillRequiredPageDefaults($html)');
        self::assertNotFalse($fillPos);
        self::assertLessThan($fillPos, $gatePos, 'strict gate must precede required overlay');
        // W3 solidified controller template flag remains.
        self::assertStringContainsString('solidifiedControllerTemplateSelected', $healerSrc);

        $markerSrc = (string)file_get_contents($markers);
        self::assertStringContainsString('function htmlHasInjectableSlotDestinations', $markerSrc);
        self::assertStringContainsString('data-wslot=', $markerSrc);

        $fillerSrc = (string)file_get_contents($filler);
        self::assertStringContainsString('function splicePublishedChromeFromDisk', $fillerSrc);
        $pos = strpos($fillerSrc, 'function splicePublishedChromeFromDisk');
        self::assertNotFalse($pos);
        $doc = substr($fillerSrc, max(0, (int)$pos - 280), 280);
        self::assertStringContainsString('deprecated no-op', $doc);
        $body = substr($fillerSrc, (int)$pos, 400);
        self::assertStringContainsString('return $html;', $body);
        self::assertStringNotContainsString('chromeSlotProjectionFromRenderedSnapshot', $body);
        self::assertStringContainsString('function solidifyMissingPublishedPageIfNeeded', $fillerSrc);

        $projPos = strpos($fillerSrc, 'function chromeSlotProjectionFromRenderedSnapshot');
        self::assertNotFalse($projPos);
        $projBody = substr($fillerSrc, (int)$projPos, 350);
        self::assertStringContainsString('return [];', $projBody);
    }

    public function testPresenceGateAcceptsReactiveDataWslotOnlyShell(): void
    {
        require_once dirname(__DIR__, 3) . '/Service/SlotBoundaryMarkers.php';
        $reactive = '<div data-wslot="checkout-shipping-address" class="x"></div>';
        self::assertTrue(
            \Weline\Theme\Service\SlotBoundaryMarkers::htmlHasInjectableSlotDestinations($reactive)
        );
        self::assertFalse(
            \Weline\Theme\Service\SlotBoundaryMarkers::htmlHasInjectableSlotDestinations('<p>no slots</p>')
        );
    }
}
