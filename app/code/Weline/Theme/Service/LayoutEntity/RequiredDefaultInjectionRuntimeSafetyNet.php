<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Widget\Cache\WidgetOutputCache;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Storefront safety net: required default_injections must appear unless user_deleted.
 *
 * Steady path remains bake → derived PHTML. When solidify falls back, Overlay is stubbed,
 * or a design theme rewrote layouts and dropped destination slots, this still XOR-injects
 * missing required widgets into (or after synthesizing) their declared slots.
 *
 * Owning: Weline_Theme. Does not replace bake; never stacks a second copy when present;
 * never revives user_deleted@{versionId}.
 */
final class RequiredDefaultInjectionRuntimeSafetyNet
{
    /**
     * TTL (seconds) for cached chrome-widget markup injected by this safety net.
     * These widgets carry no per-product data; a short TTL still bounds staleness
     * after a theme/config edit publishes new markup.
     */
    private const REQUIRED_INJECTION_WIDGET_TTL = 300;

    /** Process-local memo of widget cache keys; bounded to avoid growth. */
    private static array $widgetKeyMemo = [];

    public function ensure(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        TemplateRenderRouteTrace::armIfRequested();
        TemplateRenderRouteTrace::mark('safety_net', 'ensure_enter');
        // Theme-switch solidify gate: editor canvas still needs Filters XOR when
        // source layouts show declaration placeholders. Full required Overlay stays
        // storefront-only so edit chrome is not flooded with every injection.
        if ($this->isEditorCanvas()) {
            TemplateRenderRouteTrace::mark('safety_net', 'editor_canvas_filters_xor');
            return $this->finishFiltersXor($html);
        }

        // Theme-rewritten footers may omit float destinations entirely.
        $html = StorefrontFloatLayerHost::ensureInHtml($html);

        $pageType = $this->resolvePageType();
        if ($pageType === '') {
            $pageType = ThemeLayout::PAGE_TYPE_HOME;
        }
        // Belt: if request identity missed layout name / SEO-rewrote /product/ away, infer PDP
        // from storefront markup so product required XOR (help-pay) still runs.
        if (
            ($pageType === ThemeLayout::PAGE_TYPE_HOME || $pageType === 'default')
            && (
                str_contains($html, 'product-native-detail')
                || str_contains($html, 'data-testid="storefront-product-detail"')
                || str_contains($html, "data-testid='storefront-product-detail'")
            )
        ) {
            $pageType = ThemeLayout::PAGE_TYPE_PRODUCT;
        }

        $themeId = $this->resolveFrontendThemeId();
        $declarations = $this->loadDeclarations();
        if ($declarations === []) {
            return $this->finishFiltersXor($html);
        }

        $omissions = $themeId > 0
            ? $this->omissionsFor($themeId, $pageType)
            : [];

        $targets = RequiredDefaultInjectionContract::requiredInjections($declarations, $pageType);
        // One-pass index of slot×widget pairs: presence is asked once per target×alias,
        // which rescanning a 500KB page per question made O(targets × page size).
        $wrapperIndex = $targets === []
            ? null
            : RequiredDefaultInjectionContract::buildWidgetWrapperIndex($html);
        // Only an actual injection can leave orphan float-edge slots that need re-adoption.
        // Re-running the float-edge DOM pass on an untouched document is pure cost: it
        // parses + re-serialises the whole page (measured 5.1ms CPU on 478KB) and always
        // produced an unchanged document.
        $injectedAnything = false;
        foreach ($targets as $item) {
            $slotId = trim((string)($item['slot_id'] ?? ''));
            $module = trim((string)($item['widget_module'] ?? ''));
            $code = trim((string)($item['widget_code'] ?? ''));
            if ($slotId === '' || $module === '' || $code === '') {
                continue;
            }
            if (RequiredDefaultInjectionContract::isUninstalled($omissions, $slotId, $module, $code)) {
                continue;
            }
            // Per declared slot — mini-cart coupon must not suppress checkout/cart summary slots.
            if ($this->widgetAlreadyEffective($html, $slotId, $module, $code, $wrapperIndex)) {
                TemplateRenderRouteTrace::mark(
                    'safety_net_skip_present',
                    $module . '/' . $code . '@' . $slotId
                );
                continue;
            }
            if (!$this->htmlMentionsSlot($html, $slotId)
                && !StorefrontFloatLayerHost::htmlHasFloatDestinations($html)
            ) {
                // Non-float destinations without a slot: cannot invent arbitrary page slots.
                continue;
            }
            if (!$this->htmlMentionsSlot($html, $slotId)
                && in_array($slotId, ['storefront-float-start', 'storefront-float-end', 'storefront-tail-chrome'], true)
            ) {
                $html = StorefrontFloatLayerHost::ensureInHtml($html);
            }
            if (!$this->htmlMentionsSlot($html, $slotId)) {
                continue;
            }

            $node = is_array($item['node'] ?? null) ? $item['node'] : [];
            TemplateRenderRouteTrace::mark(
                'safety_net_inject',
                $module . '/' . $code . '@' . $slotId
            );
            $widgetHtml = $this->renderWidget($module, $code, $slotId, $node, $themeId);
            if ($widgetHtml === '') {
                continue;
            }
            $html = $this->injectIntoSlot($html, $slotId, $widgetHtml);
            $injectedAnything = true;
        }

        // Float-edge SSR may have run before XOR injects; re-adopt orphans into edge body.
        // Marker: storefront-float-edge-ssr-body-v3
        // Skipped when nothing was injected: ensureInHtml() already normalised the float
        // edges for this document, so a second full DOM parse cannot change anything.
        if ($injectedAnything) {
            $html = StorefrontFloatLayerHost::ensureFloatEdgeShellsInHtml($html);
        }

        return $this->finishFiltersXor($html);
    }

