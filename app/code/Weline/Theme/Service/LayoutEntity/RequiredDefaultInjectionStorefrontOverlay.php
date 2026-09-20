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
 * Published storefront overlay: required default injections render even when the
 * baked page file omitted them, unless this theme layout version uninstalled them.
 * Does not write layouts. Draft/editor fill must not call this.
 *
 * Zero-tolerance (有部件必入声明槽): subject is the widget. When a required widget
 * exists (Catalog declaration) and is not version-uninstalled, it MUST land in its
 * declared slot. The slot is destination only — never create ghost slots. Missing
 * destination after multi-pass is "cannot land" (no ghost), not "no obligation";
 * when the destination is present, empty/missing widget throws.
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

        $items = $this->loadRequiredItems($themeId, $pageType);
        if ($items === []) {
            return $rendered;
        }

        // Multi-pass: parent container widgets may introduce nested destinations
        // (e.g. product-info → product-purchase-actions). Declaration order alone
        // must not leave a required widget skipped forever.
        $pass = 0;
        while ($pass < 16) {
            ++$pass;
            $changed = false;
            foreach ($items as $item) {
                $slotId = (string)$item['slot_id'];
                $module = (string)$item['widget_module'];
                $code = (string)$item['widget_code'];
                if ($this->listSlotRegions($rendered, $slotId) === []) {
                    // Destination not in tree yet — do not create ghost slots.
                    // Obligation remains; a later pass may land after a parent injects.
                    continue;
                }
                $guard = 0;
                $missing = true;
                while ($missing !== null && $guard < 32) {
                    ++$guard;
                    $missing = null;
                    foreach ($this->sortRegionsDeepestFirst($this->listSlotRegions($rendered, $slotId)) as $region) {
                        $inner = (string)($region['inner'] ?? '');
                        if (!RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                            $missing = $region;
                            break;
                        }
                    }
                    if ($missing === null) {
                        break;
                    }
                    $inner = (string)($missing['inner'] ?? '');
                    $html = $this->renderNode($item['node'], $themeId, $scopeKey, $versionKey);
                    if ($html === '' || \str_starts_with(\trim($html), '<!--')) {
                        throw new \RuntimeException(
                            'required_default_injection_render_failed: '
                            . $module . '|' . $code . ' slot=' . $slotId
                        );
                    }
                    $rendered = $this->replaceRegionInner($rendered, $missing, $html . $inner);
                    $changed = true;
                }
                if ($missing !== null) {
                    throw new \RuntimeException(
                        'required_default_injection_region_loop: '
                        . $module . '|' . $code . ' slot=' . $slotId
                    );
                }
            }
            if (!$changed) {
                break;
            }
        }

        // Final: destination present ⇒ widget must be present (widget-subject zero-tolerance).
        foreach ($items as $item) {
            $slotId = (string)$item['slot_id'];
            $module = (string)$item['widget_module'];
            $code = (string)$item['widget_code'];
            $regions = $this->listSlotRegions($rendered, $slotId);
            if ($regions === []) {
                continue;
            }
            foreach ($regions as $region) {
                $inner = (string)($region['inner'] ?? '');
                if (!RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                    throw new \RuntimeException(
                        'required_default_injection_unfilled: '
                        . $module . '|' . $code . ' slot=' . $slotId
                    );
                }
            }
        }

        return $rendered;
    }

    /**
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,node:array<string,mixed>}>
     */
    private function loadRequiredItems(int $themeId, string $pageType): array
    {
        try {
            /** @var ThemeComponentCatalog $catalog */
            $catalog = ObjectManager::getInstance(ThemeComponentCatalog::class);
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

            /** @var ThemePublishedVersionRuntimeResolver $versions */
            $versions = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
            $versionId = (int)($versions->resolve($themeId, $pageType)['themePublishedVersionId'] ?? 0);

            /** @var WidgetDefaultInjectionService $injections */
            $injections = ObjectManager::getInstance(WidgetDefaultInjectionService::class);
            $omissions = $injections->uninstalledInjectionsForVersion($themeId, $pageType, $versionId);

            $items = [];
            foreach (RequiredDefaultInjectionContract::requiredInjections($declarations, $pageType) as $item) {
                if (RequiredDefaultInjectionContract::isUninstalled(
                    $omissions,
                    $item['slot_id'],
                    $item['widget_module'],
                    $item['widget_code'],
                )) {
                    continue;
                }
                $items[] = $item;
            }

            return $items;
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
     * @return list<array{inner:string,region_start:int,inner_start:int,inner_end:int,depth:int,via:string}>
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
