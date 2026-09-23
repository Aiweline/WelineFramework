<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * wave8-8s5: published storefront = include solidified whole-shell (chrome+page);
 * forbid request-time injectChrome / fill; regeneration only publish + injection collect.
 */
final class PublishedStorefrontSolidifiedShellContractTest extends TestCase
{
    public function testSlotFillerPrefersShellAndForbidsPublishedFillInjectChrome(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );

        self::assertStringContainsString('shellPhtml', $src);
        self::assertStringContainsString('Prefer whole-shell include', $src);
        self::assertStringContainsString('loadPublishedChromeBakeHtmlDirect', $src);
        self::assertStringContainsString('extractChromeInnersFromBakedHtml', $src);
        self::assertStringContainsString('wave8-8s5', $src);
        // Published fill no-op when solidified; placeholder leftovers keep narrow safety-net.
        self::assertStringContainsString('narrow safety-net exception', $src);
        self::assertStringContainsString('shellNeedsRuntimeSafetyNetFill', $src);
        self::assertStringContainsString('healPublishedPlaceholderShell', $src);
        // Fragments path must NOT call chrome_slot_projection remember.
        $fragmentsStart = \strpos($src, 'function renderPublishedSolidifiedFragments');
        self::assertNotFalse($fragmentsStart);
        $fragmentsEnd = \strpos($src, 'function fill(', $fragmentsStart);
        self::assertNotFalse($fragmentsEnd);
        $fragmentsBody = \substr($src, $fragmentsStart, $fragmentsEnd - $fragmentsStart);
        self::assertStringNotContainsString('rememberPublishedChromeSlotProjection', $fragmentsBody);
        self::assertStringNotContainsString('injectChromeSlots', $fragmentsBody);
        // fillChromeOnly published no-op.
        self::assertStringContainsString('chrome already in shell bake', $src);
        // required-default-always-present: fillRequiredDefaultsOnShell always overlays
        // (no published+complete hard no-op); only user_deleted omits plan items.
        self::assertStringContainsString('required-default-always-present', $src);
        self::assertStringContainsString('user_deleted@{versionId}', $src);
        $fillRequiredStart = \strpos($src, 'function fillRequiredDefaultsOnShell');
        self::assertNotFalse($fillRequiredStart);
        $fillRequiredEnd = \strpos($src, 'function finalizePublishedChromeRenderedHtml', $fillRequiredStart);
        self::assertNotFalse($fillRequiredEnd);
        $fillRequiredBody = \substr($src, $fillRequiredStart, $fillRequiredEnd - $fillRequiredStart);
        self::assertStringContainsString('RequiredDefaultInjectionStorefrontOverlay', $fillRequiredBody);
        self::assertStringNotContainsString('shellNeedsRuntimeSafetyNetFill', $fillRequiredBody);
        // Bake-time chrome.rendered finalize always overlays required JSON (slot exists).
        $finalizeStart = \strpos($src, 'function finalizePublishedChromeRenderedHtml');
        self::assertNotFalse($finalizeStart);
        $finalizeEnd = \strpos($src, 'function prefillPublishedChromeFromRenderedSnapshot', $finalizeStart);
        self::assertNotFalse($finalizeEnd);
        $finalizeBody = \substr($src, $finalizeStart, $finalizeEnd - $finalizeStart);
        self::assertStringContainsString('fillRequiredDefaultsOnShell', $finalizeBody);
        // wave9-9s2 integrity: chrome bake splice without Policy regen.
        self::assertStringContainsString('spliceChromeSlotsFromBake', $src);
        self::assertStringContainsString('wave9-9s2', $src);
        // wave9-9s3: graft chrome destinations when Partials chrome was gated off.
        self::assertStringContainsString('graftMissingChromePublishedSlot', $src);
        self::assertStringContainsString('wave9-9s3', $src);
    }

    public function testLayoutSlotHardSkipsStorefrontFill(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('wave8-8s5', $src);
        self::assertStringContainsString('+skip_fill_solidified', $src);
        self::assertStringContainsString('+safety_net_fill', $src);
        self::assertStringContainsString('healPublishedPlaceholderShell', $src);
        // Solidified path skips entity fill; placeholder leftovers take safety-net.
        // 布局固化与默认注入.md: complete skip_fill → strip only (no per-request Overlay).
        $zeroStart = \strpos($src, 'shouldForcePublishedZeroRuntimeFill()');
        self::assertNotFalse($zeroStart);
        $zeroEnd = \strpos($src, 'isEditorOrPreviewMode()', $zeroStart);
        self::assertNotFalse($zeroEnd);
        $zeroBody = \substr($src, $zeroStart, $zeroEnd - $zeroStart);
        self::assertStringContainsString('+safety_net_fill', $zeroBody);
        self::assertStringContainsString('healPublishedPlaceholderShell', $zeroBody);
        self::assertStringContainsString('fillRequiredDefaultsOnShell', $zeroBody);
        $skipPos = \strpos($zeroBody, "\$reason .= '+skip_fill_solidified'");
        self::assertNotFalse($skipPos);
        $stripPos = \strpos($zeroBody, 'SlotBoundaryMarkers::strip', $skipPos);
        self::assertNotFalse($stripPos);
        // Complete solidify: strip only — no per-request Overlay (布局固化与默认注入.md §3–§4).
        $betweenSkipAndStrip = \substr($zeroBody, $skipPos, $stripPos - $skipPos);
        self::assertStringNotContainsString('fillRequiredDefaultsOnShell', $betweenSkipAndStrip);
        self::assertStringContainsString('布局固化与默认注入', $betweenSkipAndStrip);
    }

    public function testRequiredDefaultsWrittenAtBakeNotEveryRequestOverlay(): void
    {
        $filler = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        $overlay = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/RequiredDefaultInjectionStorefrontOverlay.php'
        );
        $layoutSlot = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );
        $chrome = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityChrome.php'
        );

        // Bake-time finalize always overlays required JSON (minus user_deleted).
        $finalizeStart = \strpos($filler, 'function finalizePublishedChromeRenderedHtml');
        self::assertNotFalse($finalizeStart);
        $finalizeEnd = \strpos($filler, 'function prefillPublishedChromeFromRenderedSnapshot', $finalizeStart);
        self::assertNotFalse($finalizeEnd);
        $finalizeBody = \substr($filler, $finalizeStart, $finalizeEnd - $finalizeStart);
        self::assertStringContainsString('fillRequiredDefaultsOnShell', $finalizeBody);

        // No published+!safetyNet hard return in fillRequiredDefaultsOnShell.
        $fnStart = \strpos($filler, 'function fillRequiredDefaultsOnShell');
        self::assertNotFalse($fnStart);
        $fnEnd = \strpos($filler, 'function finalizePublishedChromeRenderedHtml', $fnStart);
        self::assertNotFalse($fnEnd);
        $body = \substr($filler, $fnStart, $fnEnd - $fnStart);
        self::assertStringNotContainsString('shellNeedsRuntimeSafetyNetFill', $body);
        self::assertStringContainsString('RequiredDefaultInjectionStorefrontOverlay', $body);

        // Overlay omit gate remains user_deleted only; plan reads ledger DB not Catalog.
        self::assertStringContainsString('user_deleted@{versionId}', $overlay);
        self::assertStringContainsString('仅 `user_deleted@{versionId}` 从 plan 省略', $overlay);
        self::assertStringContainsString('DefaultInjectionPlanRepository', $overlay);
        self::assertStringContainsString('listDeclarations', $overlay);
        self::assertStringNotContainsString('ThemeComponentCatalog', $overlay);
        self::assertStringContainsString('DefaultInjectionPlanRepository', $filler);
        self::assertStringNotContainsString(
            'ThemeComponentCatalog::class',
            \substr(
                $filler,
                (int)\strpos($filler, 'function shellMissingRequiredInjections'),
                1200
            )
        );

        // Incomplete chrome.rendered → drop for dynamic solidify (active theme).
        self::assertStringContainsString('isIncompleteRequiredChromeRendered', $chrome);
        self::assertStringContainsString('forceResolidifyRenderedSnapshot', $chrome);

        // skip_fill complete path must NOT wire per-request Overlay / Plan before strip.
        $skipPos = \strpos($layoutSlot, "\$reason .= '+skip_fill_solidified'");
        self::assertNotFalse($skipPos);
        $stripPos = \strpos($layoutSlot, 'SlotBoundaryMarkers::strip', $skipPos);
        self::assertNotFalse($stripPos);
        $between = \substr($layoutSlot, $skipPos, $stripPos - $skipPos);
        self::assertStringNotContainsString('fillRequiredDefaultsOnShell', $between);
        self::assertStringNotContainsString('DefaultInjectionPlanRepository', $between);
        // Safety-net incomplete path still self-heals with Overlay.
        self::assertStringContainsString('+safety_net_fill', $layoutSlot);
        $safetyPos = \strpos($layoutSlot, '+safety_net_fill');
        self::assertNotFalse($safetyPos);
        self::assertStringContainsString('fillRequiredDefaultsOnShell', \substr($layoutSlot, $safetyPos, 2500));
    }

    // Atomic shell publication and config/release reuse are exercised by
    // ThemeLayoutEntityTemplateBindingTest using actual generated artifacts.

    public function testPathsExposesShellBesideLayout(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php'
        );

        self::assertStringContainsString("return \$this->pageDir(\$themeId, \$scope, \$identityKey, \$structureOrRelease) . 'layout.phtml';", $src);
        self::assertStringContainsString("return \$this->pageDir(\$themeId, \$scope, \$identityKey, \$structureOrRelease) . 'shell.phtml';", $src);
        self::assertStringContainsString('function shellPhtml', $src);
        self::assertStringContainsString('wave8-8s5', $src);
    }
}
