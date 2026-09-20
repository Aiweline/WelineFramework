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
        $slots = $this->readStructureSlots($structurePath);
        $slots = $this->seedSlotsFromRenderedWidgets($slots, $rendered);
        // Structure may list widgets that baked empty into the slot (ghost presence).
        // Drop ghosts so required merge + render can refill the empty nested slot.
        $slots = $this->dropGhostStructureWidgets($slots, $rendered);
        $merged = $this->ensureRequired($slots, $themeId, $pageType, $status);
        $additions = [];
        foreach ($merged as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            $known = [];
            foreach ($slots[$slotId] ?? [] as $existing) {
                if (!\is_array($existing)) {
                    continue;
                }
                $known[(string)($existing['widget_module'] ?? '') . '|' . (string)($existing['widget_code'] ?? '')] = true;
            }
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $key = (string)($widget['widget_module'] ?? '') . '|' . (string)($widget['widget_code'] ?? '');
                if (isset($known[$key])) {
                    continue;
                }
                $html = $this->renderNode($widget, $themeId, $scopeKey, $versionKey);
                if ($html === '' || \str_starts_with(\trim($html), '<!--')) {
                    continue;
                }
                $additions[(string)$slotId] = ($additions[(string)$slotId] ?? '') . $html;
            }
        }
        if ($additions === []) {
            return $rendered;
        }

        // 有槽才注：HTML 中无 slot 边界时禁止文末新建 ghost slot。
        foreach ($additions as $slotId => $html) {
            $inner = $this->extractSlotInnerForPresence($rendered, (string)$slotId);
            if ($inner === null) {
                continue;
            }
            $rendered = $this->replaceSlotInner($rendered, (string)$slotId, $html . $inner);
        }

        return $rendered;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @return array<string, list<array<string, mixed>>>
     */
    private function ensureRequired(array $slots, int $themeId, string $pageType, string $status): array
    {
        if ($status !== ThemeLayout::STATUS_PUBLISHED || $themeId < 1 || \trim($pageType) === '') {
            return $slots;
        }

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

            return RequiredDefaultInjectionContract::merge(
                $slots,
                $pageType,
                $declarations,
                $injections->uninstalledInjectionsForVersion($themeId, $pageType, $versionId),
            );
        } catch (\Throwable $e) {
            \error_log('[RequiredDefaultInjection] ensureRequired failed: ' . $e->getMessage());

            return $slots;
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function readStructureSlots(?string $structurePath): array
    {
        if ($structurePath === null || !\is_file($structurePath)) {
            return [];
        }
        $decoded = \json_decode((string)\file_get_contents($structurePath), true);
        $slots = \is_array($decoded['slots'] ?? null) ? $decoded['slots'] : [];
        $normalized = [];
        foreach ($slots as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            $normalized[(string)$slotId] = $widgets;
        }

        return $normalized;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @return array<string, list<array<string, mixed>>>
     */
    private function dropGhostStructureWidgets(array $slots, string $rendered): array
    {
        if ($slots === [] || $rendered === '') {
            return $slots;
        }
        $out = [];
        foreach ($slots as $slotId => $widgets) {
            if (!\is_array($widgets)) {
                continue;
            }
            $slotId = (string)$slotId;
            $inner = $this->extractSlotInnerForPresence($rendered, $slotId);
            // Slot markers missing: keep structure (append only fills existing slots).
            if ($inner === null) {
                $out[$slotId] = $widgets;
                continue;
            }
            $kept = [];
            foreach ($widgets as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $module = \trim((string)($widget['widget_module'] ?? ''));
                $code = \trim((string)($widget['widget_code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                if (RequiredDefaultInjectionContract::slotInnerHasWidgetCode($inner, $module, $code)) {
                    $kept[] = $widget;
                }
                // Ghost: listed in structure but absent from slot HTML — omit so required refill runs.
            }
            $out[$slotId] = $kept;
        }

        return $out;
    }

    /**
     * Prefer wrapper-aware scan; fall back to raw boundary markers when the slot
     * has markers but no data-wslot wrapper (empty nested compile output).
     */
    private function extractSlotInnerForPresence(string $rendered, string $slotId): ?string
    {
        // Prefer a non-empty / shallow region so nested empty placeholders inside
        // product-info do not hide widgets already rendered in the page-level slot.
        $inner = $this->boundaryScanner->extractSlotInner($rendered, $slotId, false, true);
        if ($inner !== null) {
            return $inner;
        }
        try {
            $open = SlotBoundaryMarkers::open($slotId);
            $close = SlotBoundaryMarkers::close($slotId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $openPos = \strpos($rendered, $open);
        if ($openPos === false) {
            return null;
        }
        $innerStart = $openPos + \strlen($open);
        $closePos = \strpos($rendered, $close, $innerStart);
        if ($closePos === false) {
            return null;
        }

        return \substr($rendered, $innerStart, $closePos - $innerStart);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @return array<string, list<array<string, mixed>>>
     */
    private function seedSlotsFromRenderedWidgets(array $slots, string $rendered): array
    {
        if ($rendered === '' || \preg_match_all('/<div class="widget-wrapper"\s+([^>]+)>/', $rendered, $matches) < 1) {
            return $slots;
        }
        foreach ($matches[1] as $attrs) {
            $code = $this->widgetAttr((string)$attrs, 'data-widget-code');
            $module = $this->widgetAttr((string)$attrs, 'data-widget-module');
            $slotId = $this->widgetAttr((string)$attrs, 'data-slot-id');
            if ($code === '' || $slotId === '') {
                continue;
            }
            $slots[$slotId][] = [
                'widget_module' => $module,
                'widget_code' => $code,
            ];
        }

        return $slots;
    }

    private function widgetAttr(string $attrs, string $name): string
    {
        if (\preg_match('/' . \preg_quote($name, '/') . '="([^"]*)"/', $attrs, $match) !== 1) {
            return '';
        }

        return \trim((string)$match[1]);
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
            return '';
        }

        $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
        if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            // Required merge always stamps a stable uid; synthesize one for legacy nodes.
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

        // Direct module+code render when entity sidecar/config is missing.
        try {
            /** @var \Weline\Theme\Service\ThemePlaceableRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Theme\Service\ThemePlaceableRegistry::class);
            /** @var \Weline\Theme\Service\ThemeComponentRenderer $componentRenderer */
            $componentRenderer = ObjectManager::getInstance(\Weline\Theme\Service\ThemeComponentRenderer::class);
            $definition = $registry->find($module, (string)($widget['widget_type'] ?? ''), $code, null, 'frontend');
            if ($definition === null) {
                \error_log('[RequiredDefaultInjection] renderNode failed: ' . $module . '|' . $code);

                return '';
            }
            $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
            $config['_widget_instance_key'] = $uid;
            $html = (string)$componentRenderer->render($definition, $config, null, ['area' => 'frontend']);
            if ($html === '' || \str_starts_with(\trim($html), '<!--')) {
                \error_log('[RequiredDefaultInjection] renderNode failed: ' . $module . '|' . $code);

                return '';
            }

            return $html;
        } catch (\Throwable) {
            \error_log('[RequiredDefaultInjection] renderNode failed: ' . $module . '|' . $code);

            return '';
        }
    }

    private function replaceSlotInner(string $html, string $slotId, string $newInner): string
    {
        // Prefer data-wslot / data-slot-id wrapper inner so host classes
        // (e.g. product-native-detail__actions) survive overlay append.
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
}
