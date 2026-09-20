<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\SlotRendererService;
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
    ) {
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

        $scope = $this->resolveScope();
        $preview = $status === ThemeLayout::STATUS_DRAFT;
        // Chrome first so a later page-entity miss can still keep header/footer
        // when the caller soft-degrades (LayoutSlotRenderer DEV path).
        // Do not abort page-slot fill when chrome is unpublished/missing — category
        // filters and other content widgets must still render from page entities.
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

        $published = !$preview;
        $resolved = $this->resolvePageEntityLocation(
            $themeId,
            $scope,
            $pageType,
            $area,
            $published,
        );
        if ($resolved === null) {
            // Entity missing: still 有部件必入声明槽 against the page shell — never append('') which
            // cannot see slot destinations and would silently skip all required fills.
            return \Weline\Framework\Manager\ObjectManager::getInstance(RequiredDefaultInjectionStorefrontOverlay::class)
                ->append($html, $themeId, $pageType, $status, $scope, 'required', null);
        }

        $pageScope = $resolved['scope'];
        $identityKey = $resolved['identity_key'];
        $structureOrRelease = $resolved['structure_or_release'];
        $phtml = $this->paths->pagePhtml($themeId, $pageScope, $identityKey, $structureOrRelease);
        if (!\is_file($phtml)) {
            throw new \RuntimeException('theme_layout_entity_phtml_missing: ' . $phtml);
        }

        $this->primeLocaleConfigs(
            $themeId,
            $pageType,
            $status,
            $area,
            $pageScope,
            $identityKey,
            $structureOrRelease,
        );

        $rendered = $this->includeEntityPhtml($phtml);
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
        // page-level slot was empty/incomplete. Re-run required overlay on the shell when
        // any required target has a slot boundary but still lacks its widget markers.
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
            /** @var \Weline\Theme\Service\ThemeComponentCatalog $catalog */
            $catalog = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemeComponentCatalog::class,
            );
            $declarations = [];
            foreach ($catalog->getDefinitions('frontend', null) as $definition) {
                if (!\is_object($definition)) {
                    continue;
                }
                $declarations[] = [
                    'module' => (string)($definition->module ?? ''),
                    'type' => (string)($definition->type ?? ''),
                    'code' => (string)($definition->code ?? ''),
                    'default_injections' => $definition->defaultInjections ?? [],
                ];
            }
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

    private function anySlotRegionMissingWidget(
        string $html,
        string $slotId,
        string $module,
        string $code,
    ): bool {
        $regions = $this->boundaryScanner->enumerateRegions($html, $slotId);
        if ($regions !== []) {
            $sawRegion = false;
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
                if (!RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                    return true;
                }
            }
            if ($sawRegion) {
                return false;
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

        return \Weline\Framework\Manager\ObjectManager::getInstance(RequiredDefaultInjectionStorefrontOverlay::class)
            ->append($html, $themeId, $pageType, $status, $scope, 'required', null);
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
        // Channel (or other leaf) chrome may only bake a subset of slots (e.g. only
        // footer-extras). Walk ancestors and keep the nearest non-blank inner per
        // slot so header-nav-extensions / footer-about-links still receive Blog widgets.
        $chromeByScope = [];
        $slotIds = SharedChromeService::CHROME_SLOTS;
        $lastError = null;
        foreach ($this->scopeFallbackChain($scope) as $candidateScope) {
            try {
                $candidateHtml = $this->chrome->renderCurrent($themeId, $candidateScope, null, $preview);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }
            if ($candidateHtml === '') {
                continue;
            }
            $chromeByScope[$candidateScope] = $candidateHtml;
            if (\preg_match_all('/<!--@weline-slot:([\w.-]+)-->/', $candidateHtml, $matches) > 0) {
                foreach ($matches[1] as $slotId) {
                    $slotId = (string)$slotId;
                    if ($this->sharedChrome->isChromeSlot($slotId) && !\in_array($slotId, $slotIds, true)) {
                        $slotIds[] = $slotId;
                    }
                }
            }
        }
        if ($chromeByScope === []) {
            if ($lastError instanceof \Throwable) {
                throw new \RuntimeException(
                    'theme_layout_entity_chrome_fill_failed: ' . $lastError->getMessage(),
                    0,
                    $lastError,
                );
            }

            return $html;
        }

        $bestInnerBySlot = [];
        foreach ($this->scopeFallbackChain($scope) as $candidateScope) {
            if (!isset($chromeByScope[$candidateScope])) {
                continue;
            }
            $chromeHtml = $this->composeChromeSlotTree($chromeByScope[$candidateScope], $slotIds);
            foreach ($slotIds as $slotId) {
                if (isset($bestInnerBySlot[$slotId])) {
                    continue;
                }
                $inner = $this->boundaryScanner->extractSlotInner($chromeHtml, $slotId);
                if ($inner === null || $this->isBlankChromeInner($inner)) {
                    continue;
                }
                $bestInnerBySlot[$slotId] = $inner;
            }
        }

        // Nested chrome slots are emitted as siblings in chrome.phtml (e.g.
        // header-nav-extensions / footer-about-links) while the page shell keeps
        // matching empty markers under header/footer. Inject roots first, then nested.
        foreach ($this->orderChromeSlotsForInjection(\array_keys($bestInnerBySlot)) as $slotId) {
            $html = $this->replaceSlotInner($html, $slotId, $bestInnerBySlot[$slotId]);
        }

        return $html;
    }

    private function isBlankChromeInner(string $inner): bool
    {
        $trim = \trim($inner);
        if ($trim === '') {
            return true;
        }
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
     * Roots first so nested page markers inside header/footer survive, then
     * nested chrome slots (longer ids) fill header-nav-extensions etc.
     *
     * @param list<string> $slotIds
     * @return list<string>
     */
    private function orderChromeSlotsForInjection(array $slotIds): array
    {
        $roots = [];
        $nested = [];
        foreach ($slotIds as $slotId) {
            $slotId = \strtolower(\trim((string)$slotId));
            if ($slotId === '') {
                continue;
            }
            if (\in_array($slotId, SharedChromeService::CHROME_SLOTS, true)) {
                $roots[$slotId] = $slotId;
            } else {
                $nested[$slotId] = $slotId;
            }
        }
        \usort($nested, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return \array_values(\array_merge(\array_values($roots), $nested));
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

        \uksort($preferred, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($preferred as $slotId => $inner) {
            $chromeHtml = $this->replaceAllSlotInners($chromeHtml, $slotId, $inner);
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

    private function resolveScope(): string
    {
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
            $context = $this->layoutResolver->buildContext($themeId, $pageType, $area, [
                'layout_option' => 'default',
                'scope' => $scope,
                'target_type' => 'global',
                'target_id' => 0,
                'locale_code' => 'default',
            ]);

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
            $context = $this->layoutResolver->buildContext($themeId, $pageType, $area, [
                'layout_option' => 'default',
                'scope' => $scope,
                'target_type' => 'global',
                'target_id' => 0,
                'locale_code' => 'default',
            ]);

            return $this->paths->identityKey($context->identityHash());
        } catch (\Throwable) {
            return $this->paths->identityKey(\hash('sha256', $pageType . '|' . $scope));
        }
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
            $phtml = $this->paths->pagePhtml($themeId, $scope, $identityKey, $segment);
            if ($this->pagePhtmlHasSlots($phtml)) {
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
        if ($published) {
            return null;
        }
        $dir = $this->paths->pageIdentityDir($themeId, $scope, $identityKey);
        if (!\is_dir($dir)) {
            return null;
        }
        $draft = [];
        $releases = [];
        $entries = \scandir($dir) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name === 'current.json') {
                continue;
            }
            $phtml = $dir . $name . \DIRECTORY_SEPARATOR . 'layout.phtml';
            if (!$this->pagePhtmlHasSlots($phtml)) {
                continue;
            }
            if (\str_starts_with($name, 's')) {
                $draft[] = $name;
            }
            if (\preg_match('/^r(\d+)$/', $name, $matches) === 1) {
                $releases[(int)$matches[1]] = $name;
            }
        }
        if ($draft !== []) {
            \rsort($draft, \SORT_STRING);

            return $draft[0];
        }
        if ($releases === []) {
            return null;
        }
        \krsort($releases, \SORT_NUMERIC);

        return \reset($releases) ?: null;
    }

    private function pagePhtmlHasSlots(string $path): bool
    {
        if (!\is_file($path)) {
            return false;
        }
        $src = (string)\file_get_contents($path);

        return $src !== '' && \str_contains($src, SlotBoundaryMarkers::OPEN_PREFIX);
    }

    private function includeEntityPhtml(string $path): string
    {
        \ob_start();
        try {
            include $path;
            $html = (string)\ob_get_clean();
        } catch (\Throwable $e) {
            if (\ob_get_level() > 0) {
                \ob_end_clean();
            }
            throw new \RuntimeException('theme_layout_entity_include_failed: ' . $e->getMessage(), 0, $e);
        }

        return $html;
    }

    private function spliceSolidifiedSlots(string $html, string $rendered): string
    {
        if (\preg_match_all('/<!--@weline-slot:([\w.-]+)-->/', $rendered, $matches) < 1) {
            return $html;
        }
        $seen = [];
        foreach ($matches[1] as $slotId) {
            $slotId = (string)$slotId;
            if (isset($seen[$slotId])) {
                continue;
            }
            $seen[$slotId] = true;
            $inner = $this->boundaryScanner->extractSlotInner($rendered, $slotId, false, true);
            if ($inner === null) {
                continue;
            }
            // Empty nested placeholders must not wipe a shell slot that already
            // has markup; only replace when the solidified inner has content.
            if (trim($inner) === '') {
                continue;
            }
            $html = $this->replaceSlotInner($html, $slotId, $inner);
        }

        return $html;
    }

    private function primeLocaleConfigs(
        int $themeId,
        string $pageType,
        string $status,
        string $area,
        string $pageScope,
        string $identityKey,
        string $structureOrRelease,
    ): void {
        try {
            $configByUid = $this->configStore->readPageConfig(
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
            if ($this->configStore->needsStructureHydration($node)) {
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
                [
                    'layout_option' => 'default',
                    'scope' => $overlayScope,
                    'target_type' => 'global',
                    'target_id' => 0,
                ],
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
                    RequestContext::set('theme.layout_entity.node.' . $uid, $widget);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function scopeFallbackChain(string $scope): array
    {
        $scope = \trim($scope);
        $chain = [];
        try {
            $identity = $this->scopes->fromStorageScope($scope, true);
            if ($identity !== null) {
                foreach ($this->scopes->chainFromIdentity($identity) as $candidate) {
                    $candidate = \trim((string)$candidate);
                    if ($candidate !== '' && !\in_array($candidate, $chain, true)) {
                        $chain[] = $candidate;
                    }
                }
            }
        } catch (\Throwable) {
            // fall through
        }
        if ($chain === [] && $scope !== '') {
            $chain[] = $scope;
        }
        if (!\in_array('default.default.default', $chain, true)) {
            $chain[] = 'default.default.default';
        }

        return $chain;
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
}
