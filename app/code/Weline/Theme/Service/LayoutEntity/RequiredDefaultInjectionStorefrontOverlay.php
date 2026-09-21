<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\ThemeComponentCatalog;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;

/**
 * Published storefront overlay: required default injections by plan.
 *
 * Pipeline (P0): SlotInventory (Catalog closure) → InjectionPlanner (topo by depth)
 * → execute one pass per depth layer → assert. Does not write layouts.
 * HTML multi-pass discovery is not the default algorithm.
 *
 * Hard rule (REQ-THEME-0036 / 有部件必入声明槽): if a widget declares
 * required=true default_injections and this published ThemeLayoutVersion has
 * NO human uninstall (`user_deleted@{versionId}`), the widget MUST appear in
 * its declared slot. Missing bake/entity/snapshot/param-only config is NOT a
 * valid omission reason. Plain `user_deleted` without version is NOT this
 * version's uninstall. Injection owns the destination region (replace, never
 * `$html.$inner` prepend) so presence failures cannot stack duplicates.
 */
final class RequiredDefaultInjectionStorefrontOverlay
{
    public function __construct(
        private readonly SlotBoundaryScanner $boundaryScanner,
    ) {
    }

    public function append(
        string $rendered,
        int $themeId,
        string $pageType,
        string $status,
        string $scopeKey,
        string $versionKey,
        ?string $structurePath,
    ): string {
        unset($structurePath);
        if ($status !== ThemeLayout::STATUS_PUBLISHED || $themeId < 1 || \trim($pageType) === '') {
            return $rendered;
        }
        if ($rendered === '' || !\str_contains($rendered, '<!--@weline-slot:')) {
            return $rendered;
        }

        [$declarations, $plan] = $this->loadPlan($themeId, $pageType);
        unset($declarations);
        if ($plan === []) {
            return $rendered;
        }

        $maxDepth = 0;
        foreach ($plan as $item) {
            $maxDepth = \max($maxDepth, (int)($item['depth'] ?? 0));
        }

        // One execute wave per depth (parent containers before nested destinations).
        for ($depth = 0; $depth <= $maxDepth; ++$depth) {
            foreach ($plan as $item) {
                if ((int)($item['depth'] ?? 0) !== $depth) {
                    continue;
                }
                $rendered = $this->executeOne($rendered, $item, $themeId, $scopeKey, $versionKey);
            }
        }

        foreach ($plan as $item) {
            $this->assertFilled($rendered, $item);
        }

        return $rendered;
    }

    /**
     * @param array{slot_id:string,widget_module:string,widget_code:string,depth:int,node:array<string,mixed>} $item
     */
    private function executeOne(
        string $rendered,
        array $item,
        int $themeId,
        string $scopeKey,
        string $versionKey,
    ): string {
        $slotId = (string)$item['slot_id'];
        $module = (string)$item['widget_module'];
        $code = (string)$item['widget_code'];
        $regions = $this->listSlotRegions($rendered, $slotId);
        if ($regions === []) {
            // Destination not in HTML yet (parent layer should have introduced it).
            // Do not create ghost slots; assertFilled handles residual misses.
            return $rendered;
        }
        if ($this->anyRegionHasWidget($regions, $module, $code)
            || $this->pageHasWidgetBoundToSlot($rendered, $slotId, $module, $code)
        ) {
            return $rendered;
        }
        $target = null;
        foreach ($this->sortRegionsDeepestFirst($regions) as $region) {
            $inner = (string)($region['inner'] ?? '');
            if (!RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                $target = $region;
                break;
            }
        }
        if ($target === null) {
            return $rendered;
        }
        $html = $this->renderNode($item['node'], $themeId, $scopeKey, $versionKey);
        if ($html === '' || \str_starts_with(\trim($html), '<!--')) {
            throw new \RuntimeException(
                'required_default_injection_render_failed: '
                . $module . '|' . $code . ' slot=' . $slotId
            );
        }
        if (!RequiredDefaultInjectionContract::slotInnerHasWidgetCode($html, $module, $code)) {
            $safe = \htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $html = '<div data-widget-code="' . $safe . '" data-testid="' . $safe
                . '" data-required-injection-presence="1">' . $html . '</div>';
        }

        // Required injection owns the destination region: replace inner, never
        // prepend onto existing bake/soft/hook body ($html.$inner caused duplicate widgets).
        return $this->replaceRegionInner($rendered, $target, $html);
    }

