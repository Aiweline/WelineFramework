<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\ThemePageTypeResolver;

/**
 * wave8-8s / 8s2 / 8s3 / 8s5: published storefront slot host — emit solidified bake into
 * the shell without reactive data-wslot markers so LayoutSlotRenderer can early-return.
 *
 * wave8-8s5: fragments come from whole-shell bake (shell.phtml = chrome+page); no
 * request-time chrome_slot_projection / injectChrome. Regeneration = editor publish
 * + injection-collect bake only. Editor / preview / backend keep reactive markers.
 *
 * wave8-8s2: fragments MUST be primed before w:slot runs (ControllerFetchFileBefore).
 * Do not sticky-cache reactive=true when theme_id/layout_type are not ready yet.
 *
 * wave8-8s3: bake/widget include can still emit nested data-wslot (solidify re-entry
 * or hardcoded component attrs). Sanitize fragments + publishedInner; solidify flag
 * forces published wrappers with passthrough body (no recursive bake lookup).
 */
final class ThemeLayoutEntityPublishedSlotHost
{
    public const CTX_USE_REACTIVE = 'theme.layout_entity.published_slot.use_reactive.v1';
    public const CTX_FRAGMENTS = 'theme.layout_entity.published_slot.fragments.v1';
    public const CTX_LAYOUT_TYPE = 'theme.storefront.layout_type';
    public const CTX_THEME_ID = 'theme.storefront.theme_id';
    /** True while includeEntityPhtml builds fragments — nested w:slot must not recurse. */
    public const CTX_SOLIDIFYING = 'theme.layout_entity.published_slot.solidifying.v1';
    /** wave8-8s4: LayoutSlot applied hard zero-runtime-fill (strip + skip fill). */
    public const CTX_ZERO_FILL_APPLIED = 'theme.layout_entity.zero_runtime_fill.applied.v1';
    /** wave8-8s4: why zero-fill ran / why CTX was not false before force. */
    public const CTX_ZERO_FILL_REASON = 'theme.layout_entity.zero_runtime_fill.reason.v1';
    /** Last prime/load error message (transient include) — not sticky miss. */
    public const CTX_PRIME_TRANSIENT_ERROR = 'theme.layout_entity.published_slot.prime_transient_error.v1';

    /**
     * wave8-8s4+safety / wave9-9s2 / wave9-9s5 / wave9-9s6: true when published shell
     * is incomplete. Skip runtime fill only when this returns false — incomplete
     * shells must take the safety-net heal path before outbound strip (禁交白卷).
     *
     * Triggers:
     * - required-slot placeholders (list-filters / category-filters / slot-placeholder)
     * - storefront chrome page missing header signals OR blank exclusive header/footer
     *   roots (wave9-9s6: empty footer--shell = 缺壳, not emptyCrit-while-chromePresent)
     * - empty required inventory destinations (list-filters / category-filters)
     * - when chrome complete: only empty filters in emptyCrit (never empty header/footer
     *   wrappers — those false-forced ~2s heal after Partials already rendered chrome)
     *
     * Intentionally does NOT treat every data-placeholder (e.g. empty list-grid
     * "no products" copy) as a fill trigger — that would block skip-fill forever.
     */
    public static function shellNeedsRuntimeSafetyNetFill(string $html): bool
    {
        return self::shellSafetyNetFillReason($html) !== 'none';
    }

    /**
     * wave9-9s6: which gate branch forces safety-net (observability for zero_runtime_fill).
     *
     * @return 'none'|'slot_placeholder'|'filter_data_placeholder'|'missing_chrome_header_signals'|'missing_chrome_blank_header_or_footer'|'empty_critical_filters'|'empty_critical_published'|'missing_required_newsletter_popup'
     */
    public static function shellSafetyNetFillReason(string $html): string
    {
        if ($html === '') {
            return 'none';
        }
        // Filter destinations first — products/category layouts keep a declaration
        // placeholder with class slot-placeholder; classify as filter so LayoutSlot
        // can take the narrow overlay heal (not whole-shell fill).
        if (\preg_match(
            '/\bdata-placeholder\s*=\s*(["\'])(?:list-filters|category-filters)\1/',
            $html,
        ) === 1) {
            return 'filter_data_placeholder';
        }

        if (\str_contains($html, 'slot-placeholder')) {
            return 'slot_placeholder';
        }

        if (self::shellMissingStorefrontChromeSignals($html)) {
            if (!self::shellHasStorefrontHeaderSignal($html)) {
                return 'missing_chrome_header_signals';
            }

            return 'missing_chrome_blank_header_or_footer';
        }

        if (self::shellHasEmptyCriticalPublishedSlots($html)) {
            $chromePresent = !self::shellMissingStorefrontChromeSignals($html);

            return $chromePresent ? 'empty_critical_filters' : 'empty_critical_published';
        }

        // Required newsletter-popup injects into homepage content; design solidify may omit
        // it while chrome is "complete" — force narrow Overlay heal (append, not replace).
        if (self::shellMissingRequiredNewsletterPopup($html)) {
            return 'missing_required_newsletter_popup';
        }

        return 'none';
    }

