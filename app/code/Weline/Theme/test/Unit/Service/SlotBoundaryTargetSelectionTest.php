<?php

declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\SlotBoundaryScanner;

final class SlotBoundaryTargetSelectionTest extends TestCase
{
    private function slot(string $id, string $inner): string
    {
        return '<!--@weline-slot:' . $id . '--><section data-wslot="' . $id . '">'
            . $inner . '</section><!--@/weline-slot:' . $id . '-->';
    }

    public function testPendingChildrenKeepOriginalDepthAndByteOffsetsWithoutHydratingTheirParent(): void
    {
        $html = $this->slot('content', '<p>中文</p>' . $this->slot('child', '<b>old</b>'))
            . $this->slot('unused', str_repeat('<div>large body</div>', 1000))
            . $this->slot('footer', 'footer body');
        $scanner = new SlotBoundaryScanner();
        $regions = $scanner->enumerateRegions($html, null, ['child'=>true, 'footer'=>true]);
        self::assertSame(['child', 'footer'], array_column($regions, 'id'));
        self::assertSame([1, 0], array_column($regions, 'depth'));
        self::assertSame('<b>old</b>', substr($html, $regions[0]['inner_start'], $regions[0]['inner_end'] - $regions[0]['inner_start']));
        $changed = $scanner->replaceWrapperInner($html, $regions[0], 'child replaced');
        self::assertStringContainsString('child replaced</section><!--@/weline-slot:child--></section>', $changed);
        self::assertStringEndsWith($this->slot('footer', 'footer body'), $changed);
    }

    public function testDuplicateIdsOutsideTargetsRetainTheCompleteLegacyBatchInput(): void
    {
        $html = $this->slot('unused', 'first') . $this->slot('left', 'L')
            . $this->slot('unused', 'second') . $this->slot('right', 'R');
        $scanner = new SlotBoundaryScanner();
        self::assertSame($scanner->enumerateRegions($html), $scanner->enumerateRegions($html, null, ['left'=>true, 'right'=>true]));
    }

    public function testMalformedMarkerPairingStillUsesTheOriginalRecovery(): void
    {
        $html = '<!--@weline-slot:outer--><section data-wslot="outer">'
            . '<!--@weline-slot:inner--><div data-wslot="inner">I</div>'
            . '</section><!--@/weline-slot:outer--><!--@/weline-slot:inner-->'
            . $this->slot('footer', 'F');
        $scanner = new SlotBoundaryScanner();
        self::assertSame($scanner->enumerateRegions($html), $scanner->enumerateRegions($html, null, ['footer'=>true]));
    }

    public function testUnconfiguredRegionIsNotReturnedAndSingleSlotApiRemainsExact(): void
    {
        $html = $this->slot('header', 'H') . $this->slot('footer', 'F');
        $scanner = new SlotBoundaryScanner();
        self::assertSame([], $scanner->enumerateRegions($html, null, ['absent'=>true]));
        self::assertSame(['footer'], array_column($scanner->enumerateRegions($html, 'footer'), 'id'));
    }
}
