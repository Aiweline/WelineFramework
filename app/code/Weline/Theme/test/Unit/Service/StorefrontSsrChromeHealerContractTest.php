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
        // 2026-09-26 用户纠偏（严格档「有固化就完全不注」）：
        // 已装载页面固化产物 ⇒ 运行时不得补跑 required overlay（禁查部件声明）。
        self::assertStringContainsString('publishedSolidifiedArtifactLoaded', $healerSrc);
        $gatePos = \strpos($healerSrc, 'publishedSolidifiedArtifactLoaded');
        self::assertNotFalse($gatePos);
        $fillPos = \strpos($healerSrc, '$this->fillRequiredPageDefaults($html)');
        self::assertNotFalse($fillPos);
        self::assertLessThan($fillPos, $gatePos, 'strict gate must precede required overlay');

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
