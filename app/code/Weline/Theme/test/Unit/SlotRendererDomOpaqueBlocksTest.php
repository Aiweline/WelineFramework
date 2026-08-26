<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use ReflectionMethod;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Service\SlotRendererService;

final class SlotRendererDomOpaqueBlocksTest extends TestCore
{
    public function testDomDocumentInjectionDoesNotLeakScriptWithLessThan(): void
    {
        $service = $this->getMockBuilder(SlotRendererService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $html = <<<'HTML'
<div data-wslot="hero" class="slot">DEFAULT</div>
HTML;
        $widget = <<<'HTML'
<section class="wc"><span>Visible</span>
<style>.wc .x { color: red; }</style>
<script>
(function() {
    const a = 1;
    const b = 2;
    if (a < b) { window.__ok = true; }
    root.innerHTML = "<div class=\"x\">y</div>";
})();
</script>
</section>
HTML;

        $process = new ReflectionMethod(SlotRendererService::class, 'processSlotFragmentWithDom');
        $process->setAccessible(true);

        // Inject by swapping processSlotElement behavior via a thin subclass path:
        // call park/restore helpers directly around the same loadHTML pattern used in production.
        $park = new ReflectionMethod(SlotRendererService::class, 'parkDomOpaqueBlocks');
        $park->setAccessible(true);
        $restore = new ReflectionMethod(SlotRendererService::class, 'restoreDomOpaqueBlocks');
        $restore->setAccessible(true);

        $parked = $park->invoke($service, $widget);
        self::assertStringNotContainsString('if (a < b)', $parked);
        self::assertStringContainsString('WELINE_DOM_OPAQUE_', $parked);

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div data-weline-slot-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        $xpath = new \DOMXPath($doc);
        $slot = $xpath->query('//*[@data-wslot]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $slot);
        while ($slot->firstChild) {
            $slot->removeChild($slot->firstChild);
        }
        $fragment = $doc->createDocumentFragment();
        $temp = new \DOMDocument();
        $temp->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $parked . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        $body = $temp->getElementsByTagName('div')->item(0);
        self::assertNotNull($body);
        foreach ($body->childNodes as $child) {
            $fragment->appendChild($doc->importNode($child, true));
        }
        $slot->appendChild($fragment);
        $out = '';
        $root = $doc->documentElement;
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        libxml_clear_errors();
        $out = $restore->invoke($service, $out);

        self::assertStringContainsString('Visible', $out);
        self::assertStringContainsString('if (a < b)', $out);
        self::assertStringContainsString('<script>', $out);
        self::assertStringContainsString('</script>', $out);
        self::assertStringContainsString('<style>', $out);

        $visible = new \DOMDocument();
        libxml_use_internal_errors(true);
        $visible->loadHTML($out);
        libxml_clear_errors();
        $xp = new \DOMXPath($visible);
        $leaked = [];
        foreach ($xp->query('//text()[not(ancestor::script) and not(ancestor::style)]') as $text) {
            $value = trim((string)$text->textContent);
            if ($value !== '' && (str_contains($value, 'function') || str_contains($value, 'if (a'))) {
                $leaked[] = $value;
            }
        }
        self::assertSame([], $leaked, 'JS must not leak into visible text nodes');
    }
}
