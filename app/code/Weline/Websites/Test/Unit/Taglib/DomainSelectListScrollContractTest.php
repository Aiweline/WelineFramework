<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * Floating dropdown must constrain the list scroller, not clip it at panel level.
 */
final class DomainSelectListScrollContractTest extends TestCase
{
    public function testDomainSelectUsesScrollContainerAndWheelHandlerForList(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/DomainSelect.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('function ensureListScrollable()', $source);
        self::assertStringContainsString('function resolveListBudget()', $source);
        self::assertStringContainsString('function measureDropdownChrome()', $source);
        self::assertStringContainsString('dropdown.style.maxHeight = "none"', $source);
        self::assertStringContainsString('preferredHeight: panelHeight', $source);
        self::assertStringContainsString('weline-domain-select-actions', $source);
        self::assertStringContainsString('function onDropdownWheel(e)', $source);
        self::assertStringContainsString('listScroller.scrollTop = next', $source);
        self::assertStringContainsString('requestAnimationFrame(function(){ ensureListScrollable(); })', $source);
        self::assertStringContainsString('touch-action: pan-y', $source);
        self::assertStringContainsString('min-height: 0', $source);
    }
}
