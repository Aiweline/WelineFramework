<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Exception\TemplateException;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\Slot;

class SlotTaglibCompileStateTest extends TestCore
{
    public function setUp(): void
    {
        parent::setUp();
        Slot::clearRegisteredSlots();
    }

    public function tearDown(): void
    {
        Slot::clearRegisteredSlots();
        parent::tearDown();
    }

    public function testSlotRegistryResetsBetweenCompileCycles(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $firstContent = '<w:slot id="widget-hero" name="Hero">Hero</w:slot>';
        $secondContent = '<w:slot id="widget-hero" name="Hero">Hero</w:slot>';

        $firstResult = $taglib->compile($template, $firstContent, 'slot-first-cycle.phtml');
        $secondResult = $taglib->compile($template, $secondContent, 'slot-second-cycle.phtml');

        $this->assertStringContainsString('data-wslot="widget-hero"', $firstResult);
        $this->assertStringContainsString('data-wslot="widget-hero"', $secondResult);
        $this->assertSame([], Slot::getRegisteredSlots(), 'Top-level compile should not leak slot state across cycles.');
    }

    public function testDuplicateSlotErrorReportsTemplateSource(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $content = <<<PHTML
<w:slot id="widget-hero" name="Hero">First</w:slot>
<w:slot id="widget-hero" name="Hero">Second</w:slot>
PHTML;

        try {
            $taglib->compile($template, $content, 'slot-duplicate-source.phtml');
            $this->fail('Expected duplicate slot compilation to throw.');
        } catch (TemplateException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('slot-duplicate-source.phtml', $message);
            $this->assertStringNotContainsString('unknown:0', $message);
        }
    }
}