    /**
     * Homepage / chrome with footer-newsletter enable_popup but missing popup DOM.
     * Also covers design shells that still lack the co-rendered popup.
     */
    public static function shellMissingRequiredNewsletterPopup(string $html): bool
    {
        if ($html === '' || \str_contains($html, 'data-widget-code="newsletter-popup"')) {
            return false;
        }
        // Footer band enabled popup but bake omitted sibling → heal.
        if (\str_contains($html, 'data-widget-code="footer-newsletter"')
            && \preg_match('/\bdata-enable-popup\s*=\s*(["\'])true\1/', $html) === 1
        ) {
            return true;
        }
        if (!\str_contains($html, 'data-slot-id="content"')) {
            return false;
        }
        if (\preg_match('/\bdata-layout\s*=\s*(["\'])homepage\1/', $html) === 1) {
            return true;
        }

        return \str_contains($html, 'homepage-content-slot')
            || \str_contains($html, 'homepage-section');
    }

    /**
     * Empty theme-published-slot / marker wrappers for chrome + required destinations.
     *
     * wave9-9s5/9s6: when chrome is complete (header signals + non-blank header/footer
     * roots), only empty required filter slots force heal. Empty delivery / nested
     * chrome extensions / empty header|footer wrappers alone must not reopen the
     * heavy LayoutSlot heal path — blank exclusive roots are 缺壳 via
     * shellMissingStorefrontChromeSignals instead.
     */
    public static function shellHasEmptyCriticalPublishedSlots(string $html): bool
    {
        if ($html === '' || (!\str_contains($html, 'data-slot-id=') && !\str_contains($html, '<!--@weline-slot:'))) {
            return false;
        }

        $chromePresent = !self::shellMissingStorefrontChromeSignals($html);
        // wave9-9s6: chrome complete → filters only. Header/footer blanks = 缺壳 branch.
        $critical = $chromePresent
            ? '(?:list-filters|category-filters)'
            : '(?:header|footer|delivery|header-[\w.-]+|footer-[\w.-]+|list-filters|category-filters)';

        return self::shellHasBlankSlotsMatching($html, $critical);
    }

