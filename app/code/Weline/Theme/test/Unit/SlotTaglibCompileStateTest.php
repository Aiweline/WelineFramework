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

    public function testSlotTagUsesBodyAsDefaultContent(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $content = '<w:slot id="widget-hero" name="Hero"><section>Default Hero</section></w:slot>';

        $result = $taglib->compile($template, $content, 'slot-body-default-' . uniqid('', true) . '.phtml');

        $this->assertStringContainsString('data-wslot="widget-hero"', $result);
        $this->assertStringContainsString('<section>Default Hero</section>', $result);
        $this->assertStringNotContainsString('<else', $result);
    }

    public function testHookElseInsideSlotRemainsHookFallback(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $content = '<w:slot id="widget-hero" name="Hero"><w:hook>Missing_Module::frontend::slot-test::content<else/><span>Hook fallback</span></w:hook></w:slot>';

        $result = $taglib->compile($template, $content, 'slot-hook-else-fallback-' . uniqid('', true) . '.phtml');

        $this->assertStringContainsString('data-wslot="widget-hero"', $result);
        $this->assertStringContainsString('<span>Hook fallback</span>', $result);
        $this->assertStringNotContainsString('Missing_Module::frontend::slot-test::content', $result);
        $this->assertStringNotContainsString('<else', $result);
    }

    public function testIfElseInsideSlotIsNotTreatedAsSlotFallback(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $content = '<w:slot id="widget-hero" name="Hero"><if condition="meta.enabled"><span>Enabled</span><else/><span>Disabled</span></if></w:slot>';

        $result = $taglib->compile($template, $content, 'slot-nested-if-else-' . uniqid('', true) . '.phtml');

        $this->assertStringContainsString('data-wslot="widget-hero"', $result);
        $this->assertStringContainsString('<span>Enabled</span>', $result);
        $this->assertStringContainsString('<span>Disabled</span>', $result);
        $this->assertStringContainsString('<?php else:', $result);
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
