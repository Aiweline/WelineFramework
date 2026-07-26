<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\View;

use Weline\Framework\Test\TestCore;

/**
 * A07–A11：sticky 存取/同意/内链/SPA/GET form；pixel.js 与 pixel.phtml 同步。
 */
class PixelStickyUtmJsContractTest extends TestCore
{
    private function jsPath(): string
    {
        return BP . '/app/code/Weline/Visitor/view/statics/js/pixel.js';
    }

    private function phtmlPath(): string
    {
        return BP . '/app/code/Weline/Visitor/view/taglib/js/pixel.phtml';
    }

    /** @return list<string> */
    private function sources(): array
    {
        return [
            (string)\file_get_contents($this->jsPath()),
            (string)\file_get_contents($this->phtmlPath()),
        ];
    }

    public function testBothFilesContainStickyApiAndVersion(): void
    {
        foreach ($this->sources() as $source) {
            self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.07.24-a12'", $source);
            self::assertStringContainsString('function __getStickyUtmPack()', $source);
            self::assertStringContainsString('payload.sticky =', $source);
        }
    }

    public function testA08GatesStickyByMarketingConsent(): void
    {
        foreach ($this->sources() as $source) {
            self::assertStringContainsString('function __visitorMarketingConsentAllowsStorage()', $source);
            self::assertStringContainsString('function __stickyLinkerEnabled()', $source);
        }
    }

    public function testA09RewritesInternalAnchorsWithObserver(): void
    {
        foreach ($this->sources() as $source) {
            self::assertStringContainsString('function __rewriteStickyAnchors(', $source);
            self::assertStringContainsString('new MutationObserver', $source);
            self::assertStringContainsString("querySelectorAll('a[href]')", $source);
        }
    }

    public function testA10RerunsLinkerOnSpaTransitions(): void
    {
        foreach ($this->sources() as $source) {
            self::assertMatchesRegularExpression(
                '/function __trackPageTransition\([\s\S]*?__scheduleStickyAnchorRewrite\(160\);/',
                $source
            );
            self::assertStringContainsString("addEventListener('popstate'", $source);
        }
    }

    public function testA11GetFormMergeDefaultOff(): void
    {
        foreach ($this->sources() as $source) {
            self::assertStringContainsString('function __stickyFormMergeEnabled()', $source);
            self::assertStringContainsString('function __mergeStickyIntoForm(', $source);
            self::assertStringContainsString('function __initStickyFormMerge()', $source);
            self::assertStringContainsString('__initStickyFormMerge();', $source);
            self::assertStringContainsString('data-weline-sticky-field', $source);
            // 默认关：__visitorConfigBool(flag, false)
            self::assertMatchesRegularExpression(
                '/function __stickyFormMergeEnabled\(\)[\s\S]{0,400}?__visitorConfigBool\(flag, false\)/',
                $source
            );
            self::assertStringContainsString('stickyFormMergeEnabled: __stickyFormMergeEnabled', $source);
            self::assertStringContainsString('mergeStickyIntoForm: __mergeStickyIntoForm', $source);
        }
    }

    public function testA12Ga4GtmForwardIncludeStickyParams(): void
    {
        foreach ($this->sources() as $source) {
            self::assertStringContainsString('function __appendStickyAttributionParams(', $source);
            self::assertStringContainsString('return __appendStickyAttributionParams(params);', $source);
            self::assertStringContainsString('__appendStickyAttributionParams(row);', $source);
            self::assertStringContainsString('sticky_locked_at', $source);
            self::assertStringContainsString("['utm_source', pack.utm_source]", $source);
        }
    }

    public function testStickyHelpersAlignedBetweenFiles(): void
    {
        [$js, $phtml] = $this->sources();
        foreach ([
            'function __captureMarketingPackFromSearch',
            'function __persistStickyUtmPack',
            'function __rewriteStickyAnchors',
            'function __initStickyUtmLinker',
            'function __stickyFormMergeEnabled',
            'function __mergeStickyIntoForm',
            'function __initStickyFormMerge',
            'function __appendStickyAttributionParams',
            '__scheduleStickyAnchorRewrite(160)',
            '__pixelStickyStorageKey',
        ] as $needle) {
            self::assertStringContainsString($needle, $js);
            self::assertStringContainsString($needle, $phtml);
        }
    }
}
