<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Helper\WidgetI18n;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;
use Weline\Theme\Service\ThemeRuntimeLayoutResolver;

/**
 * Storefront hard-cut slot fill: include the solidified layout.phtml.
 * Slot membership is the file. Config/i18n overlays do not rebuild that file.
 */
final class ThemeLayoutEntitySlotFiller
{
    public function __construct(
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeLayoutEntityConfigStore $configStore,
        private readonly ThemeLayoutEntityChrome $chrome,
        private readonly SlotRendererService $slotRenderer,
        private readonly SlotBoundaryScanner $boundaryScanner,
        private readonly SharedChromeService $sharedChrome,
        private readonly ThemeRuntimeLayoutResolver $layoutResolver,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ?StorefrontScopeHotCache $hotCache = null,
    ) {
    }

    /**
     * wave8-8s5: render published solidified whole-shell for the slot host.
     * Prefer shell.phtml (chrome+page bake). Never call chrome_slot_projection HotCache
     * or injectChrome on this path — regeneration belongs to publish / injection-collect bake.
     * Editor/preview/bake still use {@see fill()} / materialize heavy paths.
     *
     * @return array{page_html:string,chrome_by_slot:array<string,string>}|null
     */
    public function renderPublishedSolidifiedFragments(
        int $themeId,
        string $pageType,
        string $area = 'frontend',
    ): ?array {
        if ($themeId < 1 || \trim($pageType) === '') {
            return null;
        }

        $this->resolveRequestedPreviewEntity($themeId, $pageType, $area);
        $scope = $this->resolveScope();
        $resolved = $this->resolvePageEntityLocation($themeId, $scope, $pageType, $area, true);
        if ($resolved === null) {
            return null;
        }

        $pageScope = $resolved['scope'];
        $identityKey = $resolved['identity_key'];
        $structureOrRelease = $resolved['structure_or_release'];
        $binding = $this->readPageBinding($themeId, $pageScope, $identityKey, $structureOrRelease);
        $paths = $this->paths;
        ThemeLayoutStorefrontHeadAssets::rememberPointer([
            'theme_id' => $themeId,
            'scope' => $pageScope,
            'identity_key' => $identityKey,
            'structure_or_release' => $structureOrRelease,
            'binding' => $binding,
        ]);
        $shellPhtml = $paths->shellPhtml($themeId, $pageScope, $identityKey, $structureOrRelease);
        $phtml = $binding?->templatePath ?? $paths->pagePhtml($themeId, $pageScope, $identityKey, $structureOrRelease);
        $shellPhtml = $binding?->shellPath ?: $shellPhtml;

        $this->primeLocaleConfigs(
            $themeId,
            $pageType,
            ThemeLayout::STATUS_PUBLISHED,
            $area,
            $pageScope,
            $identityKey,
            $structureOrRelease,
            $binding,
        );

        // Prefer whole-shell include (header/chrome already baked in).
        if (\is_file($shellPhtml)) {
            $pageHtml = $this->includeEntityPhtml($shellPhtml, $binding);
            $chromeBySlot = $this->extractChromeInnersFromBakedHtml($pageHtml);
            if ($binding !== null) {
                // A page can inherit its structure from a parent while chrome has
                // request-scope overlays. Compose only already compiled chrome.
                $chromeBySlot = $this->buildChromeSlotProjection($themeId, $scope, false);
                return ['page_html' => $pageHtml, 'chrome_by_slot' => $chromeBySlot];
            }
            // wave9-9s4: many "whole-shell" bakes are page-slot only (list-filters /
            // homepage-*), so chrome_by_slot is empty and heal graft is a no-op.
            // Fall back to published chrome.phtml disk bake (禁 storefront_chrome Policy).
            if ($chromeBySlot === []) {
                $chromeHtml = $this->loadPublishedChromeBakeHtmlDirect($themeId, $pageScope !== '' ? $pageScope : $scope);
                if ($chromeHtml === '') {
                    $chromeHtml = $this->loadPublishedChromeBakeHtmlDirect($themeId, $scope);
                }
                if ($chromeHtml !== '') {
                    $chromeBySlot = $this->extractChromeInnersFromBakedHtml($chromeHtml);
                }
            }

            return [
                'page_html' => $pageHtml,
                'chrome_by_slot' => $chromeBySlot,
            ];
        }

        if (!\is_file($phtml)) {
            return null;
        }

        // Legacy published page without shell yet: include page + chrome.phtml disk bake
        // (no chrome_slot_projection Policy / remember).
        $pageHtml = $this->includeEntityPhtml($phtml, $binding);
        $chromeHtml = $this->loadPublishedChromeBakeHtmlDirect($themeId, $scope);
        if ($chromeHtml !== '') {
            $pageHtml = $chromeHtml . $pageHtml;
        }
        $chromeBySlot = $this->extractChromeInnersFromBakedHtml($chromeHtml !== '' ? $chromeHtml : $pageHtml);

        return [
            'page_html' => $pageHtml,
            'chrome_by_slot' => $chromeBySlot,
        ];
    }

    /**
     * Fill shell HTML by including the solidified page layout.phtml (published and draft).
     *
     * @throws \RuntimeException when the solidified page template is missing (storefront hard fail)
     */
    public function fill(
        string $html,
        int $themeId,
        string $pageType,
        string $status = ThemeLayout::STATUS_PUBLISHED,
        string $area = 'frontend',
    ): string {
        if ($html === '' || $themeId < 1) {
            throw new \RuntimeException('theme_layout_entity_fill_invalid');
        }

        $versionPreview = $this->resolveRequestedPreviewEntity($themeId, $pageType, $area);
        $preview = $status === ThemeLayout::STATUS_DRAFT || $versionPreview !== null;
        $needsSafetyNet = !$preview
            && ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html);
        // wave8-8s5: published solidified shells skip fill; leftover required placeholders
        // / incomplete chrome (wave9-9s2) are the narrow safety-net exception.
        if (!$preview && !$needsSafetyNet) {
            return $html;
        }

        $hasReactiveMarkers = \str_contains($html, 'data-wslot')
            || \str_contains($html, 'widget-slot-area');
        if (!$hasReactiveMarkers && !$needsSafetyNet) {
            return $html;
        }

        $scope = $this->resolveScope();
        // wave8-8s5: do not injectChrome on published safety-net (禁 storefront_chrome 布局再生).
        // Live injectChrome walks ancestor scopes and can OOM (footer-container ≈270MB).
        // Incomplete shells heal via chrome.rendered snapshot splice below / nested fill.
        if ($preview) {
            try {
                $html = $this->injectChromeSlots($html, $themeId, $scope, $preview);
                $html = $this->fillNestedChromeExtensionSlots($html, $themeId, $scope, $preview);
            } catch (\Throwable $chromeError) {
                if (\function_exists('w_log_warning')) {
                    \w_log_warning(
                        'theme_layout_entity_chrome_soft_skip: ' . $chromeError->getMessage(),
                        [
                            'theme_id' => $themeId,
                            'scope' => $scope,
                            'page_type' => $pageType,
                        ],
                        'theme_layout_entity',
                    );
                }
            }
        } elseif ($needsSafetyNet) {
            try {
                $html = $this->fillNestedChromeExtensionSlots($html, $themeId, $scope, false);
            } catch (\Throwable) {
                // soft — healPublishedPlaceholderShell may retry
            }
        }

        $published = !$preview;
        $resolved = $this->resolvePageEntityLocation(
            $themeId,
            $scope,
            $pageType,
            $area,
            $published,
        );
        if ($resolved === null) {
            // 布局固化与默认注入 §3.1: 无固化模板 → 当前主题运行期动态固化（仍 merge 无卸载默认注入）。
            $solidified = $this->tryDynamicSolidifyMissingPage($themeId, $scope, $pageType, $area, $status);
            if ($solidified !== null) {
                $resolved = $solidified;
            } else {
                // Soft safety-net only when dynamic solidify cannot run.
                return \Weline\Framework\Manager\ObjectManager::getInstance(RequiredDefaultInjectionStorefrontOverlay::class)
                    ->append($html, $themeId, $pageType, $status, $scope, 'required', null);
            }
        }

        $pageScope = $resolved['scope'];
        $identityKey = $resolved['identity_key'];
        $structureOrRelease = $resolved['structure_or_release'];
        $binding = $this->readPageBinding($themeId, $pageScope, $identityKey, $structureOrRelease);
        $phtml = $binding?->templatePath ?? $this->paths->pagePhtml($themeId, $pageScope, $identityKey, $structureOrRelease);
        if (!\is_file($phtml)) {
            $rebuilt = $this->tryRematerializeMissingPhtml(
                $themeId,
                $pageScope,
                $identityKey,
                $structureOrRelease,
                $pageType,
            );
            if ($rebuilt !== '' && \is_file($rebuilt)) {
                $binding = $this->readPageBinding($themeId, $pageScope, $identityKey, $structureOrRelease);
                $phtml = $binding?->templatePath ?? $rebuilt;
            } else {
                throw new \RuntimeException('theme_layout_entity_phtml_missing: ' . $phtml);
            }
        }

        $this->primeLocaleConfigs(
            $themeId,
            $pageType,
            $status,
            $area,
            $pageScope,
            $identityKey,
            $structureOrRelease,
            $binding,
        );

        ThemeLayoutStorefrontHeadAssets::rememberPointer([
            'theme_id' => $themeId, 'scope' => $pageScope, 'identity_key' => $identityKey,
            'structure_or_release' => $structureOrRelease, 'binding' => $binding,
        ]);
        $rendered = $this->includeEntityPhtml($phtml, $binding);
        if ($binding !== null) {
            return $this->spliceSolidifiedSlots($html, $rendered);
        }
        $structurePath = $this->paths->pageStructureJson($themeId, $pageScope, $identityKey, $structureOrRelease);
        $rendered = \Weline\Framework\Manager\ObjectManager::getInstance(RequiredDefaultInjectionStorefrontOverlay::class)
            ->append(
                $rendered,
                $themeId,
                $pageType,
                $status,
                $pageScope,
                $structureOrRelease,
                $structurePath,
            );
        if ($rendered === '') {
            return $html;
        }

        $html = $this->spliceSolidifiedSlots($html, $rendered);
        // Nested empty placeholders inside container widgets can survive splice when the
        // page-level slot was empty/incomplete. Exception path only: re-run overlay when
        // inventory destinations still lack widgets after splice (not default discovery).
        if (!$this->shellMissingRequiredInjections($html, $pageType)) {
            return $html;
        }

