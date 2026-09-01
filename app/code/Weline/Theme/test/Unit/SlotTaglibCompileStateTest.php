<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
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
        $this->assertStringContainsString('<!--@weline-slot:widget-hero-->', $firstResult);
        $this->assertStringContainsString('<!--@/weline-slot:widget-hero-->', $firstResult);
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

    public function testRuntimeSlotTagClosesWrapperBeforeFollowingMarkup(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $rendered = $taglib->renderRuntimeTag(
            $template,
            'w:slot',
            'tag-start',
            [
                'id' => 'product-purchase-actions',
                'wrapper' => 'div',
                'class' => 'actions',
            ],
            '<span>Preview</span>',
            'slot-runtime-close.phtml',
            ' id="product-purchase-actions" wrapper="div" class="actions"',
            '',
        ) . '<p class="after-slot">After</p>';

        $this->assertStringContainsString('data-wslot="product-purchase-actions"', $rendered);
        $this->assertStringContainsString('<span>Preview</span>', $rendered);
        $this->assertStringContainsString('<p class="after-slot">After</p>', $rendered);
        $this->assertMatchesRegularExpression(
            '/<!--@weline-slot:product-purchase-actions-->.*?data-wslot="product-purchase-actions"[^>]*>\s*<span>Preview<\/span>\s*<\/div>\s*<!--@\/weline-slot:product-purchase-actions-->\s*<p class="after-slot">After<\/p>/s',
            $rendered,
        );
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

    public function testRuntimeSlotRegistryCanResetBetweenWidgetRenders(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $attrs = [
            'id' => 'product-purchase-actions',
            'wrapper' => 'div',
            'class' => 'actions',
            'multiple' => 'true',
        ];
        $raw = ' id="product-purchase-actions" wrapper="div" class="actions" multiple="true"';

        $first = $taglib->renderRuntimeTag(
            $template,
            'w:slot',
            'tag-start',
            $attrs,
            '<span>One</span>',
            'product-info-first.phtml',
            $raw,
            '',
        );
        $this->assertStringContainsString('data-wslot="product-purchase-actions"', $first);

        Slot::clearRegisteredSlots();

        $second = $taglib->renderRuntimeTag(
            $template,
            'w:slot',
            'tag-start',
            $attrs,
            '<span>Two</span>',
            'product-info-second.phtml',
            $raw,
            '',
        );
        $this->assertStringContainsString('data-wslot="product-purchase-actions"', $second);
        $this->assertStringContainsString('<span>Two</span>', $second);
    }

    public function testRuntimeDuplicateSlotWithoutResetStillThrows(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $attrs = [
            'id' => 'product-purchase-actions',
            'wrapper' => 'div',
            'class' => 'actions',
        ];
        $raw = ' id="product-purchase-actions" wrapper="div" class="actions"';

        $taglib->renderRuntimeTag(
            $template,
            'w:slot',
            'tag-start',
            $attrs,
            '<span>One</span>',
            'product-info-a.phtml',
            $raw,
            '',
        );

        $this->expectException(TemplateException::class);
        $taglib->renderRuntimeTag(
            $template,
            'w:slot',
            'tag-start',
            $attrs,
            '<span>Two</span>',
            'product-info-b.phtml',
            $raw,
            '',
        );
    }
}
