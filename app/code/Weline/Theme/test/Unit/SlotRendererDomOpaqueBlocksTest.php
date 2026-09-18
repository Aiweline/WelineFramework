<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use ReflectionMethod;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\WidgetHtmlHealthInspector;

/**
 * Remaining Dom opaque park/restore is only for stampFinal health inspect round-trips.
 * Slot fill no longer uses DOMDocument injection.
 */
final class SlotRendererDomOpaqueBlocksTest extends TestCore
{
    public function testParkRestoreDoesNotLeakScriptWithLessThan(): void
    {
        $service = $this->getMockBuilder(SlotRendererService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

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
            '<?xml encoding="UTF-8"><div data-weline-slot-root="1">' . $parked . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
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

    public function testFindMatchingDivCloseIgnoresDivTokensInsideComments(): void
    {
        $service = $this->getMockBuilder(SlotRendererService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $method = new ReflectionMethod(SlotRendererService::class, 'findMatchingDivClose');
        $method->setAccessible(true);

        $inner = '<footer id="footer">'
            . '<div class="footer-nav"><div class="footer-container">'
            . '<!-- repair marker </div> should not close wrapper early -->'
            . '<div class="footer-content"><span>ok</span></div>'
            . '</div></div></footer>'
            . '<style>.footer-content { display: grid; }</style>';
        $html = '<div class="widget-wrapper" data-widget-code="footer-container">' . $inner . '</div>';
        $openEnd = strpos($html, '>') + 1;

        $closeAt = $method->invoke($service, $html, $openEnd);
        self::assertNotNull($closeAt);
        self::assertSame($inner, substr($html, $openEnd, $closeAt - $openEnd));

        $inspector = new WidgetHtmlHealthInspector();
        $issues = $inspector->inspect($inner, ['code' => 'footer-container', 'slot_id' => 'footer']);
        self::assertSame([], $issues, json_encode($issues, JSON_UNESCAPED_UNICODE));
    }

    public function testFindMatchingDivCloseIgnoresDivTokensInsideScriptAndStyle(): void
    {
        $service = $this->getMockBuilder(SlotRendererService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $method = new ReflectionMethod(SlotRendererService::class, 'findMatchingDivClose');
        $method->setAccessible(true);

        $inner = '<div class="root">'
            . '<script>var html = "</div><div class=fake>";</script>'
            . '<style>.x::before { content: "</div>"; }</style>'
            . '<span>ok</span>'
            . '</div>';
        $html = '<div class="widget-wrapper" data-widget-code="header-policy-links">' . $inner . '</div>';
        $openEnd = strpos($html, '>') + 1;

        $closeAt = $method->invoke($service, $html, $openEnd);
        self::assertNotNull($closeAt);
        self::assertSame($inner, substr($html, $openEnd, $closeAt - $openEnd));
    }
}