    /**
     * @param array{slot_id:string,widget_module:string,widget_code:string,node?:array<string,mixed>} $item
     */
    private function assertFilled(string $rendered, array $item): void
    {
        $slotId = (string)$item['slot_id'];
        $module = (string)$item['widget_module'];
        $code = (string)$item['widget_code'];
        $regions = $this->listSlotRegions($rendered, $slotId);
        if ($regions === []) {
            // Destination absent in this HTML tree (conditional section / parent not on page).
            // P0: do not ghost-create; P1 may hard-fail non-conditional inventory slots.
            return;
        }
        if ($this->anyRegionHasWidget($regions, $module, $code)
            || $this->pageHasWidgetBoundToSlot($rendered, $slotId, $module, $code)
        ) {
            return;
        }
        throw new \RuntimeException(
            'required_default_injection_unfilled: '
            . $module . '|' . $code . ' slot=' . $slotId
        );
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function loadPlan(int $themeId, string $pageType): array
    {
        try {
            /** @var ThemeComponentCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeComponentCatalog::class);
            $declarations = [];
            foreach ($catalog->getDefinitions('frontend', null) as $definition) {
                if (!\is_object($definition)) {
                    continue;
                }
                $slots = [];
                if (isset($definition->slots) && \is_array($definition->slots)) {
                    $slots = $definition->slots;
                }
                $declarations[] = [
                    'module' => (string)($definition->module ?? ''),
                    'type' => (string)($definition->type ?? ''),
                    'code' => (string)($definition->code ?? ''),
                    'default_injections' => $definition->defaultInjections ?? [],
                    'slots' => $slots,
                ];
            }

            /** @var ThemePublishedVersionRuntimeResolver $versions */
            $versions = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
            $versionId = (int)($versions->resolve($themeId, $pageType)['themePublishedVersionId'] ?? 0);

            /** @var WidgetDefaultInjectionService $injections */
            $injections = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            $omissions = $injections->uninstalledInjectionsForVersion($themeId, $pageType, $versionId);

            $inventory = RequiredDefaultInjectionSlotInventory::build($declarations, $pageType);
            $plan = RequiredDefaultInjectionPlanner::plan($declarations, $pageType, $omissions, $inventory);

            return [$declarations, $plan];
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && \str_starts_with($e->getMessage(), 'required_default_injection_')) {
                throw $e;
            }
            throw new \RuntimeException(
                'required_default_injection_ensure_failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $regions
     */
    private function anyRegionHasWidget(array $regions, string $module, string $code): bool
    {
        foreach ($regions as $region) {
            $inner = (string)($region['inner'] ?? '');
            if (RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                return true;
            }
        }

        return false;
    }

    private function pageHasWidgetBoundToSlot(
        string $html,
        string $slotId,
        string $module,
        string $code,
    ): bool {
        unset($module);
        $code = \strtolower(\trim($code));
        $slotId = \strtolower(\trim($slotId));
        if ($code === '' || $slotId === '' || $html === '') {
            return false;
        }
        if (\preg_match_all('/<div class="widget-wrapper"\s+([^>]+)>/', $html, $matches) < 1) {
            return false;
        }
        foreach ($matches[1] as $attrs) {
            $attrs = \strtolower((string)$attrs);
            $hasCode = \str_contains($attrs, 'data-widget-code="' . $code . '"')
                || \str_contains($attrs, "data-widget-code='" . $code . "'");
            $hasSlot = \str_contains($attrs, 'data-slot-id="' . $slotId . '"')
                || \str_contains($attrs, "data-slot-id='" . $slotId . "'");
            if ($hasCode && $hasSlot) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{inner:string,region_start:int,inner_start:int,inner_end:int,depth:int,via:string,raw:?array}>
     */
    private function listSlotRegions(string $html, string $slotId): array
    {
        $regions = $this->boundaryScanner->enumerateRegions($html, $slotId);
        $out = [];
        foreach ($regions as $region) {
            if (!\is_array($region)) {
                continue;
            }
            $innerStart = (int)($region['inner_start'] ?? -1);
            $innerEnd = (int)($region['inner_end'] ?? -1);
            if ($innerStart < 0 || $innerEnd < $innerStart) {
                continue;
            }
            $out[] = [
                'inner' => \substr($html, $innerStart, $innerEnd - $innerStart),
                'region_start' => (int)($region['region_start'] ?? $innerStart),
                'inner_start' => $innerStart,
                'inner_end' => $innerEnd,
                'depth' => (int)($region['depth'] ?? 0),
                'via' => 'scanner',
                'raw' => $region,
            ];
        }
        if ($out !== []) {
            return $out;
        }

        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $openPos = \strpos($html, $open);
        if ($openPos === false) {
            return [];
        }
        $innerStart = $openPos + \strlen($open);
        $closePos = \strpos($html, $close, $innerStart);
        if ($closePos === false) {
            return [];
        }

        return [[
            'inner' => \substr($html, $innerStart, $closePos - $innerStart),
            'region_start' => $openPos,
            'inner_start' => $innerStart,
            'inner_end' => $closePos,
            'depth' => 0,
            'via' => 'marker',
            'raw' => null,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $regions
     * @return list<array<string, mixed>>
     */
    private function sortRegionsDeepestFirst(array $regions): array
    {
        \usort(
            $regions,
            static fn(array $a, array $b): int => ((int)($b['depth'] ?? 0) <=> (int)($a['depth'] ?? 0))
                ?: ((int)($b['region_start'] ?? 0) <=> (int)($a['region_start'] ?? 0)),
        );

        return $regions;
    }

    /**
     * @param array<string, mixed> $region
     */
    private function replaceRegionInner(string $html, array $region, string $newInner): string
    {
        if (($region['via'] ?? '') === 'scanner' && \is_array($region['raw'] ?? null)) {
            return $this->boundaryScanner->replaceWrapperInner($html, $region['raw'], $newInner);
        }
        $innerStart = (int)($region['inner_start'] ?? -1);
        $innerEnd = (int)($region['inner_end'] ?? -1);
        if ($innerStart < 0 || $innerEnd < $innerStart) {
            return $html;
        }

        return \substr($html, 0, $innerStart) . $newInner . \substr($html, $innerEnd);
    }

    /**
     * @param array<string, mixed> $widget
     */
    private function renderNode(array $widget, int $themeId, string $scopeKey, string $versionKey): string
    {
        $module = \trim((string)($widget['widget_module'] ?? ''));
        $code = \trim((string)($widget['widget_code'] ?? ''));
        $type = \trim((string)($widget['widget_type'] ?? ''));
        if ($module === '' || $code === '') {
            throw new \RuntimeException(
                'required_default_injection_render_failed: missing module/code'
            );
        }

        $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            $uid = \substr(\hash('sha256', $scopeKey . '|' . $module . '|' . $code), 0, 32);
            $widget['node_uid'] = $uid;
        }
        if ($type === '') {
            $widget['widget_type'] = 'product';
        }
        if (!\array_key_exists('config', $widget) || !\is_array($widget['config'])) {
            $widget['config'] = [];
        }
        $widget['is_active'] = true;

        $scopeKey = \trim($scopeKey) !== '' ? \trim($scopeKey) : 'default';
        $versionKey = \trim($versionKey) !== '' ? \trim($versionKey) : 'required';
        RequestContext::set('theme.layout_entity.node.' . $uid, $widget);
        try {
            /** @var ThemeLayoutEntityWidgetRenderer $renderer */
            $renderer = ObjectManager::getInstance(ThemeLayoutEntityWidgetRenderer::class);
            $html = $renderer->render($uid, 'page', $themeId, $scopeKey, $versionKey);
            if ($html !== '' && !\str_starts_with(\trim($html), '<!--')) {
                return $html;
            }
        } catch (\Throwable) {
            // Fall through to direct placeable render.
        }

        try {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
            /** @var \Weline\Theme\Service\ThemeComponentRenderer $componentRenderer */
            $componentRenderer = ObjectManager::getInstance(\Weline\Theme\Service\ThemeComponentRenderer::class);
            $definition = $registry->find($module, (string)($widget['widget_type'] ?? ''), $code, null, 'frontend');
            if ($definition === null) {
                throw new \RuntimeException(
                    'required_default_injection_render_failed: ' . $module . '|' . $code . ' (definition missing)'
                );
            }
            $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
            $config['_widget_instance_key'] = $uid;
            $html = (string)$componentRenderer->render($definition, $config, null, ['area' => 'frontend']);
            if ($html === '' || \str_starts_with(\trim($html), '<!--')) {
                throw new \RuntimeException(
                    'required_default_injection_render_failed: ' . $module . '|' . $code . ' (empty html)'
                );
            }

            return $html;
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && \str_starts_with($e->getMessage(), 'required_default_injection_')) {
                throw $e;
            }
            throw new \RuntimeException(
                'required_default_injection_render_failed: ' . $module . '|' . $code . ' (' . $e->getMessage() . ')',
                0,
                $e,
            );
        }
    }
}
