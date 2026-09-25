<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SSR-slim cart/checkout must heal empty footer--shell from chrome.rendered disk only.
 */
final class StorefrontSsrChromeHealerContractTest extends TestCase
{
    public function testHealerAndDiskSpliceExistWithoutLiveFill(): void
    {
        $healer = dirname(__DIR__, 3) . '/Service/StorefrontSsrChromeHealer.php';
        $filler = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php';
        self::assertFileExists($healer);
        $healerSrc = (string)file_get_contents($healer);
        self::assertStringContainsString('splicePublishedChromeFromDisk', $healerSrc);
        self::assertStringContainsString('shellNeedsRuntimeSafetyNetFill', $healerSrc);
        self::assertStringContainsString('SlotBoundaryMarkers::strip', $healerSrc);
        self::assertStringNotContainsString('->fill(', $healerSrc);

        $fillerSrc = (string)file_get_contents($filler);
        self::assertStringContainsString('function splicePublishedChromeFromDisk', $fillerSrc);
        $pos = strpos($fillerSrc, 'function splicePublishedChromeFromDisk');
        self::assertNotFalse($pos);
        $body = substr($fillerSrc, (int)$pos, 1200);
        self::assertStringContainsString('chromeSlotProjectionFromRenderedSnapshot', $body);
        self::assertStringContainsString('spliceChromeSlotsFromBake', $body);
        self::assertStringContainsString('chrome.rendered.v2.', $fillerSrc);
        self::assertStringNotContainsString('$this->fill(', $body);
    }
}
