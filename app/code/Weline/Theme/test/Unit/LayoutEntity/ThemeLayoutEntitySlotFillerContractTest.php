<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Storefront entity fill must recompute identity per ancestor scope.
 */
final class ThemeLayoutEntitySlotFillerContractTest extends TestCase
{
    public function testPageEntityResolutionRecomputesIdentityPerScope(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('function resolvePageEntityLocation', $src);
        self::assertStringContainsString('scopeFallbackChain', $src);
        self::assertStringContainsString('resolveEditorIdentity($themeId, $pageType, $area, $candidateScope)', $src);
        self::assertStringContainsString("'identity_key' => \$identityKey", $src);
        self::assertStringContainsString('function includeEntityPhtml', $src);
        self::assertStringContainsString('pageCurrentJson', $src);
        self::assertStringNotContainsString('processSlotsWithLayout', $src);
        self::assertStringContainsString('header-nav-extensions', $src);
        self::assertStringContainsString('theme_layout_entity_chrome_soft_skip', $src);
        self::assertStringContainsString('function fillNestedChromeExtensionSlots', $src);
        self::assertStringContainsString('scopeFallbackChain', $src);
        self::assertStringContainsString('isBlankChromeInner', $src);
        self::assertStringContainsString('isEffectivelyBlankSlotInner', $src);
        self::assertStringContainsString('chromeByScope', $src);
        // Storefront must read workspace published_release_id (never hardcode null).
        self::assertStringContainsString('schema_fields_PUBLISHED_RELEASE_ID', $src);
        self::assertStringContainsString("'release_id' => \$releaseId,", $src);
        // Storefront entity hard-cut must overlay RESOURCE_I18N (locale banners/media).
        self::assertStringContainsString('overlayLocaleOnLayout(', $src);
        self::assertStringContainsString('lives in RESOURCE_I18N', $src);
        self::assertStringContainsString('Prefer request scope for i18n identity', $src);
        // Slot fill order = layout document position only (never name/length sorts).
        self::assertStringContainsString('orderSlotsByShellLayout', $src);
        self::assertStringContainsString('NEVER sort by slot-id name/length', $src);
        self::assertStringNotContainsString('orderChromeSlotsForInjection', $src);
        self::assertStringNotContainsString('strlen($b) <=> strlen($a)', $src);
        // Nested homepage-* / policy-* under content must survive entity content splice
        // and keep shell document order (hero before later sections).
        self::assertStringContainsString('mergeParentSlotPreservingNested', $src);
        self::assertStringContainsString('shellSlotInnerHasProtectedNestedSlots', $src);
        self::assertStringContainsString('shellContentCarriesHomepageNestedSlots', $src);
        self::assertStringContainsString('shellContentCarriesProtectedNestedLayout', $src);
        self::assertStringContainsString("slotId === 'content' && \$this->shellContentCarriesProtectedNestedLayout", $src);
        self::assertStringContainsString('keep shell nested slot document order', $src);
        self::assertStringContainsString('$entityInner . $shellInner', $src);
        // Hard-cut: published is r{id} only — delete scandir / s* / older-r* fishing.
        self::assertStringContainsString("pageStructureOrRelease('', true, \$preferredReleaseId)", $src);
        self::assertStringNotContainsString('scandir($pageRoot)', $src);
        self::assertStringNotContainsString('bestDraft', $src);
        self::assertStringNotContainsString('bestRelease', $src);
        self::assertStringNotContainsString('filemtime($structurePath)', $src);
        // Must not keep a single channel identity while only walking scope dirs.
        self::assertStringNotContainsString(
            'resolvePageEntityLocation($themeId, $scope, $identityKey, $published',
            $src,
        );
        // Nested chrome slots (header-nav-extensions) must be injected, not only header/footer roots.
        self::assertStringNotContainsString(
            "foreach (['header', 'footer'] as \$slotId) {\n            \$inner = \$this->boundaryScanner->extractSlotInner(\$chromeHtml, \$slotId);",
            $src,
        );
    }

    public function testLayoutSlotRendererSoftDegradesWithChrome(): void
    {
        $path = \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('fillChromeFromEntity($html, $themeId, $area)', $src);
        self::assertStringContainsString('theme_layout_entity_storefront_fill_failed', $src);
        self::assertStringContainsString('fillChromeOnly($html, $themeId, $area, false)', $src);
        self::assertStringContainsString('ThemeLayoutEntitySlotFiller::class', $src);
        // Editor preview must ignore storefront skip and still processSlots for draft nodes.
        self::assertStringContainsString(
            'shouldSkipSlotProcessing() && !$this->isEditorOrPreviewMode()',
            $src,
        );
        // Live frontend preview must freeze LayoutIdentity from Token and treat
        // PreviewTokenService::isPreviewMode() as editor/preview (draft processSlots).
        self::assertStringContainsString('installPreviewLayoutIdentityFromToken', $src);
        self::assertStringContainsString('previewTokenService->isPreviewMode()', $src);
        self::assertStringContainsString('resolveLivePreviewContext', $src);
        self::assertStringNotContainsString('debug-e44d3b.log', $src);
    }

    /**
     * DaoCharms W158 P-filters：published 指针只认 rN；非法 published:d0 不得进店面 fragments。
     */
    public function testReadPageCurrentPublishedPointerAcceptsOnlyReleaseKeys(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        $fnStart = \strpos($src, 'function readPageCurrent');
        self::assertNotFalse($fnStart);
        $fnEnd = \strpos($src, 'function rememberPageCurrent', $fnStart);
        self::assertNotFalse($fnEnd);
        $body = \substr($src, $fnStart, $fnEnd - $fnStart);

        self::assertStringContainsString('/^r[1-9][0-9]*$/D', $body);
        self::assertStringContainsString("\$keys = \$published ? ['published'] : ['draft', 'published']", $body);
        // published 三元左侧必须是 rN 门禁（拒 d0）。
        self::assertStringContainsString("preg_match(\$published ? '/^r[1-9][0-9]*\$/D'", $body);
    }
}
