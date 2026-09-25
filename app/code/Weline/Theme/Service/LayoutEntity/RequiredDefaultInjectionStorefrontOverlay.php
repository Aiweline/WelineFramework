<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Theme\Helper\ProductCardAddToCartParams;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Published storefront overlay: required default injections by plan.
 *
 * Pipeline (P0): SlotInventory (Catalog closure) → InjectionPlanner (topo by depth)
 * → execute one pass per depth layer → assert. Does not write layouts.
 * HTML multi-pass discovery is not the default algorithm.
 *
 * Storefront default_injections (REQ-THEME-0036, 2026-09-21 user纠偏):
 * Best-effort only — try declared JSON injections; unfilled must NOT 500.
 * Identity XOR（同模块同部件）: layout 已内嵌该部件 → 禁止再留同部件的
 * `default_injections` JSON（勿布局+JSON 各注一遍）。跨模块：禁止布局互调部件，
 * 外国部件只能走拥有模块 JSON + 空槽。仅 `user_deleted@{versionId}` 从 plan 省略。
 * Multiple 槽内多部件并存 OK（append）。Exclusive：整区替换防同码叠层。
 * 页级 once / count>1 仅 soft-skip 日志。
 */
final class RequiredDefaultInjectionStorefrontOverlay
{
    public function __construct(
        private readonly SlotBoundaryScanner $boundaryScanner,
    ) {
    }

    /**
     * @param list<string>|null $slotAllowlist When set (N1 filter safety-net), only plan
     *        items whose slot_id is in this list run. Identity XOR still skips present widgets;
     *        empty destinations still render — Overlay is not a full-page main path.
     */
    public function append(
        string $rendered,
        int $themeId,
        string $pageType,
        string $status,
        string $scopeKey,
        string $versionKey,
        ?string $structurePath,
        ?array $slotAllowlist = null,
    ): string {
        unset($structurePath);
        if ($status !== ThemeLayout::STATUS_PUBLISHED || $themeId < 1 || \trim($pageType) === '') {
            return $rendered;
        }
        // Published Taglib shells may only carry data-slot-id (no <!--@weline-slot-->).
        // Still allow overlay when wrapper destinations exist.
        if ($rendered === ''
            || (!\str_contains($rendered, '<!--@weline-slot:')
                && !\str_contains($rendered, 'data-wslot=')
                && !\str_contains($rendered, 'data-slot-id=')
                && !\str_contains($rendered, 'widget-slot-area'))
        ) {
            return $rendered;
        }

        $allow = null;
        if ($slotAllowlist !== null) {
            $allow = [];
            foreach ($slotAllowlist as $slotId) {
                $slotId = \strtolower(\trim((string)$slotId));
                if ($slotId !== '') {
                    $allow[$slotId] = true;
                }
            }
            if ($allow === []) {
                return $rendered;
            }
        }

        [$declarations, $plan] = $this->loadPlan($themeId, $pageType);
        unset($declarations);
        if ($plan === []) {
            return $rendered;
        }
        if ($allow !== null) {
            $plan = \array_values(\array_filter(
                $plan,
                static function (array $item) use ($allow): bool {
                    $slotId = \strtolower(\trim((string)($item['slot_id'] ?? '')));

                    return $slotId !== '' && isset($allow[$slotId]);
                },
            ));
            if ($plan === []) {
                return $rendered;
            }
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
                try {
                    $rendered = $this->executeOne($rendered, $item, $themeId, $scopeKey, $versionKey);
                } catch (\Throwable $e) {
                    // Soft: one widget render miss must not abort sibling chrome inherits
                    // (footer-*-links on cart/checkout when pageType was empty).
                    if (\function_exists('w_log_warning')) {
                        w_log_warning(sprintf(
                            '[RequiredDefaultInjection] execute soft-skip %s|%s slot=%s: %s',
                            (string)($item['widget_module'] ?? ''),
                            (string)($item['widget_code'] ?? ''),
                            (string)($item['slot_id'] ?? ''),
                            $e->getMessage(),
                        ));
                    }
                }
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
            || RequiredDefaultInjectionContract::pageHasWidgetPresent($rendered, $module, $code)
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
        if (!$this->isUsableWidgetHtml($html)) {
            // Soft: missing bake config / render miss — assertFilled logs; do not abort plan.
            if (\function_exists('w_log_warning')) {
                w_log_warning(sprintf(
                    '[RequiredDefaultInjection] render soft-skip %s|%s slot=%s',
                    $module,
                    $code,
                    $slotId,
                ));
            }

            return $rendered;
        }
        if (!RequiredDefaultInjectionContract::slotInnerHasWidgetCode($html, $module, $code)) {
            $safe = \htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $html = '<div data-widget-code="' . $safe . '" data-testid="' . $safe
                . '" data-required-injection-presence="1">' . $html . '</div>';
        }

        $existingInner = (string)($target['inner'] ?? '');
        // Exclusive / wrong-bake: replace. Multiple slot with sibling widgets: append.
        // Homepage content nesting: never replace away homepage-* slot markers.
        // missing-config / comment-only inners are blank — replace, do not append stubs.
        $blankExisting = ThemeLayoutEntityPublishedSlotHost::isEffectivelyBlankSlotInner($existingInner);
        // Published shells strip <!--@weline-slot:homepage-*-->; keep nest via data-slot-id too.
        $preserveHomepageNest = $slotId === 'content'
            && (\str_contains($existingInner, '<!--@weline-slot:homepage-')
                || \preg_match('/\bdata-slot-id\s*=\s*(["\'])homepage-[\w.-]+\1/i', $existingInner) === 1
                || \str_contains($existingInner, 'homepage-section'));
        $allowMultipleAppend = !$blankExisting
            && !RequiredDefaultInjectionContract::slotInnerHasWidgetCode($existingInner, $module, $code)
            && $this->slotAllowsMultiple($rendered, $slotId, $target);
        if ($existingInner !== '' && !$blankExisting && ($preserveHomepageNest || $allowMultipleAppend)) {
            $html = $existingInner . $html;
        }

        // Required injection owns the destination region for exclusive slots (replace,
        // never `$html.$inner` prepend on mismatched bake — that caused duplicate widgets).
        // Multiple slots append above when siblings already occupy the region.
        $rendered = $this->replaceRegionInner($rendered, $target, $html);
        $this->assertSlotHasAtMostOne($rendered, $slotId, $module, $code);

        return $rendered;
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
            || RequiredDefaultInjectionContract::pageHasWidgetPresent($rendered, $module, $code)
        ) {
            $this->assertSlotHasAtMostOne($rendered, $slotId, $module, $code);

            return;
        }
        // Soft: JSON default_injections are optional at render time — layout XOR is
        // source hygiene (same widget must not be layout+JSON), not a hard storefront fill.
        if (\function_exists('w_log_warning')) {
            w_log_warning(sprintf(
                '[RequiredDefaultInjection] unfilled soft-skip %s|%s slot=%s',
                $module,
                $code,
                $slotId,
            ));
        }
    }

