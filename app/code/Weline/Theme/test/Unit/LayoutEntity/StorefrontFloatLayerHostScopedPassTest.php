<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\StorefrontFloatLayerHost;

/**
 * Scoped-fragment pass of ensureFloatEdgeShellsInHtml(): the edge-shell repair
 * must mutate only inside #w-storefront-float-layer and leave the rest of the
 * document byte-identical, instead of re-serialising the whole page through
 * DOMDocument (~4.4ms on a 446KB document).
 */
final class StorefrontFloatLayerHostScopedPassTest extends TestCase
{
    private function doc(string $slotInner, string $before = '<p>payload</p>', string $after = '<footer>f</footer>'): string
    {
        return '<!DOCTYPE html><html lang="pt"><body>'
            . $before
            . '<div id="w-storefront-float-layer">'
            . '<div class="w-storefront-float-layer__slot" data-float-slot="start" data-float-dock="left">'
            . $slotInner
            . '</div></div>'
            . $after
            . '</body></html>';
    }

    public function testScopedPassAdoptsOrphansAndLeavesRestUntouched(): void
    {
        $html = $this->doc('<div data-global-region-async="x">w</div>');
        $out = StorefrontFloatLayerHost::ensureFloatEdgeShellsInHtml($html);

        self::assertStringContainsString('data-float-edge-body', $out);
        self::assertStringContainsString('data-float-edge-dock="1"', $out);
        self::assertStringContainsString('data-global-region-async="x"', $out);
        // Everything outside the layer must survive byte-identical (no full-doc
        // normalisation): DOCTYPE, lang and surrounding markup unchanged.
        self::assertStringStartsWith('<!DOCTYPE html><html lang="pt"><body><p>payload</p>', $out);
        self::assertStringEndsWith('</div><footer>f</footer></body></html>', $out);
    }

    public function testScopedPassOnEmptySlotIsByteIdenticalNoop(): void
    {
        $html = $this->doc('');
        self::assertSame($html, StorefrontFloatLayerHost::ensureFloatEdgeShellsInHtml($html));
    }

    /**
     * A </div> inside a comment or an escaped <script> string in the layer must
     * not truncate the byte scan — content after them still has to be adopted.
     * (libxml itself truncates script CDATA at any literal </div, so the script
     * case uses the conventional <\/ escape that real markup must already use.)
     */
    public function testScriptAndCommentInsideLayerDoNotCutTheSpan(): void
    {
        $html = $this->doc(
            '<!-- edge </div> note -->'
            . '<script>var s = "<\/div>";</script>'
            . '<div data-global-region-async="y">z</div>'
        );
        $out = StorefrontFloatLayerHost::ensureFloatEdgeShellsInHtml($html);

        self::assertStringContainsString('data-float-edge-body', $out);
        self::assertStringContainsString('data-global-region-async="y"', $out);
        self::assertStringContainsString('var s = "<\/div>";', $out);
        self::assertStringEndsWith('</div><footer>f</footer></body></html>', $out);
    }

    /**
     * When the layer id literal is ambiguous (e.g. a JS template string also
     * carries it), the scoped scan must refuse and the full-document path must
     * still repair the real layer.
     */
    public function testAmbiguousLayerIdFallsBackAndStillRepairs(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<script>var tpl = \'<div id="w-storefront-float-layer"></div>\';</script>'
            . '<div id="w-storefront-float-layer">'
            . '<div class="w-storefront-float-layer__slot" data-float-slot="end" data-float-dock="right">'
            . '<div data-global-region-async="q">w</div>'
            . '</div></div></body></html>';

        $out = StorefrontFloatLayerHost::ensureFloatEdgeShellsInHtml($html);

        self::assertStringContainsString('data-float-edge-body', $out);
        self::assertStringContainsString('data-float-edge-dock="1"', $out);
        self::assertStringContainsString('var tpl', $out);
    }

    /**
     * A page whose only layer-id occurrence lives inside a script string must be
     * left untouched (no real layer element exists to repair).
     */
    public function testScriptLiteralOnlyDoesNotCorruptScriptText(): void
    {
        $html = '<!DOCTYPE html><html><body>'
            . '<script>var tpl = \'<div id="w-storefront-float-layer">'
            . '<div class="w-storefront-float-layer__slot"><div data-global-region-async="q">w</div></div>'
            . '</div>\';</script>'
            . '</body></html>';

        $out = StorefrontFloatLayerHost::ensureFloatEdgeShellsInHtml($html);
        // Full-doc path: script is CDATA, nothing to repair — output must equal input.
        self::assertStringContainsString('var tpl', $out);
        self::assertStringNotContainsString('data-float-edge-dock="1"', $out);
    }
}