    /**
     * Depth-aware blank scan for published wrappers + marker regions.
     * Naive `(.*?)` same-tag match false-positives on nested `<div>` (wave9-9s5).
     */
    private static function shellHasBlankSlotsMatching(string $html, string $slotIdPattern): bool
    {
        // Boundary-marker regions (open/close include slot id — non-greedy is safe).
        if (\preg_match_all(
            '/<!--@weline-slot:(' . $slotIdPattern . ')-->(.*?)<!--@\/weline-slot:\1-->/is',
            $html,
            $markerMatches,
            \PREG_SET_ORDER,
        ) > 0) {
            foreach ($markerMatches as $m) {
                if (self::isBlankPublishedSlotInner((string)($m[2] ?? ''))) {
                    return true;
                }
            }
        }

        foreach (self::eachPublishedSlotInner($html, $slotIdPattern) as $inner) {
            if (self::isBlankPublishedSlotInner($inner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Generator<int, string>
     */
    private static function eachPublishedSlotInner(string $html, string $slotIdPattern): \Generator
    {
        if ($html === '' || !\str_contains($html, 'data-slot-id=')) {
            return;
        }
        if (\preg_match_all(
            '/<([a-z0-9]+)([^>]*\bdata-slot-id\s*=\s*(["\'])(' . $slotIdPattern . ')\3[^>]*)>/is',
            $html,
            $opens,
            \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        ) < 1) {
            return;
        }
        foreach ($opens as $open) {
            $tag = \strtolower((string)($open[1][0] ?? ''));
            if ($tag === '') {
                continue;
            }
            $token = (string)($open[0][0] ?? '');
            $innerStart = (int)($open[0][1] ?? 0) + \strlen($token);
            $inner = self::extractBalancedTagInner($html, $tag, $innerStart);
            if ($inner !== null) {
                yield $inner;
            }
        }
    }

    /**
     * Extract inner HTML of a balanced tag starting at $innerStart (after the open tag).
     */
    private static function extractBalancedTagInner(string $html, string $tag, int $innerStart): ?string
    {
        $len = \strlen($html);
        if ($innerStart < 0 || $innerStart > $len) {
            return null;
        }
        $depth = 1;
        $offset = $innerStart;
        $pattern = '/<\/?' . \preg_quote($tag, '/') . '\b[^>]*>/i';
        while ($offset < $len && \preg_match($pattern, $html, $m, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $tok = (string)$m[0][0];
            $pos = (int)$m[0][1];
            $isClose = isset($tok[1]) && $tok[1] === '/';
            if ($isClose) {
                --$depth;
                if ($depth === 0) {
                    return \substr($html, $innerStart, $pos - $innerStart);
                }
            } elseif (!\str_ends_with($tok, '/>')) {
                ++$depth;
            }
            $offset = $pos + \strlen($tok);
        }

        return null;
    }

    /**
     * Full storefront chrome pages (homepage / listing / default wrapper) must keep
     * a real header shell. Missing chrome signals after solidify = 丢件.
     *
     * wave9-9s3: align with real Theme/Hanfu chrome markers — not only `weline-header`
     * (false-negative when design wraps shell in `weline-header-slot` / published
     * `data-slot-id="header|footer|delivery|header-nav-extensions"`).
     *
     * wave9-9s6: `weline-header-slot` alone is NOT a header signal (substring trap).
     * Blank exclusive `header`/`footer` published roots (incl. footer--shell) = 缺壳
     * even when Partial header classes already exist.
     */
    public static function shellMissingStorefrontChromeSignals(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        $expectsChrome = \str_contains($html, 'weline-page-wrapper')
            || \str_contains($html, 'weline-main-content')
            || \str_contains($html, 'homepage-main')
            || \str_contains($html, 'theme-layout-homepage')
            // Word-boundary: do NOT match products-layout__placeholder alone.
            || \preg_match('/\bproducts-layout\b(?!_)/', $html) === 1;
        if (!$expectsChrome) {
            return false;
        }

        // Explicit chrome-off layouts (account auth blank, etc.) skip the gate.
        if (\str_contains($html, 'data-storefront-chrome="off"')
            || \str_contains($html, 'weline-auth-shell')
        ) {
            return false;
        }

        if (!self::shellHasStorefrontHeaderSignal($html)) {
            return true;
        }

        // Strong Partial/chrome header already on the page → blank exclusive
        // data-slot-id="header" wrappers are leftover declaration shells, not 缺壳.
        // Empty exclusive footer (incl. footer--shell) still forces heal UNLESS a real
        // Partial footer body is already present (list/PDP: footer lives outside the
        // exclusive published root while data-slot-id="footer" stays blank).
        if (self::shellHasSubstantialHeaderChrome($html)) {
            if (!self::shellHasBlankExclusiveFooterRoot($html)) {
                return false;
            }

            return !self::shellHasSubstantialFooterChrome($html);
        }

        // Header signal present but exclusive chrome roots still blank → 缺壳.
        return self::shellHasBlankExclusiveChromeRoots($html);
    }

    /**
     * Real header chrome with non-trivial body (Partial-rendered), not empty slot shells.
     */
    public static function shellHasSubstantialHeaderChrome(string $html): bool
    {
        if ($html === '' || !self::shellHasStorefrontHeaderSignal($html)) {
            return false;
        }

        // weline-header … with nav/account content (depth-agnostic sample).
        if (\preg_match(
            '/\bweline-header\b(?!-)[^>]*>[\s\S]{80,8000}?(?:header-nav|header-account|site-header|hanfu-atelier-chrome)/i',
            $html,
        ) === 1) {
            return true;
        }

        // Published non-blank header root already carries widgets.
        if (\preg_match(
            '/\bdata-slot-id=(["\'])header\1[^>]*>[\s\S]{40,}?<\/(?:div|header|section)>/i',
            $html,
        ) === 1) {
            $sample = '';
            if (\preg_match(
                '/\bdata-slot-id=(["\'])header\1[^>]*>([\s\S]{0,4000})<\/(?:div|header|section)>/i',
                $html,
                $m,
            ) === 1) {
                $sample = (string)($m[2] ?? '');
            }
            if ($sample !== '' && !self::isBlankPublishedSlotInner($sample)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Real footer chrome body already on the page (Partial / bake), not empty shell.
     */
    public static function shellHasSubstantialFooterChrome(string $html): bool
    {
        if ($html === '' || !\str_contains($html, 'weline-footer')) {
            return false;
        }

        if (\preg_match(
            '/\bweline-footer\b(?!-)/i',
            $html,
        ) !== 1) {
            return false;
        }

        // Reject pages that ONLY have empty footer--shell and no real footer body.
        if (\str_contains($html, 'weline-footer--shell')
            && !\preg_match('/\bweline-footer\b(?!-)(?![^>]*--shell)[^>]*>/i', $html)
        ) {
            return false;
        }

        if (\preg_match(
            '/\bweline-footer\b(?!-)(?![^>]*--shell)[^>]*>[\s\S]{0,12000}?(?:footer-container|footer-nav|footer-bottom|site-footer|wc-theme_widget_footer)/i',
            $html,
        ) === 1) {
            return true;
        }

        // Non-blank published footer root.
        if (\preg_match(
            '/\bdata-slot-id=(["\'])footer\1[^>]*>([\s\S]{0,8000})<\/(?:div|footer|section)>/i',
            $html,
            $m,
        ) === 1) {
            $sample = (string)($m[2] ?? '');
            if ($sample !== ''
                && !\str_contains($sample, 'weline-footer--shell')
                && !self::isBlankPublishedSlotInner($sample)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Blank exclusive footer published root (incl. footer--shell) = 缺壳.
     */
    public static function shellHasBlankExclusiveFooterRoot(string $html): bool
    {
        if ($html === '' || (!\str_contains($html, 'data-slot-id=') && !\str_contains($html, '<!--@weline-slot:'))) {
            return false;
        }

        return self::shellHasBlankSlotsMatching($html, 'footer');
    }

    /**
     * Real header structure signals (not the `weline-header-slot` wrapper class alone).
     */
    public static function shellHasStorefrontHeaderSignal(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        // (?!-) rejects `weline-header-slot` / `weline-header-nav` false positives.
        return \preg_match('/\bweline-header\b(?!-)/', $html) === 1
            // Do NOT use bare str_contains('header-nav') — matches empty
            // data-slot-id="header-nav-extensions" and false-passes completeness.
            || \preg_match('/\bheader-nav(?:["\'\s>]|$)/', $html) === 1
            || \str_contains($html, 'site-header')
            || \str_contains($html, 'header-account')
            || \str_contains($html, 'hanfu-atelier-chrome')
            || \preg_match('/\bclass=(["\'])[^"\']*\bheader-container\b/', $html) === 1
            // Non-blank published chrome destinations count as signals.
            || self::shellHasNonBlankChromePublishedSlotSignal($html);
    }

    /**
     * Exclusive header/footer published roots that are blank (or footer--shell only).
     */
    private static function shellHasBlankExclusiveChromeRoots(string $html): bool
    {
        if ($html === '' || (!\str_contains($html, 'data-slot-id=') && !\str_contains($html, '<!--@weline-slot:'))) {
            return false;
        }

        return self::shellHasBlankSlotsMatching($html, '(?:header|footer)');
    }

    /**
     * True when a published chrome destination exists with non-blank inner content.
     */
    private static function shellHasNonBlankChromePublishedSlotSignal(string $html): bool
    {
        $pattern = '(?:header|footer|delivery|header-nav(?:-extensions)?|header-[\w.-]+|footer-[\w.-]+)';
        foreach (self::eachPublishedSlotInner($html, $pattern) as $inner) {
            if (!self::isBlankPublishedSlotInner($inner)) {
                return true;
            }
        }
        if (\preg_match_all(
            '/<!--@weline-slot:(' . $pattern . ')-->(.*?)<!--@\/weline-slot:\1-->/is',
            $html,
            $markerMatches,
            \PREG_SET_ORDER,
        ) > 0) {
            foreach ($markerMatches as $m) {
                if (!self::isBlankPublishedSlotInner((string)($m[2] ?? ''))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when slot inner has no visible widgets — empty, comments only
     * (incl. theme-layout-entity:missing-config stubs), or empty wrappers.
     */
    public static function isEffectivelyBlankSlotInner(string $inner): bool
    {
        return self::isBlankPublishedSlotInner($inner);
    }

    private static function isBlankPublishedSlotInner(string $inner): bool
    {
        $trimmed = \trim($inner);
        if ($trimmed === '') {
            return true;
        }
        // Ignore whitespace / comments / empty nested wrappers only.
        $stripped = \trim(\preg_replace('/<!--.*?-->/s', '', $trimmed) ?? $trimmed);
        if ($stripped === '') {
            return true;
        }
        $text = \trim(\html_entity_decode(\strip_tags($stripped), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));

        return $text === '';
    }

    /**
     * Seed request context and eagerly load solidified fragments BEFORE any w:slot
     * evaluates useReactiveMarkers(). Call from ControllerFetchFileBefore on frontend.
     */
    public static function primeStorefront(int $themeId, string $layoutType): bool
    {
        if (self::isEditorOrPreviewRequest()) {
            RequestContext::set(self::CTX_USE_REACTIVE, true);

            return false;
        }

        $area = \strtolower(\trim((string)(ThemeData::getCurrentArea() ?? 'frontend')));
        if ($area === 'backend') {
            RequestContext::set(self::CTX_USE_REACTIVE, true);

            return false;
        }

        if ($themeId > 0) {
            RequestContext::set(self::CTX_THEME_ID, $themeId);
        }
        $layoutType = \trim($layoutType);
        if ($layoutType !== '') {
            RequestContext::set(self::CTX_LAYOUT_TYPE, $layoutType);
        }

        // Clear sticky negatives from any earlier not-ready probe.
        RequestContext::remove(self::CTX_USE_REACTIVE);
        RequestContext::remove(self::CTX_FRAGMENTS);

        $fragments = self::loadFragments();
        if ($fragments !== null) {
            RequestContext::set(self::CTX_USE_REACTIVE, false);

            return true;
        }

        // Definitive miss after a primed attempt with ids → reactive fill safety net.
        if (RequestContext::get(self::CTX_FRAGMENTS) === false) {
            RequestContext::set(self::CTX_USE_REACTIVE, true);
        }

        return false;
    }

    /**
     * True when the compiled slot must emit data-wslot / boundary markers
     * (editor, preview, backend, or published bake unavailable).
     */
    public static function useReactiveMarkers(): bool
    {
        // Solidifying entity HTML: emit published wrappers without data-wslot,
        // and publishedInner passthrough (avoids recursive loadFragments).
        if (RequestContext::get(self::CTX_SOLIDIFYING) === true) {
            return false;
        }

        $cached = RequestContext::get(self::CTX_USE_REACTIVE);
        if (\is_bool($cached)) {
            return $cached;
        }

        if (self::isEditorOrPreviewRequest()) {
            RequestContext::set(self::CTX_USE_REACTIVE, true);

            return true;
        }

        $area = \strtolower(\trim((string)(ThemeData::getCurrentArea() ?? '')));
        if ($area === 'backend') {
            RequestContext::set(self::CTX_USE_REACTIVE, true);

            return true;
        }

        $fragments = self::loadFragments();
        if ($fragments !== null) {
            RequestContext::set(self::CTX_USE_REACTIVE, false);

            return false;
        }

        // Definitive miss (valid theme+page attempted, bake missing).
        if (RequestContext::get(self::CTX_FRAGMENTS) === false) {
            RequestContext::set(self::CTX_USE_REACTIVE, true);

            return true;
        }

        // Not ready yet (theme_id / layout_type still empty): temporary reactive so
        // LayoutSlot fill can still rescue — DO NOT sticky-cache this decision.
        return true;
    }

    /**
     * Published path: prefer bake inner; keep nested shell output when parent slot
     * would otherwise wipe children (same spirit as mergeParentSlotPreservingNested).
     *
     * theme-published-policy-body: sparse overlays (newsletter-popup) must never
     * replace policy/terms layout body under content.
     */
    public static function publishedInner(string $slotId, string $defaultHtml): string
    {
        $slotId = \strtolower(\trim($slotId));
        if ($slotId === '') {
            return self::sanitizePublishedHtml($defaultHtml);
        }

        // During fragment solidify, never look up bake (that is the HTML being built).
        if (RequestContext::get(self::CTX_SOLIDIFYING) === true) {
            return self::sanitizePublishedHtml($defaultHtml);
        }

        $bakeInner = self::resolveBakeInner($slotId);
        if ($bakeInner === null) {
            return self::sanitizePublishedHtml($defaultHtml);
        }

        // A compiled empty slot is an explicit decision, not a missing bake.
        if (trim($bakeInner) === '') {
            return '';
        }

        // N1: list/category filter inventory — bake owns required Filters relationship HTML.
        // Design declaration placeholders must not survive beside a solidified Filters panel
        // (otherwise LayoutSlot narrow_filter_heal → Overlay becomes the per-request main path).
        if (self::isFilterInventorySlot($slotId) && self::bakeInnerHasFiltersWidget($bakeInner)) {
            return self::sanitizePublishedHtml($bakeInner);
        }

        $defaultHtml = (string)$defaultHtml;
        if ($defaultHtml !== '' && (
            self::defaultCarriesNestedSlotMarkup($defaultHtml)
            || self::defaultCarriesLayoutPolicyOrTermsBody($defaultHtml)
            || self::bakeIsSparseContentOverlay($bakeInner, $defaultHtml)
        )) {
            return self::sanitizePublishedHtml($bakeInner . $defaultHtml);
        }

        return self::sanitizePublishedHtml($bakeInner);
    }

    /** list-filters (products) / category-filters (category) inventory destinations. */
    public static function isFilterInventorySlot(string $slotId): bool
    {
        $slotId = \strtolower(\trim($slotId));

        return $slotId === 'list-filters' || $slotId === 'category-filters';
    }

    /** True when solidified bake inner already carries Filters widget presence markers. */
    public static function bakeInnerHasFiltersWidget(string $inner): bool
    {
        if ($inner === '') {
            return false;
        }

        return \str_contains($inner, 'storefront-filters-panel')
            || \str_contains($inner, 'data-widget-code="category-filters"')
            || \str_contains($inner, "data-widget-code='category-filters'")
            || \str_contains($inner, 'w-filters');
    }

    private static function isEditorOrPreviewRequest(): bool
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $editorMode = \trim((string)$request->getParam('editor_mode', ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return true;
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            /** @var PreviewTokenService $preview */
            $preview = ObjectManager::getInstance(PreviewTokenService::class);
            if ($preview->isPreviewMode()) {
                return true;
            }
        } catch (\Throwable) {
            // fall through
        }

        return false;
    }

    private static function resolveBakeInner(string $slotId): ?string
    {
        $fragments = self::loadFragments();
        if ($fragments === null) {
            return null;
        }

        // 固化输出已经明确槽归属；合法的公共壳嵌套槽不必有 header/footer 前缀。
        $chromeBySlot = $fragments['chrome_by_slot'] ?? [];
        if (is_array($chromeBySlot) && array_key_exists($slotId, $chromeBySlot)
            && is_string($chromeBySlot[$slotId])) {
            return $chromeBySlot[$slotId] !== '' ? $chromeBySlot[$slotId] : null;
        }

        try {
            /** @var SharedChromeService $chrome */
            $chrome = ObjectManager::getInstance(SharedChromeService::class);
            if ($chrome->isChromeSlot($slotId)) {
                $chromeBySlot = $fragments['chrome_by_slot'] ?? [];
                if (\is_array($chromeBySlot) && isset($chromeBySlot[$slotId]) && \is_string($chromeBySlot[$slotId])) {
                    $inner = $chromeBySlot[$slotId];

                    return $inner !== '' ? $inner : null;
                }

                return null;
            }
        } catch (\Throwable) {
            // treat as page slot
        }

        $pageHtml = (string)($fragments['page_html'] ?? '');
        if ($pageHtml === '') {
            return null;
        }

        try {
            /** @var SlotBoundaryScanner $scanner */
            $scanner = ObjectManager::getInstance(SlotBoundaryScanner::class);
            return self::composePublishedPageSlot($pageHtml, $slotId, $scanner);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Entity includes render flat slot fragments while CTX_SOLIDIFYING is set.
     * A parent widget therefore contains unfilled nested wrappers. Assemble those
     * wrappers from the same already-rendered entity before returning the parent;
     * no registry lookup, widget re-render, or request-time default injection.
     */
    private static function composePublishedPageSlot(
        string $pageHtml,
        string $slotId,
        SlotBoundaryScanner $scanner,
        array $visiting = [],
    ): ?string {
        if (isset($visiting[$slotId])) {
            return null;
        }
        $visiting[$slotId] = true;
        $entityRegions = [];
        foreach ($scanner->enumerateRegions($pageHtml, $slotId) as $region) {
            $wrapper = substr($pageHtml, $region['wrapper_open_start'], $region['wrapper_open_end'] - $region['wrapper_open_start']);
            if (preg_match('/\bclass=["\'][^"\']*\btheme-layout-entity-slot\b/', $wrapper) === 1) {
                $entityRegions[] = $region;
            }
        }
        if ($entityRegions !== []) {
            usort($entityRegions, static fn(array $a, array $b): int => ($a['depth'] <=> $b['depth']) ?: ($a['region_start'] <=> $b['region_start']));
            $region = $entityRegions[0];
            // Prefer the flat entity owner even when intentionally empty; a
            // nested default with the same ID must never override its decision.
            $inner = substr($pageHtml, $region['inner_start'], $region['inner_end'] - $region['inner_start']);
        } else {
            $inner = $scanner->extractSlotInner($pageHtml, $slotId, false, true);
            if ($inner === null || trim($inner) === '') {
                return null;
            }
        }
        $regions = $scanner->enumerateRegions($inner);
        usort($regions, static fn(array $a, array $b): int => $a['region_start'] <=> $b['region_start']);
        $replacements = [];
        $coveredUntil = -1;
        foreach ($regions as $region) {
            // Recurse through direct children only; their descendants are handled
            // in that call, so the replacements below stay disjoint.
            if ($region['region_start'] < $coveredUntil) {
                continue;
            }
            $coveredUntil = $region['region_end'];
            $child = self::composePublishedPageSlot($pageHtml, $region['id'], $scanner, $visiting);
            if ($child !== null) {
                $replacements[] = ['inner_start' => $region['inner_start'], 'inner_end' => $region['inner_end'], 'new_inner' => $child];
            }
        }
        return $scanner->replaceWrapperInners($inner, $replacements);
    }

    /**
     * @return array{page_html:string,chrome_by_slot:array<string,string>}|null
     */
    private static function loadFragments(): ?array
    {
        $cached = RequestContext::get(self::CTX_FRAGMENTS);
        if ($cached === false) {
            return null;
        }
        if (\is_array($cached)
            && isset($cached['page_html'])
            && \is_string($cached['page_html'])
            && isset($cached['chrome_by_slot'])
            && \is_array($cached['chrome_by_slot'])
        ) {
            return $cached;
        }

        // Re-entrant solidify: outer loadFragments is already including entity HTML.
        if (RequestContext::get(self::CTX_SOLIDIFYING) === true) {
            return null;
        }

        $themeId = (int)(RequestContext::get(self::CTX_THEME_ID) ?? 0);
        if ($themeId < 1) {
            try {
                $theme = ThemeData::getCurrentTheme();
                $themeId = (int)($theme?->getId() ?? 0);
            } catch (\Throwable) {
                $themeId = 0;
            }
        }
        // Not ready: do NOT sticky-cache CTX_FRAGMENTS=false (wave8-8s2).
        if ($themeId < 1) {
            return null;
        }

        $pageType = self::resolvePageTypeForFragments();
        if ($pageType === '') {
            return null;
        }

        RequestContext::set(self::CTX_SOLIDIFYING, true);
        $loadError = null;
        try {
            /** @var ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);
            $fragments = $filler->renderPublishedSolidifiedFragments($themeId, $pageType, 'frontend');
        } catch (\Throwable $e) {
            // wave8-8s4: include/deps not ready yet — DO NOT sticky-miss.
            // Sticky false here made useReactiveMarkers cache reactive=true for the
            // whole request even though LayoutSlot fill later succeeds with the same bake.
            $fragments = null;
            $loadError = $e;
        } finally {
            RequestContext::remove(self::CTX_SOLIDIFYING);
        }

        if ($loadError instanceof \Throwable) {
            RequestContext::set(self::CTX_PRIME_TRANSIENT_ERROR, $loadError->getMessage());

            return null;
        }

        if ($fragments === null) {
            // Definitive miss: resolver/file absent (not a thrown include).
            RequestContext::set(self::CTX_FRAGMENTS, false);

            return null;
        }

        RequestContext::remove(self::CTX_PRIME_TRANSIENT_ERROR);
        $fragments = self::sanitizePublishedFragments($fragments);
        RequestContext::set(self::CTX_FRAGMENTS, $fragments);

        return $fragments;
    }

    /**
     * @param array{page_html?:mixed,chrome_by_slot?:mixed} $fragments
     * @return array{page_html:string,chrome_by_slot:array<string,string>}
     */
    private static function sanitizePublishedFragments(array $fragments): array
    {
        $pageHtml = self::sanitizePublishedHtml((string)($fragments['page_html'] ?? ''));
        $chromeBySlot = [];
        $rawChrome = $fragments['chrome_by_slot'] ?? [];
        if (\is_array($rawChrome)) {
            foreach ($rawChrome as $slotId => $inner) {
                if (!\is_string($slotId) || !\is_string($inner)) {
                    continue;
                }
                $chromeBySlot[$slotId] = self::sanitizePublishedHtml($inner);
            }
        }

        return [
            'page_html' => $pageHtml,
            'chrome_by_slot' => $chromeBySlot,
        ];
    }

    private static function sanitizePublishedHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        // Keep boundary comments inside fragment cache (extractSlotInner needs them);
        // strip only reactive attributes that would re-arm LayoutSlot fill.
        return SlotBoundaryMarkers::stripReactiveSlotAttributes($html);
    }

    private static function resolvePageTypeForFragments(): string
    {
        $layoutType = \trim((string)(RequestContext::get(self::CTX_LAYOUT_TYPE) ?? ''));
        if ($layoutType === '') {
            try {
                /** @var ThemePageTypeResolver $resolver */
                $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
                /** @var Request $request */
                $request = ObjectManager::getInstance(Request::class);
                $layoutType = \trim($resolver->resolveLayoutType(null, null, $request, ''));
            } catch (\Throwable) {
                $layoutType = '';
            }
        }
        if ($layoutType === '') {
            return '';
        }

        try {
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
            $mapped = \trim($resolver->mapLayoutTypeToPageType($layoutType));

            return $mapped !== '' ? $mapped : $layoutType;
        } catch (\Throwable) {
            return $layoutType;
        }
    }

    private static function defaultCarriesNestedSlotMarkup(string $html): bool
    {
        return \str_contains($html, 'theme-layout-entity-slot')
            || \str_contains($html, 'theme-published-slot')
            || \str_contains($html, 'data-slot-id=');
    }

    /**
     * Layout-built policy/terms body under content (hero/sections) — must survive bake.
     */
    private static function defaultCarriesLayoutPolicyOrTermsBody(string $html): bool
    {
        return \str_contains($html, 'amazon-policy__')
            || \str_contains($html, 'amazon-terms__')
            || \str_contains($html, 'policy-main')
            || \str_contains($html, 'data-layout="policy-')
            || \str_contains($html, "data-layout='policy-")
            || \str_contains($html, 'data-layout="terms')
            || \str_contains($html, "data-layout='terms");
    }

    /**
     * Sparse content bake (homepage-only newsletter popup etc.) must prepend onto a
     * non-blank layout default — never wholesale replace.
     */
    private static function bakeIsSparseContentOverlay(string $bakeInner, string $defaultHtml): bool
    {
        if (self::isEffectivelyBlankSlotInner($defaultHtml)) {
            return false;
        }
        if (self::defaultCarriesLayoutPolicyOrTermsBody($bakeInner)) {
            return false;
        }
        if (\str_contains($bakeInner, 'homepage-hero')
            || \str_contains($bakeInner, '<!--@weline-slot:homepage-')
        ) {
            return false;
        }

        return \str_contains($bakeInner, 'newsletter-popup')
            || \str_contains($bakeInner, 'data-widget-code="newsletter')
            || \str_contains($bakeInner, "data-widget-code='newsletter");
    }
}
