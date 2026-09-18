<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Architecture gate: document-wide MutationObserver must go through Weline.dom bus.
 * Vue-aligned: max flush depth + takeRecords drain; forbid raw document MO in first-party surfaces.
 */
final class WelineDomMutationBusContractTest extends TestCase
{
    private function welineJs(): string
    {
        return (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );
    }

    public function testDomObserveBusIsExportedAndSharedByFingerprint(): void
    {
        $js = $this->welineJs();

        self::assertStringContainsString('function observeDomMutations', $js);
        self::assertStringContainsString('domMutationChannels', $js);
        self::assertStringContainsString('Weline.dom = Object.assign', $js);
        self::assertStringContainsString('observe: observeDomMutations', $js);
        self::assertStringContainsString('documentWideTargetToken', $js);
        self::assertStringContainsString('__shared: true', $js);
        self::assertStringContainsString('Quiet window FIRST', $js);
        self::assertStringContainsString('startMarkerDiscovery', $js);
        self::assertStringContainsString('observeDomMutations({', $js);
    }

    public function testVueStyleMaxFlushDepthAndTakeRecords(): void
    {
        $js = $this->welineJs();

        self::assertStringContainsString('MAX_FLUSH_DEPTH = 50', $js);
        self::assertStringContainsString('MAX_FLUSHES_PER_WINDOW = 40', $js);
        self::assertStringContainsString('takeRecords', $js);
        self::assertStringContainsString('reportFeedbackLoop', $js);
        self::assertStringContainsString('max_flush_depth', $js);
        self::assertStringContainsString('flush_storm', $js);
        self::assertStringContainsString('weline:dom:mutation-loop', $js);
        self::assertStringContainsString('参考 Vue maxUpdateDepth', $js);
    }

    public function testCriticalSurfacesPreferWelineDomObserve(): void
    {
        $ui = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/ui/js/weline-ui.js'
        );
        $captcha = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Captcha/view/statics/js/captcha-lazy.js'
        );
        $preview = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/ui/js/pages/theme-preview.js'
        );
        $editorMode = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Theme/view/statics/js/editor-mode.js'
        );
        $apiDom = (string) \file_get_contents(
            \dirname(__DIR__, 4) . '/Frontend/view/statics/js/weline-api-dom.js'
        );

        self::assertStringContainsString('Weline.dom.observe', $ui);
        self::assertStringContainsString('Weline.dom.observe', $captcha);
        self::assertStringContainsString('Weline.dom.observe', $preview);
        self::assertStringContainsString('Weline.dom.observe', $editorMode);
        self::assertStringContainsString('Weline.dom.observe', $apiDom);
        self::assertStringContainsString('weline-api-dom:late-discovery', $apiDom);
    }

    /**
     * First-party storefront/runtime surfaces must not attach a raw MutationObserver
     * to document/documentElement/body except inside named *Fallback* helpers or weline.js bus.
     *
     * @return list<string>
     */
    private function firstPartyPathsUnderGate(): array
    {
        $root = \dirname(__DIR__, 4);
        return [
            $root . '/Theme/view/ui/js/weline-ui.js',
            $root . '/Theme/view/ui/js/pages/theme-preview.js',
            $root . '/Theme/view/statics/js/editor-mode.js',
            $root . '/Captcha/view/statics/js/captcha-lazy.js',
            $root . '/Frontend/view/statics/js/weline-api-dom.js',
            $root . '/Cart/view/statics/js/widgets/mini-cart-icon.js',
            $root . '/Product/view/statics/js/widgets/product-sticky-purchase.js',
            $root . '/Theme/view/statics/js/widgets/site-blocks.js',
            $root . '/Theme/view/statics/js/widgets/mini-cart-icon.js',
        ];
    }

    /**
     * Strip ARCH_MO_FALLBACK_* regions (local quiet-window polyfills when bus missing).
     */
    private function stripFallbackHelpers(string $source): string
    {
        $stripped = \preg_replace(
            '/\/\*\s*ARCH_MO_FALLBACK_START\s*\*\/[\s\S]*?\/\*\s*ARCH_MO_FALLBACK_END\s*\*\//',
            '',
            $source
        );

        return \is_string($stripped) ? $stripped : $source;
    }

    public function testFirstPartySurfacesForbidRawDocumentMutationObserverOutsideFallback(): void
    {
        $pattern = '/new\s+MutationObserver[\s\S]{0,1200}?(?:document\.documentElement|document\.body|observe\s*\(\s*document\b)/';
        $violations = [];

        foreach ($this->firstPartyPathsUnderGate() as $path) {
            if (!\is_file($path)) {
                continue;
            }
            $raw = (string) \file_get_contents($path);
            $src = $this->stripFallbackHelpers($raw);
            if (\preg_match($pattern, $src)) {
                $violations[] = $path;
            }
        }

        self::assertSame(
            [],
            $violations,
            "Document-wide raw MutationObserver forbidden; use Weline.dom.observe. Violations:\n"
            . \implode("\n", $violations)
        );
    }
}