    private function finishFiltersXor(string $html): string
    {
        try {
            /** @var RequiredFilterSlotFallbackInjector $filters */
            $filters = ObjectManager::getInstance(RequiredFilterSlotFallbackInjector::class);

            return $filters->ensure($html);
        } catch (\Throwable) {
            return $html;
        }
    }

    private function isEditorCanvas(): bool
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            $flag = trim((string)$request->getParam('editor_mode', ''));
            if ($flag === '1' || strtolower($flag) === 'true') {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }
        try {
            /** @var PreviewContextService $preview */
            $preview = ObjectManager::getInstance(PreviewContextService::class);
            if ($preview->isEditorThemeRequest()) {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }

    private function resolvePageType(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

            // Prefer solidified layout name (product.default) set by SolidifiedControllerTemplateResolver
            // before finalizeFrontendHtml — SEO rewrites may drop /product/ from getUrlPath().
            $layoutName = trim((string)\Weline\Framework\Runtime\RequestContext::get(
                \Weline\Widget\Cache\WidgetOutputCache::REQUEST_LAYOUT_NAME_KEY,
                '',
            ));
            if ($layoutName !== '' && str_contains($layoutName, '.')) {
                $fromLayout = trim((string)explode('.', $layoutName, 2)[0]);
                if ($fromLayout !== '') {
                    $mapped = trim((string)$resolver->resolvePageType($fromLayout, null, $request, ''));

                    return $mapped !== '' ? $mapped : $fromLayout;
                }
            }

            $layoutType = trim((string)$request->getParam('layout_type', ''));
            if ($layoutType === '') {
                $path = trim((string)$request->getUrlPath());
                // Canonical URI map — /product/{slug} must be product, not homepage fallback.
                // Legacy heuristics below only covered list/category and silently returned '' for PDP,
                // so ensure() coerced pageType→homepage and skipped product-help-pay XOR.
                $fromUri = trim((string)$resolver->resolvePageTypeFromUri($path));
                if ($fromUri !== '' && $fromUri !== 'default') {
                    return $fromUri;
                }
                if ($path === '' || $path === '/') {
                    return ThemeLayout::PAGE_TYPE_HOME;
                }
                if (str_contains($path, '/products') || preg_match('#^/?products(?:/|$)#', $path) === 1) {
                    return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
                }
                if (str_contains($path, '/category') || preg_match('#^/?categor(?:y|ies)(?:/|$)#', $path) === 1) {
                    return ThemeLayout::PAGE_TYPE_CATEGORY;
                }
                // Explicit PDP path even when URI map returns default for bare slugs.
                if (preg_match('#(^|/)product(?:/|$)#', $path) === 1) {
                    return ThemeLayout::PAGE_TYPE_PRODUCT;
                }

                return '';
            }

            return trim((string)$resolver->resolvePageType($layoutType, null, $request, ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveFrontendThemeId(): int
    {
        try {
            $id = (int)(ThemeData::getCurrentTheme()?->getId() ?? 0);
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // fall through
        }
        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $themeContext->resolveTheme('frontend', null, true);
            $id = (int)($theme?->getId() ?? 0);
            if ($id > 0) {
                return $id;
            }

            return (int)($themeContext->resolveRegisteredDefaultTheme('frontend')?->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDeclarations(): array
    {
        try {
            /** @var DefaultInjectionPlanRepository $plans */
            $plans = ObjectManager::getInstance(DefaultInjectionPlanRepository::class);

            return $plans->listDeclarations('frontend');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function omissionsFor(int $themeId, string $pageType): array
    {
        try {
            /** @var ThemePublishedVersionRuntimeResolver $versions */
            $versions = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
            $versionId = $versions->resolvePublishedNumericVersionId($themeId, $pageType);
            /** @var WidgetDefaultInjectionService $injections */
            $injections = ObjectManager::getInstance(WidgetDefaultInjectionService::class);

            return $injections->uninstalledInjectionsForVersion($themeId, $pageType, $versionId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Slot-scoped presence, made resilient to slot renames.
     *
     * Invariant (kept deliberately): presence is PER DECLARED SLOT, not page-wide —
     * one widget may be legitimately required into several different slots
     * (e.g. `checkout-coupon` in `footer-extras` + `checkout-summary-discount`), and a
     * page-level check would wrongly suppress the second slot.
     *
     * What we add: the declared slot may still carry a pre-rename id while the layout
     * renders into the renamed one (`product-main` vs `design-product-main`). Checking
     * the semantically-same slot family prevents the override from being injected twice
     * while keeping the per-slot XOR semantics intact.
     */
    private function widgetAlreadyEffective(
        string $html,
        string $slotId,
        string $module,
        string $code,
        ?array $wrapperIndex = null,
    ): bool {
        return RequiredDefaultInjectionContract::slotFamilyHasWidgetPresent($html, $slotId, $module, $code, $wrapperIndex);
    }

    private function htmlMentionsSlot(string $html, string $slotId): bool
    {
        // Called once per required target on every request; each call scans the whole
        // page seven times (~0.67ms on 575KB). Memoize per (document, slot).
        // Fingerprint with length + head/tail slices: md5() would itself scan 575KB.
        static $memo = [];
        $docKey = \strlen($html) . ':' . \substr($html, 0, 64) . ':' . \substr($html, -128);
        if (isset($memo[$docKey])) {
            if (\array_key_exists($slotId, $memo[$docKey]['slots'])) {
                return $memo[$docKey]['slots'][$slotId];
            }
        } else {
            if (\count($memo) >= 8) {
                $memo = [];
            }
            $memo[$docKey] = ['slots' => []];
        }

        $found = str_contains($html, 'data-slot-id="' . $slotId . '"')
            || str_contains($html, "data-slot-id='" . $slotId . "'")
            || str_contains($html, 'data-wslot="' . $slotId . '"')
            || str_contains($html, "data-wslot='" . $slotId . "'")
            || str_contains($html, 'id="' . $slotId . '"')
            || str_contains($html, 'data-testid="' . $slotId . '"')
            || preg_match('/<w:slot\b[^>]*\bid\s*=\s*["\']' . preg_quote($slotId, '/') . '["\']/i', $html) === 1;

        $memo[$docKey]['slots'][$slotId] = $found;

        return $found;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderWidget(string $module, string $code, string $slotId, array $node, int $themeId): string
    {
        // These are chrome widgets (footer links, nav, newsletter). Their markup depends
        // only on module+code+slot+config+theme+lang+currency — never on the current
        // product or page — yet the safety net re-rendered all of them on every request
        // (measured 4–460ms each, ~20 injects per PDP).
        //
        // Cache the rendered markup and re-stamp only the per-instance `data-uid` nonce on
        // the way out, so two widgets never share a uid on one page (widget JS binds via
        // [data-uid="..."]).
        $cacheKey = '';
        try {
            // buildKey() ignores unknown keys: the identifying parts must go through
            // `identity` / `node_uid` / `template_path`, otherwise every widget would
            // share one cache entry (verified: footer-blog-link returned newsletter HTML).
            $config = \is_array($node['config'] ?? null) ? $node['config'] : [];
            // buildKey() resolves the environment scope per call (~2ms measured) and this
            // runs for every injected widget on every request — memoize by identity. The
            // memo index is keyed by the framework environment hash (lang / currency /
            // website / area), so a worker serving several locales never reuses one
            // locale's key for another.
            $memoKey = $module . '|' . $code . '|' . $slotId . '|' . $themeId . '|'
                . \md5((string)\json_encode($config)) . '|'
                . KeyBuilder::environmentHash();
            if (!isset(self::$widgetKeyMemo[$memoKey])) {
                if (\count(self::$widgetKeyMemo) >= 256) {
                    self::$widgetKeyMemo = [];
                }
                self::$widgetKeyMemo[$memoKey] = WidgetOutputCache::buildKey([
                    'identity' => $module . '|' . $code . '|' . $slotId . '|runtime-required-injection',
                    'node_uid' => 'runtime-required-' . $slotId . '-' . $code,
                    'theme_id' => $themeId,
                    'layout' => 'runtime-required-injection',
                ], $config);
            }
            $cacheKey = self::$widgetKeyMemo[$memoKey];
        } catch (\Throwable) {
            $cacheKey = '';
        }

        $render = function () use ($module, $code, $slotId, $node, $themeId): string {
            return $this->renderWidgetUncached($module, $code, $slotId, $node, $themeId);
        };

        if ($cacheKey === '') {
            return $render();
        }

        try {
            $html = WidgetOutputCache::remember(self::REQUIRED_INJECTION_WIDGET_TTL, $cacheKey, $render);
        } catch (\Throwable) {
            return $render();
        }

        return self::restampWidgetUid($html);
    }

    /**
     * Give a cached widget body a fresh per-instance uid.
     *
     * `data-uid="wc-<code>-<8 hex>"` is a JS instance selector; reusing one value across
     * widgets would make `querySelector` bind the wrong node.
     */
    private static function restampWidgetUid(string $html): string
    {
        if ($html === '' || !str_contains($html, 'data-uid=')) {
            return $html;
        }
        $nonce = \bin2hex(\random_bytes(4));
        $replaced = \preg_replace_callback(
            '/data-uid="([^"]*?)-[0-9a-f]{8}"/',
            static fn(array $m): string => 'data-uid="' . $m[1] . '-' . $nonce . '"',
            $html,
        );

        return \is_string($replaced) ? $replaced : $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderWidgetUncached(string $module, string $code, string $slotId, array $node, int $themeId): string
    {
        try {
            /** @var ThemeLayoutEntityWidgetRenderer $renderer */
            $renderer = ObjectManager::getInstance(ThemeLayoutEntityWidgetRenderer::class);
            $entry = [
                'node_uid' => (string)($node['node_uid'] ?? ('runtime-required-' . $slotId . '-' . $code)),
                'widget_module' => $module,
                'widget_code' => $code,
                'widget_type' => (string)($node['widget_type'] ?? 'content'),
                'is_active' => true,
                'slot_id' => $slotId,
                'config' => is_array($node['config'] ?? null) ? $node['config'] : [],
                'source' => 'default_injection_runtime_safety_net',
            ];
            $identity = $themeId > 0
                ? ThemeVersionIdentity::fromArray([
                    'theme_id' => $themeId,
                    'canonical_scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => 'frontend',
                    'theme_version_id' => 0,
                    'mode' => 'formal',
                    'content_revision' => 0,
                ])
                : null;

            return trim($renderer->renderResolved($entry, [], [], $identity));
        } catch (\Throwable) {
            return '';
        }
    }

    private function injectIntoSlot(string $html, string $slotId, string $widgetHtml): string
    {
        $quoted = preg_quote($slotId, '/');
        $html2 = preg_replace(
            '/(<div\b[^>]*\bdata-slot-id\s*=\s*(["\'])' . $quoted . '\2[^>]*>)/i',
            '$1' . $widgetHtml,
            $html,
            1
        );
        if (is_string($html2) && $html2 !== $html) {
            return $html2;
        }
        $html3 = preg_replace(
            '/(<div\b[^>]*\bdata-wslot\s*=\s*(["\'])' . $quoted . '\2[^>]*>)/i',
            '$1' . $widgetHtml,
            $html,
            1
        );
        if (is_string($html3) && $html3 !== $html) {
            return $html3;
        }

        return $html;
    }
}