    /**
     * Hard-fail when a required widget appears more than once in its declared slot.
     * Only outermost regions are counted: nested same-id inners are subsets and must
     * not be concatenated (false count=2 for one physical widget).
     */
    private function assertSlotHasAtMostOne(
        string $rendered,
        string $slotId,
        string $module,
        string $code,
    ): void {
        $combined = '';
        foreach ($this->outermostSlotRegions($this->listSlotRegions($rendered, $slotId)) as $region) {
            $combined .= (string)($region['inner'] ?? '');
        }
        $count = RequiredDefaultInjectionContract::countWidgetPresent($combined, $module, $code);
        // Soft: duplicate is a source hygiene issue (delete layout copy XOR default_injections),
        // not a storefront hard-fail. Prefer layout-owned Theme chrome widgets without injection JSON.
        if ($count > 1 && \function_exists('w_log_warning')) {
            w_log_warning(sprintf(
                '[RequiredDefaultInjection] slot duplicate soft-skip %s|%s slot=%s count=%d',
                $module,
                $code,
                $slotId,
                $count,
            ));
        }
    }

    /**
     * @param list<array<string, mixed>> $regions
     * @return list<array<string, mixed>>
     */
    private function outermostSlotRegions(array $regions): array
    {
        if (\count($regions) <= 1) {
            return $regions;
        }
        $outer = [];
        foreach ($regions as $region) {
            $start = (int)($region['inner_start'] ?? -1);
            $end = (int)($region['inner_end'] ?? -1);
            if ($start < 0 || $end < $start) {
                continue;
            }
            $nested = false;
            foreach ($regions as $other) {
                if ($other === $region) {
                    continue;
                }
                $oStart = (int)($other['inner_start'] ?? -1);
                $oEnd = (int)($other['inner_end'] ?? -1);
                if ($oStart < 0 || $oEnd < $oStart) {
                    continue;
                }
                if ($start >= $oStart && $end <= $oEnd && ($start > $oStart || $end < $oEnd)) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $outer[] = $region;
            }
        }

        return $outer !== [] ? $outer : $regions;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function loadPlan(int $themeId, string $pageType): array
    {
        try {
            /** @var DefaultInjectionPlanRepository $plans */
            $plans = ObjectManager::getInstance(DefaultInjectionPlanRepository::class);
            $declarations = $plans->listDeclarations('frontend');

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
     * Multiple destinations (e.g. header-nav-extensions) keep sibling required widgets.
     * Exclusive wrong-bake still replaces (no data-wslot-multiple + single-code ownership).
     *
     * @param array<string, mixed> $target
     */
    private function slotAllowsMultiple(string $html, string $slotId, array $target): bool
    {
        $start = (int)($target['region_start'] ?? $target['inner_start'] ?? 0);
        $probeFrom = \max(0, $start - 500);
        $probe = \substr($html, $probeFrom, ($start - $probeFrom) + 80);
        if (\preg_match('/\bdata-wslot-multiple\s*=\s*(["\']?)true\1/i', $probe) === 1) {
            return true;
        }
        // Fallback: Theme chrome/footer extension slots are multiple by contract.
        $slotId = \strtolower(\trim($slotId));
        $multipleSlots = [
            // Homepage content is multiple=true in Theme layouts; published HTML often
            // drops data-wslot-multiple — floating required widgets (newsletter-popup) append.
            'content',
            'homepage-bottom',
            'header-nav-extensions',
            'header-policy-links',
            'footer-about-links',
            'footer-partner-links',
            'footer-payment-account-links',
            'footer-help-links',
        ];
        if (\in_array($slotId, $multipleSlots, true)) {
            return true;
        }
        if (\preg_match('/\bclass=(["\'])[^"\']*\bheader-nav-extensions\b[^"\']*\1/i', $probe) === 1) {
            return true;
        }

        return false;
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

        // required-default-always-present: published / stripped shells often have
        // only data-slot-id (or leftover data-wslot) — no <!--@weline-slot--> markers.
        // enumerateRegions returns [] without markers; findSlotWrapperBounds still works.
        $attrBounds = $this->boundaryScanner->findSlotWrapperBounds($html, $slotId);
        if ($attrBounds !== null) {
            $innerStart = (int)$attrBounds['inner_start'];
            $innerEnd = (int)$attrBounds['inner_end'];
            $openStart = (int)$attrBounds['open_start'];
            $closeEnd = (int)$attrBounds['close_end'];

            return [[
                'inner' => \substr($html, $innerStart, $innerEnd - $innerStart),
                'region_start' => $openStart,
                'inner_start' => $innerStart,
                'inner_end' => $innerEnd,
                'depth' => 0,
                'via' => 'attr-wrapper',
                'raw' => [
                    'id' => $slotId,
                    'depth' => 0,
                    'region_start' => $openStart,
                    'region_end' => $closeEnd,
                    'wrapper_open_start' => $openStart,
                    'wrapper_open_end' => (int)$attrBounds['open_end'],
                    'inner_start' => $innerStart,
                    'inner_end' => $innerEnd,
                    'wrapper_close_end' => $closeEnd,
                ],
            ]];
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
        $via = (string)($region['via'] ?? '');
        if (($via === 'scanner' || $via === 'attr-wrapper') && \is_array($region['raw'] ?? null)) {
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
        // Required injection may re-render after an earlier Fiber/slot pass already
        // set once-per-request card CSS flags while that HTML was discarded/replaced.
        ProductCardRenderer::resetProductCardCssEmission();
        ProductCardAddToCartParams::resetPurchaseActionsAssetsEmission();
        try {
            /** @var ThemeLayoutEntityWidgetRenderer $renderer */
            $renderer = ObjectManager::getInstance(ThemeLayoutEntityWidgetRenderer::class);
            $html = $renderer->render($uid, 'page', $themeId, $scopeKey, $versionKey);
            if ($this->isUsableWidgetHtml($html)) {
                return $html;
            }
        } catch (\Throwable) {
            // Fall through to direct placeable render.
        }

        try {
            ProductCardRenderer::resetProductCardCssEmission();
            ProductCardAddToCartParams::resetPurchaseActionsAssetsEmission();
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
            if (!$this->isUsableWidgetHtml($html)) {
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

    /**
     * True when rendered widget HTML has real markup (not comment-only stubs).
     * ThemeComponentRenderer wraps widgets with <!--weline-widget:start--> — that is usable.
     * Entity missing-config / invalid-widget comments alone are not.
     */
    private function isUsableWidgetHtml(string $html): bool
    {
        $trim = \trim($html);
        if ($trim === '') {
            return false;
        }
        // Strip leading HTML comments (asset markers or stub-only).
        $withoutLeadingComments = \preg_replace('/^(?:\s*<!--.*?-->\s*)+/s', '', $trim);
        $withoutLeadingComments = \is_string($withoutLeadingComments) ? \trim($withoutLeadingComments) : '';

        return $withoutLeadingComments !== '';
    }
}
