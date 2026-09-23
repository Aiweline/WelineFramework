<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * wave8-8s: published storefront zero-runtime slot fill —
 * Taglib host bakes solidified slots without data-wslot; LayoutSlotRenderer
 * early-returns; editor/preview keep reactive markers + fill; forbid fill-cache
 * as the primary fix.
 */
final class PublishedStorefrontZeroRuntimeSlotFillContractTest extends TestCase
{
    public function testSlotTaglibEmitsPublishedHostBranch(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Taglib/Slot.php');

        self::assertStringContainsString('ThemeLayoutEntityPublishedSlotHost::useReactiveMarkers', $src);
        self::assertStringContainsString('ThemeLayoutEntityPublishedSlotHost::publishedInner', $src);
        self::assertStringContainsString('FiberOutputBuffer::beginCapture()', $src);
        self::assertStringContainsString('FiberOutputBuffer::endCapture()', $src);
        self::assertStringContainsString('FiberOutputBuffer::discardCapture()', $src);
        self::assertDoesNotMatchRegularExpression('/\\\\ob_start\\(\\)/', $src);
        self::assertDoesNotMatchRegularExpression('/\\\\ob_get_clean\\(\\)/', $src);
        self::assertStringContainsString('theme-published-slot', $src);
        // published 默认 HTML 必须走 Fiber 捕获，禁止 var_export 内嵌（后续 @url 会弄坏引号）。
        self::assertStringNotContainsString('contentExport', $src);
        self::assertStringNotContainsString('var_export((string)$content', $src);
        // Reactive branch still carries editor markers.
        self::assertStringContainsString('data-wslot=', $src);
        self::assertStringContainsString('SlotBoundaryMarkers::open', $src);
    }

    public function testPublishedSlotHostSkipsReactiveOnPublishedBake(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPublishedSlotHost.php'
        );

        self::assertStringContainsString('function useReactiveMarkers', $src);
        self::assertStringContainsString('function publishedInner', $src);
        self::assertStringContainsString('isEditorOrPreviewRequest', $src);
        self::assertStringContainsString("editor_mode", $src);
        self::assertStringContainsString('PreviewTokenService', $src);
        self::assertStringContainsString('renderPublishedSolidifiedFragments', $src);
        self::assertStringContainsString('defaultCarriesNestedSlotMarkup', $src);
        self::assertStringContainsString('defaultCarriesLayoutPolicyOrTermsBody', $src);
        self::assertStringContainsString('bakeIsSparseContentOverlay', $src);
        self::assertStringContainsString('newsletter-popup', $src);
        // Must not introduce parallel static bags.
        self::assertStringNotContainsString('private static array', $src);
    }

    public function testSlotFillerExposesFragmentsWithoutShellFillOrchestration(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );

        self::assertStringContainsString('function renderPublishedSolidifiedFragments', $src);
        self::assertStringContainsString('Prefer whole-shell include', $src);
        // Published fill: solidified skip + placeholder safety-net (wave8-8s5+safety).
        self::assertStringContainsString('narrow safety-net exception', $src);
        self::assertStringContainsString('shellNeedsRuntimeSafetyNetFill', $src);
        self::assertStringContainsString('$hasReactiveMarkers', $src);
        self::assertStringContainsString('wave8-8s', $src);
        // Must not add a fill-result cache as the primary fix.
        self::assertStringNotContainsString('fillResultCache', $src);
        self::assertStringNotContainsString('publishedFillHtmlPolicy', $src);
    }

    public function testLayoutSlotRendererDocumentsZeroFillEarlyReturn(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );

        self::assertStringContainsString('zero-runtime-fill', $src);
        self::assertStringContainsString('wave8-8s', $src);
        self::assertStringContainsString('renderFromLayoutEntities', $src);
        self::assertStringContainsString("SharedResponseCachePolicy::forbid('theme_preview_mode')", $src);
        self::assertStringContainsString("SharedResponseCachePolicy::forbid('theme_editor_canvas')", $src);
    }

    public function testFetchFileBeforeSeedsHostContext(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/ControllerFetchFileBefore.php'
        );

        self::assertStringContainsString('ThemeLayoutEntityPublishedSlotHost::primeStorefront', $src);
        self::assertStringContainsString('markSkipSlotProcessing(true)', $src);
    }
}
