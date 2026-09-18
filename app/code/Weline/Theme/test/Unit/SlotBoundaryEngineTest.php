<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\SlotHtmlOpaqueParker;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Taglib\Slot;

final class SlotBoundaryEngineTest extends TestCore
{
    public function testSlotCompileEmitsBoundaryComments(): void
    {
        Slot::clearRegisteredSlots();
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $content = '<w:slot id="category-grid" name="Categories"><section>Default</section></w:slot>';

        $result = $taglib->compile(
            $template,
            $content,
            'slot-boundary-' . uniqid('', true) . '.phtml',
        );

        $this->assertStringContainsString('<!--@weline-slot:category-grid-->', $result);
        $this->assertStringContainsString('<!--@/weline-slot:category-grid-->', $result);
        $this->assertStringContainsString('data-wslot="category-grid"', $result);
    }

    public function testNestedBoundaryRegionsSortDeepestFirst(): void
    {
        $html = <<<'HTML'
<!--@weline-slot:header-->
<section data-wslot="header">
<!--@weline-slot:logo-->
<div data-wslot="logo">L</div>
<!--@/weline-slot:logo-->
</section>
<!--@/weline-slot:header-->
HTML;

        $scanner = new SlotBoundaryScanner();
        $regions = $scanner->enumerateRegions($html);

        $this->assertCount(2, $regions);
        $this->assertSame('logo', $regions[0]['id']);
        $this->assertSame('header', $regions[1]['id']);
        $this->assertGreaterThan($regions[1]['depth'], $regions[0]['depth']);
    }

    public function testSiblingBoundaryRegionsCanBeFilteredAfterOneMarkerPairingPass(): void
    {
        $html = SlotBoundaryMarkers::open('header')
            . '<header data-wslot="header">H</header>'
            . SlotBoundaryMarkers::close('header')
            . SlotBoundaryMarkers::open('footer')
            . '<footer data-wslot="footer">F</footer>'
            . SlotBoundaryMarkers::close('footer');

        $scanner = new SlotBoundaryScanner();
        $all = $scanner->enumerateRegions($html);
        $filtered = $scanner->enumerateRegions($html, 'footer');

        $this->assertSame(['header', 'footer'], array_column($all, 'id'));
        $this->assertSame(['footer'], array_column($filtered, 'id'));
        $this->assertSame(
            $all[1]['inner_start'],
            $filtered[0]['inner_start'],
        );
    }

    public function testProdStripRemovesBoundaryCommentsOnly(): void
    {
        $html = <<<'HTML'
<!--@weline-slot:content-->
<div data-wslot="content"><style>.x{}</style><span>ok</span></div>
<!--@/weline-slot:content-->
HTML;

        $stripped = SlotBoundaryMarkers::strip($html);

        $this->assertStringNotContainsString('@weline-slot', $stripped);
        $this->assertStringContainsString('data-wslot="content"', $stripped);
        $this->assertStringContainsString('<style>.x{}</style>', $stripped);
    }

    public function testOpaqueParkerPreservesScriptLessThan(): void
    {
        $parker = new SlotHtmlOpaqueParker();
        $widget = <<<'HTML'
<section><script>if (1 < 2) { window.__ok = true; }</script></section>
HTML;

        $parked = $parker->park($widget);
        $this->assertStringNotContainsString('if (1 < 2)', $parked);
        $restored = $parker->restore($parked);
        $this->assertStringContainsString('if (1 < 2)', $restored);
    }

    public function testMissingBoundaryMarkersDoNotRequireHardThrow(): void
    {
        $service = ObjectManager::getInstance(\Weline\Theme\Service\SlotRendererService::class);
        $method = new \ReflectionMethod(\Weline\Theme\Service\SlotRendererService::class, 'shouldRequireSlotBoundaryMarkers');
        $method->setAccessible(true);

        $requires = $method->invoke(
            $service,
            '<div data-wslot="content">Default</div>',
            ['content' => [['widget_code' => 'hero']]],
        );
        $this->assertTrue($requires);
        $this->assertFalse(SlotBoundaryMarkers::hasMarkers('<div data-wslot="content">Default</div>'));
    }

    public function testDistinctSiblingBoundaryRegionsBatchInDescendingOffsetOrder(): void
    {
        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SlotRendererService::class, 'selectBoundaryBatches');
        $method->setAccessible(true);
        $regions = [
            ['id' => 'left', 'depth' => 1, 'region_start' => 10],
            ['id' => 'right', 'depth' => 1, 'region_start' => 100],
        ];

        $batch = $method->invoke($service, $regions, [
            'left' => [['widget_code' => 'left']],
            'right' => [['widget_code' => 'right']],
        ], []);

        $this->assertSame(['right', 'left'], array_column($batch[0] ?? [], 'id'));

        $duplicateBatch = $method->invoke($service, [
            ['id' => 'same', 'depth' => 1, 'region_start' => 10],
            ['id' => 'same', 'depth' => 1, 'region_start' => 100],
        ], ['same' => [['widget_code' => 'same']]], []);
        $this->assertSame([10], array_column($duplicateBatch[0] ?? [], 'region_start'));
    }
}