        return \Weline\Framework\Manager\ObjectManager::getInstance(RequiredDefaultInjectionStorefrontOverlay::class)
            ->append(
                $html,
                $themeId,
                $pageType,
                $status,
                $pageScope,
                $structureOrRelease,
                $structurePath,
            );
    }

    /**
     * True when any slot region for a required injection lacks widget markers
     * (nested duplicate slot ids: any empty region triggers a second overlay pass).
     */
    private function shellMissingRequiredInjections(string $html, string $pageType): bool
    {
        $pageType = \trim($pageType);
        if ($html === '' || $pageType === '' || !\str_contains($html, '<!--@weline-slot:')) {
            return false;
        }

        try {
            /** @var \Weline\Widget\Service\DefaultInjectionPlanRepository $plans */
            $plans = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Widget\Service\DefaultInjectionPlanRepository::class,
            );
            $declarations = $plans->listDeclarations('frontend');
            foreach (RequiredDefaultInjectionContract::requiredTargets($declarations, $pageType) as $target) {
                $slotId = \trim((string)($target['slot_id'] ?? ''));
                if ($slotId === '') {
                    continue;
                }
                $module = (string)($target['widget_module'] ?? '');
                $code = (string)($target['widget_code'] ?? '');
                if ($this->anySlotRegionMissingWidget($html, $slotId, $module, $code)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'required_default_injection_shell_scan_failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return false;
    }

    /**
     * True when the slot exists in the shell but no region yet contains the widget
     * (duplicate slot-id empty siblings do not count as "missing" if one region already has it).
     */
    private function anySlotRegionMissingWidget(
        string $html,
        string $slotId,
        string $module,
        string $code,
    ): bool {
        $regions = $this->boundaryScanner->enumerateRegions($html, $slotId);
        if ($regions !== []) {
            $sawRegion = false;
            $anyHas = false;
            foreach ($regions as $region) {
                if (!\is_array($region)) {
                    continue;
                }
                $innerStart = (int)($region['inner_start'] ?? -1);
                $innerEnd = (int)($region['inner_end'] ?? -1);
                if ($innerStart < 0 || $innerEnd < $innerStart) {
                    continue;
                }
                $sawRegion = true;
                $inner = \substr($html, $innerStart, $innerEnd - $innerStart);
                if (RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                    $anyHas = true;
                    break;
                }
            }
            if ($sawRegion) {
                return !$anyHas;
            }
        }

        $inner = $this->extractSlotInnerForPresence($html, $slotId);
        if ($inner === null) {
            return false;
        }

        return !RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code);
    }

    /**
     * Soft degrade / chrome-only paths: still enforce 有部件必入声明槽 on the shell HTML.
     */
    public function fillRequiredDefaultsOnShell(
        string $html,
        int $themeId,
        string $pageType,
        string $status,
        string $scope = 'default',
    ): string {
        if ($html === '' || $themeId < 1 || \trim($pageType) === '') {
            return $html;
        }
        // required-default-always-present: published / solidified / complete chrome must NOT
        // hard no-op required overlay. Only user_deleted@{versionId} omits plan items
        // (inside RequiredDefaultInjectionStorefrontOverlay). Bake may still embed widgets;
        // overlay is identity XOR + empty-slot fill, not a published skip gate.

        return \Weline\Framework\Manager\ObjectManager::getInstance(RequiredDefaultInjectionStorefrontOverlay::class)
            ->append($html, $themeId, $pageType, $status, $scope, 'required', null);
    }

    /**
     * Bake-time finalize for chrome.rendered snapshots (wave8-8s5 固化完备).
     * chrome.phtml emits flat @weline-slot projections with real widgets, while
     * footer-container nested theme-published-slot may still hold missing-config
     * stubs. Promote longest non-blank marker inners into blank published slots
     * before the durable snapshot is written — storefront zero-fill must not
     * re-run Overlay at controller/runtime.
     */
    public function finalizePublishedChromeRenderedHtml(string $html, int $themeId = 0, bool $structureComplete = false): string
    {
        if ($html === '') {
            return $html;
        }
        // 输入是chrome模板本身，所有已渲染边界均来自写侧筛选的chrome节点。
        $slotIds = $this->chromeSlotIdsFromRenderedHtml($html, true);
        if ($slotIds !== []) {
            // Prefer longest non-blank @weline-slot inner across duplicates.
            $html = $this->composeChromeSlotTree($html, $slotIds);
        }

        $chromeBySlot = [];
        foreach ($slotIds as $slotId) {
            $inner = $this->boundaryScanner->extractSlotInner($html, $slotId);
            if ($inner === null || $this->isBlankChromeInner($inner)) {
                // Prefer longest non-blank marker duplicate when extract picked a stub.
                $best = '';
                foreach ($this->extractAllSlotInners($html, $slotId) as $candidate) {
                    if ($this->isBlankChromeInner($candidate)) {
                        continue;
                    }
                    if (\strlen($candidate) > \strlen($best)) {
                        $best = $candidate;
                    }
                }
                if ($best === '') {
                    continue;
                }
                $inner = $best;
            }
            $chromeBySlot[$slotId] = $inner;
        }
        foreach ($chromeBySlot as $slotId => $inner) {
            // Marker extractSlotInnerForPresence may HIT the good projection and
            // skip spliceChromeSlotsFromBake — still replace blank theme-published-slot
            // attribute wrappers in the footer-container DOM.
            $html = $this->replaceBlankAttributeSlotInners($html, $slotId, $inner);
        }
        if ($chromeBySlot !== []) {
            $html = $this->spliceChromeSlotsFromBake($html, $chromeBySlot);
        }

        // 新结构已在写侧纳入必装部件；这里只组合已渲染槽，不再查当前版本补结构。
        if ($themeId > 0 && !$structureComplete) {
            $html = $this->fillRequiredDefaultsOnShell(
                $html, $themeId, ThemeLayout::PAGE_TYPE_HOME, ThemeLayout::STATUS_PUBLISHED,
            );
        }

        if ($slotIds !== []) {
            $html = '<!--@weline-chrome-slots:' . implode(',', $slotIds) . '-->' . $html;
        }
        return $html;
    }

    /**
     * Replace blank data-slot-id / theme-published-slot wrappers for $slotId.
     * Does not touch non-blank regions (incl. good @weline-slot projections).
     */
    private function replaceBlankAttributeSlotInners(string $html, string $slotId, string $inner): string
    {
        $slotId = \strtolower(\trim($slotId));
        $inner = (string)$inner;
        if ($html === '' || $slotId === '' || \trim($inner) === '') {
            return $html;
        }

        $pattern = '/(<div\b[^>]*\bdata-slot-id="'
            . \preg_quote($slotId, '/')
            . '"[^>]*>)(.*?)(<\/div>)/is';
        $replaced = \preg_replace_callback(
            $pattern,
            function (array $m) use ($inner): string {
                $existing = (string)($m[2] ?? '');
                if (!$this->isBlankChromeInner($existing)) {
                    return (string)$m[0];
                }

                return (string)$m[1] . $inner . (string)$m[3];
            },
            $html,
        );

        return \is_string($replaced) ? $replaced : $html;
    }

    /**
     * wave9-9s6: durable chrome.rendered.* splice without prime / fill / include.
     * LayoutSlot calls this before prime so empty footer--shell can solidify into
     * +skip_fill_solidified (≪100ms) when a snapshot exists (incl. global theme fallback).
     */
    public function prefillPublishedChromeFromRenderedSnapshot(string $html, int $themeId): string
    {
        if ($html === '' || $themeId < 1) {
            return $html;
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        try {
            $fromDisk = $this->chromeSlotProjectionFromRenderedSnapshot($themeId, $this->resolveScope());
            if ($fromDisk === []) {
                $fallbackThemeId = $this->resolveGlobalActiveThemeId();
                if ($fallbackThemeId > 0 && $fallbackThemeId !== $themeId) {
                    $fromDisk = $this->chromeSlotProjectionFromRenderedSnapshot(
                        $fallbackThemeId,
                        $this->resolveScope(),
                    );
                }
            }
            if ($fromDisk !== []) {
                $html = $this->spliceChromeSlotsFromBake($html, $fromDisk);
            }
        } catch (\Throwable) {
            // soft
        }

        return $html;
    }

    /**
     * wave8-8s5+safety / wave9-9s2: heal published shells that are incomplete
     * (placeholders, empty chrome/required slots, missing header signals).
     * Order: fill(splice) → required overlay → project solidified fragments
     * → splice chrome_by_slot / nested chrome extensions from disk bake.
     * Runtime heal is a safety net; lasting fix = rebake shell on publish/injection-collect.
     */
    public function healPublishedPlaceholderShell(
        string $html,
        int $themeId,
        string $pageType,
        string $area = 'frontend',
    ): string {
        if ($html === '' || $themeId < 1 || \trim($pageType) === '') {
            return $html;
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        // Prefer durable chrome.rendered.* snapshot BEFORE live fill/include —
        // empty footer shells with header already present are the common path;
        // live footer-container render peaks ~270MB and OOMs default 256MB workers.
        try {
            $fromDisk = $this->chromeSlotProjectionFromRenderedSnapshot($themeId, $this->resolveScope());
            // Frontend area may resolve to Default while Global/website chrome lives on
            // another theme (hanfu) — try global active when leaf theme has no snapshot.
            if ($fromDisk === []) {
                $fallbackThemeId = $this->resolveGlobalActiveThemeId();
                if ($fallbackThemeId > 0 && $fallbackThemeId !== $themeId) {
                    $fromDisk = $this->chromeSlotProjectionFromRenderedSnapshot(
                        $fallbackThemeId,
                        $this->resolveScope(),
                    );
                }
            }
            if ($fromDisk !== []) {
                $html = $this->spliceChromeSlotsFromBake($html, $fromDisk);
            }
        } catch (\Throwable) {
            // soft
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        try {
            $html = $this->fill($html, $themeId, $pageType, ThemeLayout::STATUS_PUBLISHED, $area);
        } catch (\Throwable) {
            // soft — overlay / fragment projection below
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        try {
            $html = $this->fillRequiredDefaultsOnShell(
                $html,
                $themeId,
                $pageType,
                ThemeLayout::STATUS_PUBLISHED,
            );
        } catch (\Throwable) {
            // soft
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        $chromeBySlot = [];
        try {
            $fragments = $this->renderPublishedSolidifiedFragments($themeId, $pageType, $area);
            $pageHtml = \is_array($fragments) ? (string)($fragments['page_html'] ?? '') : '';
            if ($pageHtml !== '') {
                $html = $this->spliceSolidifiedSlots($html, $pageHtml);
            }
            $rawChrome = \is_array($fragments) ? ($fragments['chrome_by_slot'] ?? []) : [];
            if (\is_array($rawChrome)) {
                foreach ($rawChrome as $slotId => $inner) {
                    if (\is_string($slotId) && \is_string($inner) && \trim($inner) !== '') {
                        $chromeBySlot[$slotId] = $inner;
                    }
                }
            }
        } catch (\Throwable) {
            // soft
        }
        // wave9-9s4: fragments may still leave chrome_by_slot empty (page-only shell).
        if ($chromeBySlot === []) {
            try {
                $chromeHtml = $this->loadPublishedChromeBakeHtmlDirect($themeId, $this->resolveScope());
                if ($chromeHtml !== '') {
                    $chromeBySlot = $this->extractChromeInnersFromBakedHtml($chromeHtml);
                }
            } catch (\Throwable) {
                // soft
            }
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        try {
            $html = $this->spliceChromeSlotsFromBake($html, $chromeBySlot);
        } catch (\Throwable) {
            // soft
        }
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return $html;
        }

        // Nested chrome extensions from published payload (disk), not storefront_chrome Policy.
        try {
            $html = $this->fillNestedChromeExtensionSlots(
                $html,
                $themeId,
                $this->resolveScope(),
                false,
            );
        } catch (\Throwable) {
            // soft
        }

        return $html;
    }

    /**
     * Load chrome.rendered.{locale}.html for published chrome and extract slot inners.
     * Never includes chrome.phtml (avoids Phrase/parser OOM on heavy footer-container).
     *
     * @return array<string, string>
     */
    private function chromeSlotProjectionFromRenderedSnapshot(int $themeId, string $scope): array
    {
        if ($themeId < 1 || \trim($scope) === '') {
            return [];
        }
        try {
            $pointer = \Weline\Framework\Manager\ObjectManager::getInstance(
                ThemeLayoutEntityPointerResolver::class,
            )->resolvePublishedChrome($themeId, $scope);
            // Leaf storefront scopes often resolve to default.*; website chrome snapshot
            // may live only under the published ancestor — climb when leaf has no file.
            if ((!is_array($pointer) || (string)($pointer['path'] ?? '') === '' || !\is_file((string)($pointer['path'] ?? '')))
                && $scope !== 'default.__website__.default'
            ) {
                $pointer = \Weline\Framework\Manager\ObjectManager::getInstance(
                    ThemeLayoutEntityPointerResolver::class,
                )->resolvePublishedChrome($themeId, 'default.__website__.default');
            }
        } catch (\Throwable) {
            return [];
        }
        $path = \is_array($pointer) ? (string)($pointer['path'] ?? '') : '';
        if ($path === '' || !\is_file($path)) {
            return [];
        }
        $dir = \dirname($path);
        $locale = '';
        try {
            $locale = \trim((string)\Weline\Theme\Helper\WidgetI18n::storefrontLocale());
        } catch (\Throwable) {
            $locale = '';
        }
        if ($locale === '' || \preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale) !== 1) {
            $locale = 'zh_Hans_CN';
        }
        $candidates = [
            $dir . \DIRECTORY_SEPARATOR . 'chrome.rendered.' . $locale . '.html',
        ];
        // Never fall back to another locale's snapshot (en_US→zh froze 「Ship to」).
        // Legacy locale-agnostic file only when no per-locale snapshot exists.
        $legacy = $dir . \DIRECTORY_SEPARATOR . 'chrome.rendered.html';
        if (\is_file($legacy)) {
            $candidates[] = $legacy;
        }
        $html = '';
        foreach ($candidates as $candidate) {
            if (\is_file($candidate)) {
                $html = (string)@\file_get_contents($candidate);
                if ($html !== '') {
                    // Reject English delivery label in non-en locale snapshots.
                    if ($locale !== 'en_US' && $locale !== 'en_GB' && !\str_starts_with($locale, 'en_')
                        && \str_contains($html, 'delivery-line-1">Ship to')
                    ) {
                        $html = '';
                        if (\str_ends_with($candidate, 'chrome.rendered.' . $locale . '.html')) {
                            @\unlink($candidate);
                        }
                        continue;
                    }
                    break;
                }
            }
        }
        if ($html === '') {
            return [];
        }

        $best = [];
        if (\preg_match_all('/<!--@weline-slot:([\w.-]+)-->/', $html, $matches) > 0) {
            foreach (\array_unique($matches[1]) as $slotId) {
                $slotId = \strtolower(\trim((string)$slotId));
                if ($slotId === '') {
                    continue;
                }
                try {
                    if (!$this->sharedChrome->isChromeSlot($slotId)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
                $inner = $this->boundaryScanner->extractSlotInner($html, $slotId);
                if ($inner === null || $this->isBlankChromeInner($inner)) {
                    continue;
                }
                $best[$slotId] = $inner;
            }
        }

        return $best;
    }

    /**
     * wave9-9s2: project non-empty chrome bake inners into empty chrome destinations.
     * Does not call injectChrome / chrome_slot_projection / storefront_chrome Policy.
     *
     * @param array<string, string> $chromeBySlot
     */
    private function spliceChromeSlotsFromBake(string $html, array $chromeBySlot): string
    {
        if ($html === '' || $chromeBySlot === []) {
            return $html;
        }

        foreach ($chromeBySlot as $slotId => $inner) {
            $slotId = \strtolower(\trim((string)$slotId));
            $inner = (string)$inner;
            if ($slotId === '' || \trim($inner) === '') {
                continue;
            }
            try {
                if (!$this->sharedChrome->isChromeSlot($slotId)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $existing = $this->extractSlotInnerForPresence($html, $slotId);
            if ($existing !== null && !$this->isBlankChromeInner($existing) && \trim(\strip_tags($existing)) !== '') {
                continue;
            }

            $regions = $this->boundaryScanner->enumerateRegions($html, $slotId);
            if ($regions === []) {
                // Published zero-fill strips <!--@weline-slot:*--> markers; still replace
                // theme-published-slot / data-slot-id wrappers when present.
                $bounds = $this->boundaryScanner->findSlotWrapperBounds($html, $slotId);
                if (\is_array($bounds)) {
                    $regions = [$bounds];
                }
            }
            if ($regions === []) {
                // wave9-9s3: showHeader/showFooter off left zero chrome destinations —
                // splice cannot fill what is absent. Graft published wrappers so
                // outbound still carries footer / header-nav-extensions / delivery.
                $html = $this->graftMissingChromePublishedSlot($html, $slotId, $inner);
                continue;
            }
            \usort(
                $regions,
                static fn(array $a, array $b): int => ((int)($b['inner_start'] ?? 0) <=> (int)($a['inner_start'] ?? 0)),
            );
            foreach ($regions as $region) {
                if (!\is_array($region)) {
                    continue;
                }
                $start = (int)($region['inner_start'] ?? -1);
                $end = (int)($region['inner_end'] ?? -1);
                if ($start < 0 || $end < $start) {
                    continue;
                }
                $html = \substr($html, 0, $start) . $inner . \substr($html, $end);
            }
        }

        return $html;
    }

    /**
     * Insert a published chrome slot wrapper when the live shell has no destination
     * region for that slotId (Partials chrome gated off). Prefer before <main for
     * header/delivery, after </main> for footer*.
     */
    private function graftMissingChromePublishedSlot(string $html, string $slotId, string $inner): string
    {
        if ($html === '' || $slotId === '' || \trim($inner) === '') {
            return $html;
        }
        if (\str_contains($html, 'data-slot-id="' . $slotId . '"')
            || \str_contains($html, "data-slot-id='" . $slotId . "'")
        ) {
            return $html;
        }

        $wrapper = '<!--@weline-slot:' . $slotId . '-->'
            . '<div class="theme-published-slot" data-slot-id="' . \htmlspecialchars($slotId, \ENT_QUOTES | \ENT_HTML5, 'UTF-8') . '">'
            . $inner
            . '</div>'
            . '<!--@/weline-slot:' . $slotId . '-->';

        $preferBeforeMain = $slotId === 'delivery'
            || $slotId === 'header'
            || \str_starts_with($slotId, 'header-');
        if ($preferBeforeMain) {
            if (\preg_match('/<main\b[^>]*>/i', $html, $m, \PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int)$m[0][1];

                return \substr($html, 0, $pos) . $wrapper . \substr($html, $pos);
            }
            if (\preg_match('/<div[^>]*\bweline-page-wrapper\b[^>]*>/i', $html, $m, \PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int)$m[0][1] + \strlen($m[0][0]);

                return \substr($html, 0, $pos) . $wrapper . \substr($html, $pos);
            }
        }

        if (\preg_match('/<\/main>/i', $html, $m, \PREG_OFFSET_CAPTURE) === 1) {
            $pos = (int)$m[0][1] + \strlen($m[0][0]);

            return \substr($html, 0, $pos) . $wrapper . \substr($html, $pos);
        }

        return $html . $wrapper;
    }

    /**
     * Prefer wrapper-aware scan; fall back to raw boundary markers when the slot
     * has markers but no data-wslot wrapper (empty nested compile output).
     */
    private function extractSlotInnerForPresence(string $html, string $slotId): ?string
    {
        $inner = $this->boundaryScanner->extractSlotInner($html, $slotId, false, true);
        if ($inner !== null) {
            return $inner;
        }
        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $openPos = \strpos($html, $open);
        if ($openPos === false) {
            return null;
        }
        $innerStart = $openPos + \strlen($open);
        $closePos = \strpos($html, $close, $innerStart);
        if ($closePos === false) {
            return null;
        }

        return \substr($html, $innerStart, $closePos - $innerStart);
    }

    /**
     * Soft path for LayoutSlotRenderer when page entity fill fails: still inject chrome.
     * wave8-8s5: published storefront ($preview=false) is a no-op — chrome already in shell bake.
     */
    public function fillChromeOnly(
        string $html,
        int $themeId,
        string $area = 'frontend',
        bool $preview = false,
    ): string {
        unset($area);
        if ($html === '' || $themeId < 1) {
            return $html;
        }
        if (!$preview) {
            return $html;
        }

        $scope = $this->resolveScope();
        $html = $this->injectChromeSlots($html, $themeId, $scope, $preview);

        return $this->fillNestedChromeExtensionSlots($html, $themeId, $scope, $preview);
    }

    /**
     * Fill empty nested chrome extension slots (footer-*-links, header-nav-extensions, …)
     * from ThemeScopeVersion chrome payload after root chrome inject.
     *
     * Channel scopes often only bake overlay widgets (footer-extras). Ancestor scopes
     * still own footer-container extension links — merge ancestor→leaf before fill.
     */
    private function fillNestedChromeExtensionSlots(
        string $html,
        int $themeId,
        string $scope,
        bool $preview,
    ): string {
        if ($html === '' || $themeId < 1) {
            return $html;
        }

        $boundChrome = RequestContext::get('theme.layout_entity.rendered_chrome_binding');
        if ($boundChrome instanceof EntityRenderBinding && $boundChrome->themeId === $themeId) {
            return $html;
        }

        try {
            /** @var \Weline\Theme\Service\ThemeScopeVersionService $scopeVersions */
            $scopeVersions = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemeScopeVersionService::class,
            );
            /** @var ThemeLayoutSlotTreeBuilder $slotTree */
            $slotTree = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutSlotTreeBuilder::class);

            $bySlot = [];
            $configByUid = [];
            // Nearest → ancestor: first non-empty nested slot wins (channel overlays
            // must not hide parent footer-help-links / payment links).
            foreach ($this->scopeFallbackChain($scope) as $candidateScope) {
                $candidate = $preview
                    ? ($scopeVersions->getCurrent($themeId, $candidateScope)
                        ?? $scopeVersions->getPublished($themeId, $candidateScope))
                    : ($scopeVersions->getPublished($themeId, $candidateScope)
                        ?? $scopeVersions->getCurrent($themeId, $candidateScope));
                if ($candidate === null || $candidate->getChromePayload() === []) {
                    continue;
                }
                $layout = $slotTree->nodesToAreaLayout($candidate->getChromePayload());
                $candidateBySlot = $slotTree->organizeWidgetsBySlot($layout);
                $candidateConfig = $this->configStore->readChromeConfig(
                    $themeId,
                    $candidateScope,
                    $candidate->getVersionId(),
                );
                foreach ($candidateBySlot as $slotId => $widgets) {
                    $slotId = \strtolower(\trim((string)$slotId));
                    if ($slotId === '' || \in_array($slotId, SharedChromeService::CHROME_SLOTS, true)) {
                        continue;
                    }
                    if (!$this->sharedChrome->isChromeSlot($slotId)) {
                        continue;
                    }
                    if ($widgets === [] || isset($bySlot[$slotId])) {
                        continue;
                    }
                    $bySlot[$slotId] = $widgets;
                    foreach ($widgets as $widget) {
                        if (!\is_array($widget)) {
                            continue;
                        }
                        $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                        if ($uid !== '' && isset($candidateConfig[$uid]) && \is_array($candidateConfig[$uid])) {
                            $configByUid[$uid] = $candidateConfig[$uid];
                        }
                    }
                }
            }
            if ($bySlot === []) {
                return $html;
            }

            foreach ($bySlot as $slotId => $widgets) {
                $slotId = \strtolower(\trim((string)$slotId));
                if ($slotId === '' || \in_array($slotId, SharedChromeService::CHROME_SLOTS, true)) {
                    continue;
                }
                if (!$this->sharedChrome->isChromeSlot($slotId)) {
                    continue;
                }
                if ($widgets === []) {
                    continue;
                }

                $bounds = $this->boundaryScanner->findSlotWrapperBounds($html, $slotId);
                if ($bounds === null) {
                    $inner = $this->boundaryScanner->extractSlotInner($html, $slotId);
                    if ($inner === null) {
                        continue;
                    }
                    if (!$this->isBlankChromeInner($inner) && \trim(\strip_tags($inner)) !== '') {
                        continue;
                    }
                    $rendered = $this->renderChromeSlotWidgets($widgets, $configByUid);
                    if ($rendered === '') {
                        continue;
                    }
                    $html = $this->replaceSlotInner($html, $slotId, $rendered);
                    continue;
                }

                $inner = \substr(
                    $html,
                    (int)$bounds['inner_start'],
                    (int)$bounds['inner_end'] - (int)$bounds['inner_start'],
                );
                if (!$this->isBlankChromeInner($inner) && \trim(\strip_tags($inner)) !== '') {
                    continue;
                }

                $rendered = $this->renderChromeSlotWidgets($widgets, $configByUid);
                if ($rendered === '') {
                    continue;
                }
                $html = $this->boundaryScanner->replaceWrapperInner($html, $bounds, $rendered);
            }
        } catch (\Throwable) {
            return $html;
        }

        return $html;
    }

    /**
     * @param list<array<string, mixed>> $widgets
     * @param array<string, mixed> $configByUid
     */
    private function renderChromeSlotWidgets(array $widgets, array $configByUid): string
    {
        try {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $registry */
            $registry = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemePlaceableRegistry::class,
            );
            /** @var \Weline\Theme\Service\ThemeComponentRenderer $renderer */
            $renderer = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemeComponentRenderer::class,
            );
        } catch (\Throwable) {
            return '';
        }

        $html = '';
        foreach ($widgets as $widget) {
            if (!\is_array($widget)) {
                continue;
            }
            if (\array_key_exists('is_active', $widget) && empty($widget['is_active'])) {
                continue;
            }
            $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
            $entry = $uid !== '' && isset($configByUid[$uid]) && \is_array($configByUid[$uid])
                ? $configByUid[$uid]
                : $widget;
            $module = \trim((string)($entry['widget_module'] ?? $widget['widget_module'] ?? ''));
            $code = \trim((string)($entry['widget_code'] ?? $widget['widget_code'] ?? ''));
            $type = \trim((string)($entry['widget_type'] ?? $widget['widget_type'] ?? ''));
            if ($module === '' || $code === '') {
                continue;
            }
            $config = \is_array($entry['config'] ?? null) ? $entry['config'] : [];
            if ($uid !== '') {
                $config['_widget_instance_key'] = $uid;
            }
            try {
                $definition = $registry->find($module, $type, $code, null, 'frontend');
                if ($definition === null) {
                    continue;
                }
                $piece = (string)$renderer->render($definition, $config, null, ['area' => 'frontend']);
                if ($piece !== '') {
                    $html .= $piece;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $html;
    }

    private function injectChromeSlots(string $html, int $themeId, string $scope, bool $preview): string
    {
        return (string)\Weline\Framework\Runtime\RequestLifecycleTrace::measurePhase(
            \Weline\Theme\Service\ThemeLayoutBudgetPhases::L1_CHROME,
            function () use ($html, $themeId, $scope, $preview): string {
                // Channel (or other leaf) chrome may only bake a subset of slots (e.g. only
                // footer-extras). Walk ancestors and keep the nearest non-blank inner per
                // slot so header-nav-extensions / footer-about-links still receive Blog widgets.
                $bestInnerBySlot = $preview
                    ? $this->buildChromeSlotProjection($themeId, $scope, true)
                    : $this->rememberPublishedChromeSlotProjection($themeId, $scope);
                if ($bestInnerBySlot === []) {
                    return $html;
                }

                // Nested chrome slots are siblings in chrome.phtml while the page shell
                // keeps markers under header/footer. Fill by shell layout position only
                // (deeper first, then document order) — never by slot-id name/length.
                foreach ($this->orderSlotsByShellLayout($html, \array_keys($bestInnerBySlot)) as $slotId) {
                    $html = $this->replaceSlotInner($html, $slotId, $bestInnerBySlot[$slotId]);
                }

                return $html;
            },
            ['theme_id' => $themeId, 'scope' => $scope, 'preview' => $preview],
        );
    }

    /**
     * wave8-8c6/8c8: prime chrome slot projection HotCache for deferred warmup.
     * When chrome bake is missing, fail-open remember [] under the live logical key
     * so near-virgin probes HIT and skip the ~650ms builder (not a fake FPC HIT).
     * Locale comes from StorefrontCacheKeyContext (same as Policy vary), not WidgetI18n alone.
     *
     * P5-O1: prefer {@see rememberHonestEmptyChromeSlotProjection()} on known chrome_rendered
     * miss — this method still may buildChromeSlotProjection (heavy). Do not call it from
     * bag-prime miss short-path.
     */
    public function primePublishedChromeSlotProjectionHotCache(int $themeId, ?string $scope = null): bool
    {
        if ($themeId < 1) {
            return false;
        }
        $scope = \trim((string)($scope ?? $this->resolveScope()));
        if ($scope === '') {
            $scope = 'default.default.default';
        }
        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return false;
        }
        $logicalKey = $this->chromeSlotProjectionLogicalKey($themeId, $scope);
        $policy = StorefrontThemeCacheCoordinator::publishedChromeSlotProjectionPolicy();
        try {
            $this->rememberPublishedChromeSlotProjection($themeId, $scope);
        } catch (\Throwable) {
            // Chrome bake missing / render hard-fail: still publish empty projection.
            $hotCache->rememberPolicy($policy, $logicalKey, static fn(): array => []);
        }

        return $hotCache->peekPolicy($policy, $logicalKey) !== null;
    }

    /**
     * P5-O1 / architect msg-5: honest miss marker for chrome_slot_projection.
     * Remembers [] under the live logical key WITHOUT buildChromeSlotProjection /
     * renderCurrent (禁空烧重投影). Not an FPC HIT; never claims page warm.
     */
    public function rememberHonestEmptyChromeSlotProjection(int $themeId, ?string $scope = null): bool
    {
        if ($themeId < 1) {
            return false;
        }
        $scope = \trim((string)($scope ?? $this->resolveScope()));
        if ($scope === '') {
            $scope = 'default.__store__.__channel__';
        }
        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return false;
        }
        $logicalKey = $this->chromeSlotProjectionLogicalKey($themeId, $scope);
        $policy = StorefrontThemeCacheCoordinator::publishedChromeSlotProjectionPolicy();
        try {
            $peeked = $hotCache->peekPolicy($policy, $logicalKey);
            if (\is_array($peeked)) {
                return true;
            }
            $hotCache->rememberPolicy($policy, $logicalKey, static fn(): array => []);
        } catch (\Throwable) {
            return false;
        }

        return $hotCache->peekPolicy($policy, $logicalKey) !== null;
    }

    /**
     * @return array<string, string> slotId => non-blank chrome inner HTML
     */
    private function rememberPublishedChromeSlotProjection(int $themeId, string $scope): array
    {
        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return $this->buildChromeSlotProjection($themeId, $scope, false);
        }

        $logicalKey = $this->chromeSlotProjectionLogicalKey($themeId, $scope);
        $policy = StorefrontThemeCacheCoordinator::publishedChromeSlotProjectionPolicy();
        // Same-request secondary injectChromeSlots must HIT Policy (wave7-7s).
        $peeked = $hotCache->peekPolicy($policy, $logicalKey);
        if (\is_array($peeked)) {
            return $peeked;
        }
        $cached = $hotCache->rememberPolicy(
            $policy,
            $logicalKey,
            fn(): array => $this->buildChromeSlotProjection($themeId, $scope, false),
        );

        return \is_array($cached) ? $cached : [];
    }

    private function chromeSlotProjectionLogicalKey(int $themeId, string $scope): string
    {
        $locale = '';
        try {
            $ctx = \Weline\Framework\Cache\StorefrontCacheKeyContext::current();
            if ($ctx instanceof \Weline\Framework\Cache\StorefrontCacheKeyContext) {
                $locale = \trim((string)$ctx->lang);
            }
        } catch (\Throwable) {
            $locale = '';
        }
        if ($locale === '') {
            $locale = \trim((string)WidgetI18n::storefrontLocale());
        }
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }

        $sources = $this->chrome->resolveRenderSources($themeId, $scope, false);
        $bindings = array_map(static fn(array $source): string => $source['binding']?->cacheKey() ?? $source['path'], $sources);
        return 'chrome.slot.projection.v4|' . $themeId . '|' . \trim($scope) . '|' . $locale
            . '|' . hash('sha256', json_encode($bindings, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function buildChromeSlotProjection(int $themeId, string $scope, bool $preview): array
    {
        $chromeByScope = [];
        $ownedSlots = [];
        $sources = $this->chrome->resolveRenderSources($themeId, $scope, $preview);
        foreach ($sources as $source) {
            $candidateHtml = $this->chrome->renderCurrent($themeId, $source['scope'], $source['version_id'], $source['preview']);
            $chromeByScope[$source['scope']] = $candidateHtml;
            if ($source['binding'] instanceof EntityRenderBinding) {
                $nodes = $this->configStore->readBoundConfig($source['binding']);
                foreach ($nodes as $node) {
                    if (is_array($node) && (string)($node['slot_id'] ?? '') !== '') {
                        $ownedSlots[$source['scope']][] = (string)$node['slot_id'];
                    }
                }
                // An explicitly empty version is a decision, not an absent source.
                if ($nodes === []) {
                    break;
                }
            }
        }
        RequestContext::set('theme.layout_entity.rendered_chrome_bindings', array_values(array_filter(array_column($sources, 'binding'))));
        return $this->projectRenderedChromeScopes($chromeByScope, $ownedSlots);
    }

    /** @param array<string,string> $chromeByScope Nearest scope first. */
    private function projectRenderedChromeScopes(array $chromeByScope, array $ownedSlotsByScope = []): array
    {
        $slotIds = SharedChromeService::CHROME_SLOTS;
        foreach ($chromeByScope as $chromeHtml) {
            foreach ($this->chromeSlotIdsFromRenderedHtml($chromeHtml, true) as $slotId) {
                if (!in_array($slotId, $slotIds, true)) {
                    $slotIds[] = $slotId;
                }
            }
        }
        foreach ($ownedSlotsByScope as $ownedSlots) {
            $slotIds = array_values(array_unique(array_merge($slotIds, $ownedSlots)));
        }
        $bestInnerBySlot = [];
        foreach ($chromeByScope as $ownerScope => $chromeHtml) {
            $chromeHtml = $this->composeChromeSlotTree($chromeHtml, $slotIds);
            foreach ($slotIds as $slotId) {
                if (isset($bestInnerBySlot[$slotId])) {
                    continue;
                }
                $inner = $this->boundaryScanner->extractSlotInner($chromeHtml, $slotId);
                if ($inner === null || $this->isBlankChromeInner($inner)) {
                    if (in_array($slotId, $ownedSlotsByScope[$ownerScope] ?? [], true)) {
                        $bestInnerBySlot[$slotId] = '';
                    }
                    continue;
                }
                $bestInnerBySlot[$slotId] = $inner;
            }
        }

        // An inherited root may still contain its own older child HTML. Compose
        // the selected child projections into each parent before root replacement,
        // otherwise replacing the root would undo the nearer scope's decision.
        $composed = [];
        $compose = function (string $slotId, array $ancestors = []) use (&$compose, &$composed, $bestInnerBySlot): string {
            if (array_key_exists($slotId, $composed)) {
                return $composed[$slotId];
            }
            $inner = $bestInnerBySlot[$slotId];
            $ancestors[$slotId] = true;
            foreach ($bestInnerBySlot as $childId => $_childInner) {
                if (isset($ancestors[$childId])) {
                    continue;
                }
                $regions = $this->boundaryScanner->enumerateRegions($inner, $childId);
                if ($regions === []) {
                    continue;
                }
                $childInner = $compose($childId, $ancestors);
                usort($regions, static fn(array $a, array $b): int => $b['inner_start'] <=> $a['inner_start']);
                foreach ($regions as $region) {
                    $inner = $this->boundaryScanner->replaceWrapperInner($inner, $region, $childInner);
                }
            }
            return $composed[$slotId] = $inner;
        };
        foreach (array_keys($bestInnerBySlot) as $slotId) {
            $compose($slotId);
        }
        return $composed;
    }

    private function isBlankChromeInner(string $inner): bool
    {
        // Align with published blank scan: missing-config comments are blank.
        if (ThemeLayoutEntityPublishedSlotHost::isEffectivelyBlankSlotInner($inner)) {
            return true;
        }
        $trim = \trim($inner);
        // Empty entity wrapper with no rendered widgets.
        if (\preg_match(
            '/^<div\b[^>]*\btheme-layout-entity-slot\b[^>]*>\s*<\/div>$/is',
            $trim,
        ) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $slotIds
     */
    private function composeChromeSlotTree(string $chromeHtml, array $slotIds): string
    {
        $preferred = [];
        foreach ($slotIds as $slotId) {
            $best = '';
            foreach ($this->extractAllSlotInners($chromeHtml, $slotId) as $inner) {
                if (\strlen($inner) > \strlen($best)) {
                    $best = $inner;
                }
            }
            if ($best !== '') {
                $preferred[$slotId] = $best;
            }
        }

        // Apply by chrome layout position — never by slot-id name/length.
        foreach ($this->orderSlotsByShellLayout($chromeHtml, \array_keys($preferred)) as $slotId) {
            $chromeHtml = $this->replaceAllSlotInners($chromeHtml, $slotId, $preferred[$slotId]);
        }

        return $chromeHtml;
    }

    /**
     * @return list<string>
     */
    private function extractAllSlotInners(string $html, string $slotId): array
    {
        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $inners = [];
        $offset = 0;
        $openLen = \strlen($open);
        while (($openPos = \strpos($html, $open, $offset)) !== false) {
            $innerStart = $openPos + $openLen;
            $closePos = \strpos($html, $close, $innerStart);
            if ($closePos === false) {
                break;
            }
            $inners[] = \substr($html, $innerStart, $closePos - $innerStart);
            $offset = $closePos + \strlen($close);
        }

        return $inners;
    }

    private function replaceAllSlotInners(string $html, string $slotId, string $newInner): string
    {
        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return $html;
        }

        $offset = 0;
        $openLen = \strlen($open);
        while (($openPos = \strpos($html, $open, $offset)) !== false) {
            $innerStart = $openPos + $openLen;
            $closePos = \strpos($html, $close, $innerStart);
            if ($closePos === false) {
                break;
            }
            $html = \substr($html, 0, $innerStart) . $newInner . \substr($html, $closePos);
            $offset = $innerStart + \strlen($newInner) + \strlen($close);
        }

        return $html;
    }

    private function replaceSlotInner(string $html, string $slotId, string $newInner): string
    {
        // Prefer data-wslot / data-slot-id wrapper inner so host classes
        // (e.g. product-native-detail__actions) survive solidified splice.
        $regions = $this->boundaryScanner->enumerateRegions($html, $slotId);
        if ($regions !== []) {
            usort(
                $regions,
                static fn(array $a, array $b): int => ((int)$a['depth'] <=> (int)$b['depth'])
                    ?: ((int)$a['region_start'] <=> (int)$b['region_start']),
            );
            $region = $regions[0];

            return $this->boundaryScanner->replaceWrapperInner($html, $region, $newInner);
        }

        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return $html;
        }
        $openPos = \strpos($html, $open);
        if ($openPos === false) {
            return $html;
        }
        $innerStart = $openPos + \strlen($open);
        $closePos = \strpos($html, $close, $innerStart);
        if ($closePos === false) {
            return $html;
        }

        return \substr($html, 0, $innerStart) . $newInner . \substr($html, $closePos);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param array<string, mixed> $configByUid
     * @return array<string, mixed>
     */
    private function buildLayoutDataFromStructure(array $slots, array $configByUid): array
    {
        $layoutData = [];
        foreach ($slots as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if ($uid !== '' && isset($configByUid[$uid]) && \is_array($configByUid[$uid])) {
                    $cfg = $configByUid[$uid];
                    if (isset($cfg['config']) && \is_array($cfg['config'])) {
                        $widget['config'] = $cfg['config'];
                    } else {
                        $widget = \array_merge($widget, $cfg);
                    }
                }
                $area = \trim((string)($widget['area'] ?? ''));
                if ($area === '') {
                    $area = (string)$slotId;
                }
                if (!isset($layoutData[$area])) {
                    $layoutData[$area] = ['widgets' => []];
                }
                if (!isset($widget['slot_id']) || $widget['slot_id'] === '') {
                    $widget['slot_id'] = (string)$slotId;
                }
                $layoutData[$area]['widgets'][] = $widget;
            }
        }

        return $layoutData;
    }

    /**
     * Heal stale/wrong widget_module in baked structure (e.g. Weline_Theme::recently-viewed).
     *
     * @param array<string, mixed> $layoutData
     * @return array<string, mixed>
     */
    private function healWidgetModules(array $layoutData): array
    {
        try {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $registry */
            $registry = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemePlaceableRegistry::class,
            );
            /** @var \Weline\Theme\Service\ThemeComponentCatalog $catalog */
            $catalog = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemeComponentCatalog::class,
            );
        } catch (\Throwable) {
            return $layoutData;
        }

        $definitions = $catalog->getDefinitions('frontend', null);
        $byCode = [];
        foreach ($definitions as $definition) {
            if (!\is_object($definition) || !isset($definition->code)) {
                continue;
            }
            $code = \trim((string)$definition->code);
            if ($code === '' || isset($byCode[$code])) {
                continue;
            }
            $byCode[$code] = $definition;
        }

        foreach ($layoutData as $area => $areaData) {
            if (!\is_array($areaData) || !isset($areaData['widgets']) || !\is_array($areaData['widgets'])) {
                continue;
            }
            foreach ($areaData['widgets'] as $index => $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $module = \trim((string)($widget['widget_module'] ?? ''));
                $type = \trim((string)($widget['widget_type'] ?? ''));
                $code = \trim((string)($widget['widget_code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $found = $registry->find($module, $type, $code, null, 'frontend');
                if ($found !== null) {
                    continue;
                }
                $healed = $byCode[$code] ?? null;
                if ($healed === null) {
                    continue;
                }
                $layoutData[$area]['widgets'][$index]['widget_module'] = (string)$healed->module;
                $layoutData[$area]['widgets'][$index]['widget_type'] = (string)$healed->type;
                $layoutData[$area]['widgets'][$index]['widget_code'] = (string)$healed->code;
            }
        }

        return $layoutData;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     */
    private function prefetchDictionaryModules(array $slots): void
    {
        if (!\method_exists(\Weline\Framework\Phrase\Parser::class, 'prefetchGlobalDictionaryModules')) {
            return;
        }
        $modules = [];
        foreach ($slots as $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $module = \trim((string)($widget['widget_module'] ?? ''));
                if ($module !== '') {
                    $modules[$module] = true;
                }
            }
        }
        if ($modules === []) {
            return;
        }
        $list = \array_keys($modules);
        \sort($list, \SORT_STRING);
        \Weline\Framework\Phrase\Parser::prefetchGlobalDictionaryModules($list);
    }

    public function resolveRequestedPreviewEntity(int $themeId, string $pageType, string $area): ?array
    {
        $service = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\PreviewContextService::class);
        if (!$service->isEditorThemeRequest() && !$service->hasAuthoritativePreviewContext()) {
            return null;
        }
        $context = $service->getCurrentContext();
        if ((int)($context['version_id'] ?? 0) < 1 || (int)($context['frontend_theme_id'] ?? 0) < 1) {
            return null;
        }
        $scope = (string)($context['scope'] ?? $this->resolveScope());
        $identity = $this->entityIdentityForScope($scope);
        $identity['layout_option'] = (string)($context['layout_option'] ?? $identity['layout_option']);
        $key = 'theme.layout_entity.preview_selection.' . hash('sha256', json_encode([$themeId, $pageType, $area, $identity, (int)$context['version_id']], JSON_THROW_ON_ERROR));
        $resolved = RequestContext::get($key);
        if (!is_array($resolved)) {
            $resolved = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\ThemeVersionPreviewResolver::class)
                ->resolve($themeId, $pageType, $area, $identity, (int)$context['version_id']);
            RequestContext::set($key, $resolved);
        }
        if (empty($resolved['resolved'])) {
            throw new \RuntimeException('theme_layout_entity_preview_version_missing: ' . (string)($resolved['reason'] ?? 'unknown'));
        }
        RequestContext::set('theme.layout_entity.preview_entity', $resolved);
        return $resolved;
    }

    private function entityIdentityForScope(string $scope): array
    {
        $installed = RequestContext::get(\Weline\Theme\Api\Layout\LayoutIdentity::REQUEST_CONTEXT_KEY);
        $identity = $installed instanceof \Weline\Theme\Api\Layout\LayoutIdentity
            ? $installed->toArray()
            : ['layout_option' => 'default', 'target_type' => 'global', 'target_id' => 0];
        $identity['scope'] = $scope;
        $identity['locale_code'] = 'default';
        return $identity;
    }

    private function resolveScope(): string
    {
        $installed = RequestContext::get(\Weline\Theme\Api\Layout\LayoutIdentity::REQUEST_CONTEXT_KEY);
        if ($installed instanceof \Weline\Theme\Api\Layout\LayoutIdentity) {
            return $installed->scope;
        }
        try {
            if (RequestContext::isInitialized()) {
                $identity = RequestContext::scopeIdentity();
                if ($identity instanceof ScopeIdentity) {
                    return $this->scopes->contextFromIdentity($identity)->storageScope;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'default.default.default';
    }

    private function resolveGlobalActiveThemeId(): int
    {
        try {
            $theme = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Model\WelineTheme::class,
            );
            $theme->clearData()->clearQuery()->getActiveTheme(null);
            $id = (int)$theme->getId();
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // soft
        }

        return 0;
    }

    /**
     * @return array{identity_hash:string,release_id:?int}
     */
    private function resolveEditorIdentity(
        int $themeId,
        string $pageType,
        string $area,
        ?string $scope = null,
    ): array {
        $scope = $scope ?? $this->resolveScope();
        try {
            $context = $this->layoutResolver->buildContext($themeId, $pageType, $area, $this->entityIdentityForScope($scope));

            $releaseId = null;
            try {
                /** @var \Weline\Theme\Model\ThemeScopeWorkspace $workspaces */
                $workspaces = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Theme\Model\ThemeScopeWorkspace::class,
                );
                $workspace = clone $workspaces;
                $workspace->clearData()->clearQuery()
                    ->where(\Weline\Theme\Model\ThemeScopeWorkspace::schema_fields_IDENTITY_HASH, $context->identityHash())
                    ->find()
                    ->fetch();
                if ((int)$workspace->getId() > 0) {
                    $releaseId = (int)$workspace->getData(
                        \Weline\Theme\Model\ThemeScopeWorkspace::schema_fields_PUBLISHED_RELEASE_ID,
                    );
                    if ($releaseId < 1) {
                        $releaseId = null;
                    }
                }
            } catch (\Throwable) {
                $releaseId = null;
            }

            return [
                'identity_hash' => $context->identityHash(),
                'release_id' => $releaseId,
            ];
        } catch (\Throwable) {
            return [
                'identity_hash' => \hash('sha256', $pageType . '|' . $scope),
                'release_id' => null,
            ];
        }
    }

    /**
     * 布局固化与默认注入 §3.1: no entity bake → dynamic solidify active theme page.
     *
     * @return array{scope:string,identity_key:string,structure_or_release:string}|null
     */
    private function tryDynamicSolidifyMissingPage(
        int $themeId,
        string $scope,
        string $pageType,
        string $area,
        string $status,
    ): ?array {
        if ($status !== ThemeLayout::STATUS_PUBLISHED || $themeId < 1 || \trim($pageType) === '') {
            return null;
        }
        try {
            $identity = $this->resolveEditorIdentity($themeId, $pageType, $area, $scope);
            $identityHash = (string)($identity['identity_hash'] ?? '');
            $releaseId = isset($identity['release_id']) ? (int)$identity['release_id'] : null;
            if ($releaseId !== null && $releaseId < 1) {
                $releaseId = null;
            }
            $nodes = $this->loadNodesForDynamicSolidify($themeId, $pageType);
            /** @var ThemeLayoutEntityBakeCoordinator $bake */
            $bake = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);
            $path = $bake->dynamicSolidifyPublishedPage(
                $themeId,
                $scope,
                $identityHash,
                $pageType,
                $nodes,
                $releaseId,
                (string)$this->entityIdentityForScope($scope)['layout_option'],
                $area,
            );
            if ($path === '' || !\is_file($path)) {
                return null;
            }

            return $this->resolvePageEntityLocationUncached($themeId, $scope, $pageType, $area, true);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    'theme_layout_entity_dynamic_solidify_soft: ' . $e->getMessage(),
                    ['theme_id' => $themeId, 'page_type' => $pageType],
                    'theme_layout_entity',
                );
            }

            return null;
        }
    }

    private function tryRematerializeMissingPhtml(
        int $themeId,
        string $pageScope,
        string $identityKey,
        string $structureOrRelease,
        string $pageType,
    ): string {
        if (preg_match('/^r[1-9][0-9]*$/D', $structureOrRelease) !== 1) {
            return '';
        }
        try {
            /** @var ThemeLayoutEntityBakeCoordinator $bake */
            $bake = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class);

            return $bake->rematerializePublishedPageAt(
                $themeId,
                $pageScope,
                $identityKey,
                $structureOrRelease,
                $pageType,
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadNodesForDynamicSolidify(int $themeId, string $pageType): array
    {
        try {
            /** @var \Weline\Theme\Service\ThemeLayoutService $layouts */
            $layouts = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemeLayoutService::class,
            );
            $layout = $layouts->getPublishedLayout($themeId, $pageType);
            if (!\is_array($layout) || $layout === []) {
                return [];
            }
            $nodes = [];
            foreach ($layout as $area => $areaData) {
                $widgets = \is_array($areaData) ? ($areaData['widgets'] ?? []) : [];
                if (!\is_array($widgets)) {
                    continue;
                }
                foreach ($widgets as $widget) {
                    if (!\is_array($widget)) {
                        continue;
                    }
                    $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                    if ($uid === '') {
                        continue;
                    }
                    if (\trim((string)($widget['area'] ?? '')) === '') {
                        $widget['area'] = (string)$area;
                    }
                    $widget['node_uid'] = $uid;
                    $nodes[$uid] = $widget;
                }
            }

            return $nodes;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resolve the solidified layout.phtml. Published and draft both load a file.
     * current.json is written at structural bake; workspace is only a one-time fallback
     * for pages baked before that pointer existed.
     *
     * @return array{scope:string,identity_key:string,structure_or_release:string}|null
     */
    private function resolvePageEntityLocation(
        int $themeId,
        string $scope,
        string $pageType,
        string $area,
        bool $published,
    ): ?array {
        $previewEntity = RequestContext::get('theme.layout_entity.preview_entity');
        if (is_array($previewEntity) && (int)($previewEntity['theme_id'] ?? 0) === $themeId) {
            return ['scope' => (string)$previewEntity['scope'], 'identity_key' => (string)$previewEntity['identity_key'], 'structure_or_release' => (string)$previewEntity['entity_key']];
        }
        if ($published) {
            return $this->rememberPublishedPageEntityLocation($themeId, $scope, $pageType, $area);
        }

        return $this->resolvePageEntityLocationUncached($themeId, $scope, $pageType, $area, false);
    }

    /**
     * @return array{scope:string,identity_key:string,structure_or_release:string}|null
     */
    private function rememberPublishedPageEntityLocation(
        int $themeId,
        string $scope,
        string $pageType,
        string $area,
    ): ?array {
        $hotCache = $this->resolveHotCache();
        if (!$hotCache instanceof StorefrontScopeHotCache) {
            return $this->resolvePageEntityLocationUncached($themeId, $scope, $pageType, $area, true);
        }

        $logicalKey = 'page.location.v1|' . $themeId . '|' . \trim($scope)
            . '|' . \strtolower(\trim($pageType)) . '|' . \strtolower(\trim($area))
            . '|' . hash('sha256', json_encode($this->entityIdentityForScope($scope), JSON_THROW_ON_ERROR));
        $cached = $hotCache->rememberPolicy(
            StorefrontThemeCacheCoordinator::publishedPageEntityLocationPolicy(),
            $logicalKey,
            fn(): array => $this->resolvePageEntityLocationUncached($themeId, $scope, $pageType, $area, true) ?? [],
        );
        if (!\is_array($cached) || $cached === []) {
            return null;
        }
        if (!isset($cached['scope'], $cached['identity_key'], $cached['structure_or_release'])) {
            return null;
        }

        return [
            'scope' => (string)$cached['scope'],
            'identity_key' => (string)$cached['identity_key'],
            'structure_or_release' => (string)$cached['structure_or_release'],
        ];
    }

    /**
     * @return array{scope:string,identity_key:string,structure_or_release:string}|null
     */
    private function resolvePageEntityLocationUncached(
        int $themeId,
        string $scope,
        string $pageType,
        string $area,
        bool $published,
    ): ?array {
        foreach ($this->scopeFallbackChain($scope) as $candidateScope) {
            $identityKey = $this->identityKeyForScope($themeId, $pageType, $area, $candidateScope);
            $fromPointer = $this->readPageCurrent($themeId, $candidateScope, $identityKey, $published);
            if ($fromPointer !== null) {
                return [
                    'scope' => $candidateScope,
                    'identity_key' => $identityKey,
                    'structure_or_release' => $fromPointer,
                ];
            }
        }

        if (!$published) {
            foreach ($this->scopeFallbackChain($scope) as $candidateScope) {
                $identityKey = $this->identityKeyForScope($themeId, $pageType, $area, $candidateScope);
                $scanned = $this->scanSolidifiedSegment($themeId, $candidateScope, $identityKey, false);
                if ($scanned === null) {
                    continue;
                }
                $this->rememberPageCurrent($themeId, $candidateScope, $identityKey, $scanned, false);

                return [
                    'scope' => $candidateScope,
                    'identity_key' => $identityKey,
                    'structure_or_release' => $scanned,
                ];
            }

            return null;
        }

        foreach ($this->scopeFallbackChain($scope) as $candidateScope) {
            $identity = $this->resolveEditorIdentity($themeId, $pageType, $area, $candidateScope);
            $identityKey = $this->paths->identityKey($identity['identity_hash']);
            $structureOrRelease = $this->resolveStructureOrRelease(
                $themeId,
                $candidateScope,
                $identityKey,
                true,
                $identity['release_id'],
            );
            if ($structureOrRelease === null) {
                continue;
            }
            if (!$this->pagePhtmlHasSlots(
                $this->paths->pagePhtml($themeId, $candidateScope, $identityKey, $structureOrRelease),
            )) {
                continue;
            }
            $this->rememberPageCurrent($themeId, $candidateScope, $identityKey, $structureOrRelease, true);

            return [
                'scope' => $candidateScope,
                'identity_key' => $identityKey,
                'structure_or_release' => $structureOrRelease,
            ];
        }

        return null;
    }

    private function identityKeyForScope(int $themeId, string $pageType, string $area, string $scope): string
    {
        try {
            $context = $this->layoutResolver->buildContext($themeId, $pageType, $area, $this->entityIdentityForScope($scope));

            return $this->paths->identityKey($context->identityHash());
        } catch (\Throwable) {
            return $this->paths->identityKey(\hash('sha256', $pageType . '|' . $scope));
        }
    }

    private function readPageBinding(int $themeId, string $scope, string $identityKey, string $entityKey): ?EntityRenderBinding
    {
        $key = 'theme.layout_entity.page_binding.' . hash('sha256', json_encode([$themeId, $scope, $identityKey, $entityKey], JSON_THROW_ON_ERROR));
        $cached = RequestContext::get($key);
        if ($cached instanceof EntityRenderBinding) {
            return $cached;
        }
        $binding = \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityBindingStore::class)
            ->readPageBinding($themeId, $scope, $identityKey, $entityKey);
        if ($binding !== null) {
            RequestContext::set($key, $binding);
        }
        return $binding;
    }

    private function readPageCurrent(int $themeId, string $scope, string $identityKey, bool $published): ?string
    {
        $file = $this->paths->pageCurrentJson($themeId, $scope, $identityKey);
        if (!\is_file($file)) {
            return null;
        }
        $decoded = \json_decode((string)\file_get_contents($file), true);
        if (!\is_array($decoded)) {
            return null;
        }
        $keys = $published ? ['published'] : ['draft', 'published'];
        foreach ($keys as $key) {
            $segment = \trim((string)($decoded[$key] ?? ''));
            if ($segment === '') {
                continue;
            }
            if (preg_match($published ? '/^r[1-9][0-9]*$/D' : '/^(?:[dr][1-9][0-9]*|s[a-f0-9]+)$/D', $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }

    private function rememberPageCurrent(
        int $themeId,
        string $scope,
        string $identityKey,
        string $structureOrRelease,
        bool $published,
    ): void {
        if ($structureOrRelease === '' || $identityKey === '') {
            return;
        }
        $file = $this->paths->pageCurrentJson($themeId, $scope, $identityKey);
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }
        $existing = [];
        if (\is_file($file)) {
            $decoded = \json_decode((string)\file_get_contents($file), true);
            if (\is_array($decoded)) {
                $existing = $decoded;
            }
        }
        $existing[$published ? 'published' : 'draft'] = $structureOrRelease;
        $json = \json_encode($existing, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = $file . '.tmp';
        if (@\file_put_contents($tmp, $json . "\n") === false) {
            return;
        }
        @\rename($tmp, $file);
    }

    /**
     * Draft fallback only: pick an existing solidified file. Published storefront
     * never fishes older r* / s* — it uses current.json or the published release id.
     */
    private function scanSolidifiedSegment(
        int $themeId,
        string $scope,
        string $identityKey,
        bool $published,
    ): ?string {
        // 没有权威指针时不能按目录排序猜测草稿或发布版本。
        return null;
    }

    private function pagePhtmlHasSlots(string $path): bool
    {
        if (!\is_file($path)) {
            return false;
        }
        $src = (string)\file_get_contents($path);

        return $src !== '' && \str_contains($src, SlotBoundaryMarkers::OPEN_PREFIX);
    }

    private function includeEntityPhtml(string $path, ?EntityRenderBinding $entityBinding = null): string
    {
        \Weline\Framework\Runtime\FiberOutputBuffer::beginCapture();
        try {
            include $path;
            $html = (string)\Weline\Framework\Runtime\FiberOutputBuffer::endCapture();
        } catch (\Throwable $e) {
            \Weline\Framework\Runtime\FiberOutputBuffer::discardCapture();
            throw new \RuntimeException('theme_layout_entity_include_failed: ' . $e->getMessage(), 0, $e);
        }

        return $html;
    }

    /**
     * wave8-8s5: load published chrome bake from disk (chrome.phtml / rendered snapshot).
     * Does NOT touch chrome_slot_projection HotCache Policy.
     */
    private function loadPublishedChromeBakeHtmlDirect(int $themeId, string $scope): string
    {
        $scope = \trim($scope);
        if ($themeId < 1 || $scope === '') {
            return '';
        }
        try {
            // Prefer ThemeLayoutEntityChrome::renderCurrent → chrome.rendered.{locale}
            // (finalized nested footer-*-links). Never raw-include chrome.phtml injectors.
            return $this->chrome->renderCurrent($themeId, $scope, null, false);
        } catch (\Throwable $first) {
            try {
                \Weline\Framework\Manager\ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class)
                    ->ensurePublishedChromeForScope($themeId, $scope, []);
                return $this->chrome->renderCurrent($themeId, $scope, null, false);
            } catch (\Throwable $e) {
                if (\function_exists('w_log_warning')) {
                    w_log_warning(
                        'theme_layout_entity_chrome_bake_html_miss: ' . $e->getMessage(),
                        [
                            'theme_id' => $themeId,
                            'scope' => $scope,
                            'first' => $first->getMessage(),
                        ],
                        'theme_layout_entity',
                    );
                }

                return '';
            }
        }
    }

    /**
     * @return array<string, string> chrome slotId => inner HTML from already-baked markup
     */
    private function chromeSlotIdsFromRenderedHtml(string $html, bool $chromeOnly = false): array
    {
        $slots = [];
        if (preg_match_all('/<!--@weline-chrome-slots:([\w.,-]+)-->/', $html, $ownership) > 0) {
            foreach ($ownership[1] as $list) {
                foreach (explode(',', $list) as $slot) {
                    $slots[strtolower($slot)] = true;
                }
            }
        }
        if (preg_match_all('/<!--@weline-slot:([\w.-]+)-->/', $html, $markers) > 0) {
            foreach ($markers[1] as $slot) {
                $slot = strtolower($slot);
                if ($chromeOnly || $this->sharedChrome->isChromeSlot($slot)) {
                    $slots[$slot] = true;
                }
            }
        }
        return array_keys($slots);
    }

    private function extractChromeInnersFromBakedHtml(string $html): array
    {
        if ($html === '' || !\str_contains($html, '<!--@weline-slot:')) {
            return [];
        }
        $out = [];
        foreach (SharedChromeService::CHROME_SLOTS as $slotId) {
            $slotId = \strtolower(\trim((string)$slotId));
            if ($slotId === '') {
                continue;
            }
            try {
                $inner = $this->boundaryScanner->extractSlotInner($html, $slotId, false, true);
            } catch (\Throwable) {
                continue;
            }
            if ($inner === null || \trim($inner) === '') {
                continue;
            }
            $out[$slotId] = $inner;
        }
        foreach ($this->chromeSlotIdsFromRenderedHtml($html) as $slotId) {
            if (isset($out[$slotId])) {
                continue;
            }
            $inner = $this->boundaryScanner->extractSlotInner($html, $slotId, false, true);
            if ($inner !== null && trim($inner) !== '') {
                $out[$slotId] = $inner;
            }
        }

        return $out;
    }

    private function spliceSolidifiedSlots(string $html, string $rendered): string
    {
        if (\preg_match_all('/<!--@weline-slot:([\w.-]+)-->/', $rendered, $matches) < 1) {
            return $html;
        }
        $seen = [];
        foreach ($matches[1] as $slotId) {
            $slotId = (string)$slotId;
            if ($slotId === '' || isset($seen[$slotId])) {
                continue;
            }
            $seen[$slotId] = true;
        }
        // Layout document order only — NEVER sort by slot-id name/length.
        // Children before parents (nesting depth in the shell), then open-marker
        // position in the shell. That keeps homepage-hero at the top of content.
        $ordered = $this->orderSlotsByShellLayout($html, \array_keys($seen));

        foreach ($ordered as $slotId) {
            $inner = $this->boundaryScanner->extractSlotInner($rendered, $slotId, false, true);
            if ($inner === null) {
                continue;
            }
            // Empty nested placeholders must not wipe a shell slot that already
            // has markup; only replace when the solidified inner has content.
            if (trim($inner) === '') {
                continue;
            }
            // Homepage shell nests homepage-hero/promo/videos under content. Entity
            // layout emits those as sibling top-level slots; a full content replace
            // would wipe nested markers (and any already-spliced children).
            // Policy/terms nest policy-*-content + layout-built hero/sections the same way.
            // Always preserve when the shell still carries protected nested layout body,
            // even if the entity leaf omitted that slot id from $ordered.
            if ($slotId === 'content' && $this->shellContentCarriesProtectedNestedLayout($html)) {
                $html = $this->mergeParentSlotPreservingNested($html, $slotId, $inner, $ordered);
                continue;
            }
            if ($this->shellSlotInnerHasProtectedNestedSlots($html, $slotId, $ordered)) {
                $html = $this->mergeParentSlotPreservingNested($html, $slotId, $inner, $ordered);
                continue;
            }
            // Safety-net: replace every matching region (data-wslot / data-slot-id / markers).
            if (ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
                $regions = $this->boundaryScanner->enumerateRegions($html, $slotId);
                if ($regions !== []) {
                    \usort(
                        $regions,
                        static fn(array $a, array $b): int => ((int)($b['region_start'] ?? 0) <=> (int)($a['region_start'] ?? 0)),
                    );
                    foreach ($regions as $region) {
                        if (!\is_array($region)) {
                            continue;
                        }
                        $html = $this->boundaryScanner->replaceWrapperInner($html, $region, $inner);
                    }
                    continue;
                }
                $html = $this->replaceAllSlotInners($html, $slotId, $inner);
                continue;
            }
            $html = $this->replaceSlotInner($html, $slotId, $inner);
        }

        return $html;
    }

    /**
     * Order entity slots by layout position in the shell HTML.
     *
     * Deeper nested markers first (so parent merge cannot erase children), then
     * ascending open-marker offset (document order). Forbidden: name/length sorts.
     *
     * @param list<string> $slotIds
     * @return list<string>
     */
    private function orderSlotsByShellLayout(string $shellHtml, array $slotIds): array
    {
        /** @var array<string, array{pos:int,inner_start:int,close:int,depth:int}> $meta */
        $meta = [];
        foreach ($slotIds as $slotId) {
            $slotId = \strtolower(\trim((string)$slotId));
            if ($slotId === '') {
                continue;
            }
            try {
                $open = SlotBoundaryMarkers::open($slotId);
                $close = SlotBoundaryMarkers::close($slotId);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $openPos = \strpos($shellHtml, $open);
            if ($openPos === false) {
                // Not on shell: process after layout-positioned slots (entity-only).
                $meta[$slotId] = [
                    'pos' => \PHP_INT_MAX,
                    'inner_start' => \PHP_INT_MAX,
                    'close' => \PHP_INT_MAX,
                    'depth' => -1,
                ];
                continue;
            }
            $innerStart = $openPos + \strlen($open);
            $closePos = \strpos($shellHtml, $close, $innerStart);
            $meta[$slotId] = [
                'pos' => $openPos,
                'inner_start' => $innerStart,
                'close' => $closePos === false ? $innerStart : $closePos,
                'depth' => 0,
            ];
        }

        foreach ($meta as $id => $m) {
            if ($m['pos'] === \PHP_INT_MAX) {
                continue;
            }
            $depth = 0;
            foreach ($meta as $otherId => $other) {
                if ($otherId === $id || $other['pos'] === \PHP_INT_MAX) {
                    continue;
                }
                if ($other['inner_start'] <= $m['pos'] && $m['pos'] < $other['close']) {
                    ++$depth;
                }
            }
            $meta[$id]['depth'] = $depth;
        }

        $ordered = \array_keys($meta);
        \usort(
            $ordered,
            static function (string $a, string $b) use ($meta): int {
                $depthCmp = $meta[$b]['depth'] <=> $meta[$a]['depth'];
                if ($depthCmp !== 0) {
                    return $depthCmp;
                }

                return $meta[$a]['pos'] <=> $meta[$b]['pos'];
            },
        );

        return $ordered;
    }

    /**
     * @param list<string> $entitySlotIds
     */
    private function shellSlotInnerHasProtectedNestedSlots(
        string $html,
        string $slotId,
        array $entitySlotIds,
    ): bool {
        $shellInner = $this->boundaryScanner->extractSlotInner($html, $slotId, false, true);
        if ($shellInner === null || $shellInner === '') {
            return false;
        }
        foreach ($entitySlotIds as $childId) {
            if ($childId === $slotId) {
                continue;
            }
            if (\str_contains($shellInner, SlotBoundaryMarkers::OPEN_PREFIX . $childId . '-->')) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when content still carries nested homepage-* / policy-* / terms layout body.
     * Used as a hard guard so sparse content widgets (newsletter-popup / trust-badges)
     * cannot erase the slot tree before children are spliced.
     */
    private function shellContentCarriesProtectedNestedLayout(string $html): bool
    {
        if ($this->shellContentCarriesHomepageNestedSlots($html)) {
            return true;
        }
        $shellInner = $this->boundaryScanner->extractSlotInner($html, 'content', false, true);
        if ($shellInner === null || $shellInner === '') {
            return false;
        }
        if (\preg_match('/<!--@weline-slot:(?:policy-|terms)/', $shellInner) === 1) {
            return true;
        }
        if (\preg_match('/\bdata-slot-id\s*=\s*(["\'])(?:policy-|terms)/', $shellInner) === 1) {
            return true;
        }

        return \str_contains($shellInner, 'amazon-policy__')
            || \str_contains($shellInner, 'amazon-terms__')
            || \str_contains($shellInner, 'policy-main');
    }

    /**
     * True when homepage content still carries nested homepage-* slot opens.
     * Used as a hard guard so sparse content widgets (trust-badges/store-music)
     * cannot erase the slot tree before children are spliced.
     */
    private function shellContentCarriesHomepageNestedSlots(string $html): bool
    {
        $shellInner = $this->boundaryScanner->extractSlotInner($html, 'content', false, true);
        if ($shellInner === null || $shellInner === '') {
            return false;
        }

        return \str_contains($shellInner, SlotBoundaryMarkers::OPEN_PREFIX . 'homepage-');
    }

    /**
     * Prepend entity parent widgets; keep shell nested slot document order.
     *
     * Nested children were already spliced in-place by layout position.
     * Do NOT rebuild by slot-id name/length — layout position is authoritative.
     *
     * @param list<string> $entitySlotIds unused; kept for call-site stability
     */
    private function mergeParentSlotPreservingNested(
        string $html,
        string $slotId,
        string $entityInner,
        array $entitySlotIds,
    ): string {
        unset($entitySlotIds);
        $shellInner = (string)$this->boundaryScanner->extractSlotInner($html, $slotId, false, true);
        if (trim($shellInner) === '') {
            return $this->replaceSlotInner($html, $slotId, $entityInner);
        }

        return $this->replaceSlotInner($html, $slotId, $entityInner . $shellInner);
    }

    private function primeLocaleConfigs(
        int $themeId,
        string $pageType,
        string $status,
        string $area,
        string $pageScope,
        string $identityKey,
        string $structureOrRelease,
        ?EntityRenderBinding $binding = null,
    ): void {
        try {
            $configByUid = $binding !== null ? $this->configStore->readBoundConfig($binding) : $this->configStore->readPageConfig(
                $themeId,
                $pageScope,
                $identityKey,
                $structureOrRelease,
            );
        } catch (\Throwable) {
            return;
        }
        if ($configByUid === []) {
            return;
        }
        $versionKey = $identityKey . '/' . $structureOrRelease;
        foreach ($configByUid as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)$uid));
            if ($uid === '') {
                continue;
            }
            if ($binding === null && $this->configStore->needsStructureHydration($node)) {
                $configByUid[$uid] = $this->configStore->hydratePageNodeFromStructure(
                    $node,
                    $uid,
                    $themeId,
                    $pageScope,
                    $versionKey,
                );
            }
        }
        $this->prefetchDictionaryModules(['page' => \array_values($configByUid)]);
        // Baked entity config is language-neutral structure; storefront locale media/copy
        // lives in RESOURCE_I18N and must overlay before widget render (hero banners, etc.).
        // Prefer request scope for i18n identity (editor publishes under website/store),
        // not only the entity directory scope (may be an ancestor without i18n rows).
        $layout = [];
        foreach ($configByUid as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $slot = (string)($node['slot_id'] ?? 'content');
            $areaName = \trim((string)($node['area'] ?? ''));
            if ($areaName === '') {
                $areaName = $slot;
            }
            $node['node_uid'] = \strtolower(\trim((string)($node['node_uid'] ?? $uid)));
            $layout[$areaName]['widgets'][] = $node;
        }
        $overlayScope = \trim($this->resolveScope());
        if ($overlayScope === '') {
            $overlayScope = $pageScope;
        }
        try {
            $layout = $this->layoutResolver->overlayLocaleOnLayout(
                $layout,
                $themeId,
                $pageType,
                $status,
                $area,
$this->entityIdentityForScope($overlayScope),
            );
        } catch (\Throwable) {
            $layout = $layout;
        }
        foreach ($layout as $areaData) {
            if (!\is_array($areaData)) {
                continue;
            }
            $widgets = $areaData['widgets'] ?? [];
            if (!\is_array($widgets)) {
                continue;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if ($uid !== '') {
                    RequestContext::set(ThemeLayoutEntityWidgetRenderer::requestNodeKey($uid, 'page', $themeId, $binding?->scope ?? $this->paths->scopeKey($pageScope), $binding?->cacheKey() ?? $versionKey, WidgetI18n::storefrontLocale()), $widget);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function scopeFallbackChain(string $scope): array
    {
        return $this->chrome->scopeFallbackChain($scope);
    }

    /**
     * Hard-cut path picker: published storefront is r{published_release_id} only.
     * Never scandir-fish s* / older r* — missing bake fails closed (caller throws).
     * Draft/preview does not resolve page entities via this directory fallback.
     */
    private function resolveStructureOrRelease(
        int $themeId,
        string $scope,
        string $identityKey,
        bool $published,
        ?int $preferredReleaseId,
    ): ?string {
        if (!$published) {
            return null;
        }
        if ($preferredReleaseId === null || $preferredReleaseId < 1) {
            return null;
        }

        $candidate = $this->paths->pageStructureOrRelease('', true, $preferredReleaseId);
        $path = $this->paths->pagePhtml($themeId, $scope, $identityKey, $candidate);
        if (!$this->pagePhtmlHasSlots($path)) {
            return null;
        }

        return $candidate;
    }

    private function resolveHotCache(): ?StorefrontScopeHotCache
    {
        if ($this->hotCache instanceof StorefrontScopeHotCache) {
            return $this->hotCache;
        }
        try {
            $resolved = \Weline\Framework\Manager\ObjectManager::getInstance(StorefrontScopeHotCache::class);

            return $resolved instanceof StorefrontScopeHotCache ? $resolved : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
