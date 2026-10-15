<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\State;
use Weline\Theme\Service\SharedChromeService;

/**
 * Guarantees storefront float destinations exist in outbound HTML / footer source.
 *
 * Design themes may rewrite footer and drop Theme's `#w-storefront-float-layer`.
 * Required default_injections (store-music / customer-service-float) still need
 * `storefront-float-start|end` shells — synthesize them unless already present.
 *
 * Float a11y labels: never bake Chinese source identity into published HTML for
 * non-zh locales. Request-time heal + English last-resort defaults; runtime
 * `api.floatEdge` / JS overlay remain the locale authority.
 *
 * Owning: Weline_Theme. Not a substitute for bake; safety net when destinations vanish.
 */
final class StorefrontFloatLayerHost
{
    public static function htmlHasFloatDestinations(string $html): bool
    {
        if ($html === '') {
            return false;
        }
        // Both nested float slots must exist. A leftover CSS marker
        // (data-storefront-float-layer-css) must NOT count as a destination.
        foreach (SharedChromeService::FOOTER_NESTED_CHROME_SLOTS as $slotId) {
            if (!self::htmlMentionsSlot($html, $slotId)) {
                return false;
            }
        }

        return true;
    }

    public static function htmlMentionsSlot(string $html, string $slotId): bool
    {
        return str_contains($html, 'data-slot-id="' . $slotId . '"')
            || str_contains($html, "data-slot-id='" . $slotId . "'")
            || str_contains($html, 'data-wslot="' . $slotId . '"')
            || str_contains($html, "data-wslot='" . $slotId . "'")
            || str_contains($html, 'data-testid="' . $slotId . '"')
            || preg_match('/<w:slot\b[^>]*\bid\s*=\s*["\']' . preg_quote($slotId, '/') . '["\']/i', $html) === 1;
    }

    /**
     * Post-render HTML: append published-slot shells (data-slot-id) before </body>.
     * Also self-heal: baked footers may lack data-weline-load on an existing layer
     * (hidden-host IO never fired → × chrome missing). Marker: storefront-float-load-on-layer-v1
     * Marker: storefront-float-edge-label-heal-v1
     */
    public static function ensureInHtml(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        if (self::htmlHasFloatDestinations($html)) {
            $html = self::ensureFloatLayerModuleLoadAttr($html);

            return self::healBakedChineseFloatEdgeLabels($html);
        }

        $layer = self::publishedSlotLayerMarkup();
        if (stripos($html, '</body>') !== false) {
            $html = (string)preg_replace('/<\/body>/i', $layer . '</body>', $html, 1);
        } else {
            $html .= $layer;
        }

        return self::healBakedChineseFloatEdgeLabels($html);
    }

    /**
     * Ensure #w-storefront-float-layer carries data-weline-load=storefrontFloatLayer
     * so IntersectionObserver can fire on the visible fixed layer (not a hidden stub).
     */
    public static function ensureFloatLayerModuleLoadAttr(string $html): string
    {
        if ($html === '' || !str_contains($html, 'id="w-storefront-float-layer"')) {
            return $html;
        }
        if (preg_match(
            '/<div\b[^>]*\bid\s*=\s*["\']w-storefront-float-layer["\'][^>]*\bdata-weline-load\s*=\s*["\'][^"\']*storefrontFloatLayer/i',
            $html
        ) === 1) {
            return $html;
        }

        $patched = preg_replace(
            '/(<div\b[^>]*\bid\s*=\s*["\']w-storefront-float-layer["\'][^>]*\bdata-storefront-float-layer)(\s|>)/i',
            '$1 data-weline-load="storefrontFloatLayer" data-testid="storefront-float-layer"$2',
            $html,
            1
        );
        if (is_string($patched) && $patched !== $html) {
            return $patched;
        }

        $patched = preg_replace(
            '/(<div\b[^>]*\bid\s*=\s*["\']w-storefront-float-layer["\'])(\s|>)/i',
            '$1 data-weline-load="storefrontFloatLayer" data-testid="storefront-float-layer"$2',
            $html,
            1
        );

        return is_string($patched) ? $patched : $html;
    }

    /**
     * Layout-entity bake may freeze Chinese __() into data-edge-* attrs.
     * On non-zh active locale, rewrite to English last-resort (runtime floatEdge/JS still overlay).
     */
    public static function healBakedChineseFloatEdgeLabels(string $html): string
    {
        if ($html === '' || self::activeLocaleLooksChinese()) {
            return $html;
        }
        if (!str_contains($html, '展开悬浮') && !str_contains($html, '收起悬浮')) {
            return $html;
        }

        $html = str_replace(
            [
                'data-edge-recall-label="展开悬浮"',
                "data-edge-recall-label='展开悬浮'",
                'data-edge-dismiss-label="收起悬浮"',
                "data-edge-dismiss-label='收起悬浮'",
            ],
            [
                'data-edge-recall-label="Expand float"',
                'data-edge-recall-label="Expand float"',
                'data-edge-dismiss-label="Collapse float"',
                'data-edge-dismiss-label="Collapse float"',
            ],
            $html
        );

        return $html;
    }

    /**
     * Source footer PHTML (pre-bake / design rewrite): restore Theme <w:slot> float layer.
     */
    public static function ensureInSourceTemplate(string $source): string
    {
        if ($source === '' || self::htmlHasFloatDestinations($source)) {
            return $source;
        }

        return rtrim($source) . "\n\n" . self::sourceSlotLayerMarkup() . "\n";
    }

    private static function activeLocaleLooksChinese(): bool
    {
        $locale = '';
        try {
            $locale = trim((string)(State::getLang() ?: ''));
        } catch (\Throwable) {
            $locale = '';
        }

        return $locale !== '' && (bool)preg_match('/^zh(_|-|$)/i', $locale);
    }

    private static function publishedSlotLayerMarkup(): string
    {
        // English last-resort defaults (not Chinese source). Request heal + api.floatEdge
        // / JS overlay supply the active locale. Marker: storefront-float-edge-label-heal-v1
        return <<<'HTML'
<style data-storefront-float-layer-critical data-required-injection-host="float-layer">
#w-storefront-float-layer{position:fixed;top:0;left:0;right:0;bottom:var(--weline-product-sticky-purchase-clearance,0px);z-index:2147482900;box-sizing:border-box;pointer-events:none;padding-bottom:0}
#w-storefront-float-layer .w-storefront-float-layer__slot{position:absolute;pointer-events:auto;max-width:min(100vw,28rem);transition:transform var(--weline-theme-duration-normal,.28s) var(--weline-theme-easing-standard,cubic-bezier(.22,1,.36,1));will-change:auto}
#w-storefront-float-layer .w-storefront-float-layer__slot--start{left:max(.85rem,env(safe-area-inset-left,0px));bottom:max(.95rem,env(safe-area-inset-bottom,0px))}
#w-storefront-float-layer .w-storefront-float-layer__slot--end{right:max(var(--weline-space-5,1.25rem),env(safe-area-inset-right,0px));bottom:max(var(--weline-space-5,1.25rem),env(safe-area-inset-bottom,0px))}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed{will-change:transform}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-slot=start],#w-storefront-float-layer .w-storefront-float-layer__slot--start.is-edge-collapsed:has(.w-store-music){left:max(0px,env(safe-area-inset-left,0px))!important;transform:translateX(-100%)}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-slot=end]{right:max(0px,env(safe-area-inset-right,0px))!important;transform:translateX(100%)}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed .w-storefront-float-edge__body,#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed .w-storefront-float-edge__body *{visibility:hidden!important;opacity:0!important;pointer-events:none!important}
#w-storefront-float-layer .w-store-music[data-store-music],#w-storefront-float-layer .customer-service-widget{position:relative!important;left:auto!important;right:auto!important;bottom:auto!important;top:auto!important}
@media (max-width:720px){#w-storefront-float-layer .w-store-music__avatar{width:2.5rem;height:2.5rem}#w-storefront-float-layer .cs-chat-button{width:2.5rem;height:2.5rem}}
</style>
<div id="w-storefront-float-layer" class="w-storefront-float-layer" data-storefront-float-layer data-weline-load="storefrontFloatLayer" data-testid="storefront-float-layer" data-required-injection-host="float-layer" aria-live="off">
<div data-slot-id="storefront-float-start" data-slot-multiple="true" data-testid="storefront-float-start" data-float-slot="start" data-edge-dismiss-label="Collapse float" data-edge-recall-label="Expand float" class="w-storefront-float-layer__slot w-storefront-float-layer__slot--start theme-published-slot"></div>
<div data-slot-id="storefront-float-end" data-slot-multiple="true" data-testid="storefront-float-end" data-float-slot="end" data-edge-dismiss-label="Collapse float" data-edge-recall-label="Expand float" class="w-storefront-float-layer__slot w-storefront-float-layer__slot--end theme-published-slot"></div>
</div>
HTML;
    }

    private static function sourceSlotLayerMarkup(): string
    {
        // Keep __() live in source templates so bake/materialize can re-resolve per locale.
        // Non-zh fallback mirrors footer partial (active_locale_must_show_target_language).
        return <<<'HTML'
<?php
/**
 * Required injection host (Theme): restored when a design theme footer omitted float slots.
 * Cross-module widgets enter via default_injections — do not embed foreign <w:widget> here.
 */
$floatLayerCssUrl = '';
try {
    $floatLayerCssUrl = (string)$this->fetchTagSource(
        \Weline\Framework\View\Data\DataInterface::dir_type_STATICS,
        'Weline_Theme::css/storefront-float-layer.css'
    );
} catch (\Throwable) {
    $floatLayerCssUrl = '';
}
$floatLayerCssUrl = htmlspecialchars($floatLayerCssUrl, ENT_QUOTES, 'UTF-8');
$floatEdgeLabelSources = ['收起悬浮', '展开悬浮'];
$floatEdgeLocale = '';
try {
    $floatEdgeLocale = trim((string)(\Weline\Framework\App\State::getLang() ?: ''));
} catch (\Throwable) {
    $floatEdgeLocale = '';
}
if ($floatEdgeLocale === '') {
    $floatEdgeLocale = 'en_US';
}
try {
    \Weline\Framework\Phrase\Parser::prefetchWords($floatEdgeLabelSources, $floatEdgeLocale);
} catch (\Throwable) {
}
$floatEdgeDismissRaw = trim((string)__('收起悬浮'));
$floatEdgeRecallRaw = trim((string)__('展开悬浮'));
$floatEdgeLangIsZh = (bool)preg_match('/^zh(_|-|$)/i', $floatEdgeLocale);
if (!$floatEdgeLangIsZh) {
    if ($floatEdgeDismissRaw === '' || $floatEdgeDismissRaw === '收起悬浮') {
        $floatEdgeDismissRaw = 'Collapse float';
    }
    if ($floatEdgeRecallRaw === '' || $floatEdgeRecallRaw === '展开悬浮') {
        $floatEdgeRecallRaw = 'Expand float';
    }
}
$floatEdgeDismissLabel = htmlspecialchars($floatEdgeDismissRaw !== '' ? $floatEdgeDismissRaw : 'Collapse float', ENT_QUOTES, 'UTF-8');
$floatEdgeRecallLabel = htmlspecialchars($floatEdgeRecallRaw !== '' ? $floatEdgeRecallRaw : 'Expand float', ENT_QUOTES, 'UTF-8');
?>
<?php if ($floatLayerCssUrl !== ''): ?>
<link rel="stylesheet" href="<?= $floatLayerCssUrl ?>" data-storefront-float-layer-css data-required-injection-host="float-layer">
<?php endif; ?>
<style data-storefront-float-layer-critical data-required-injection-host="float-layer">
#w-storefront-float-layer{position:fixed;top:0;left:0;right:0;bottom:var(--weline-product-sticky-purchase-clearance,0px);z-index:2147482900;box-sizing:border-box;pointer-events:none;padding-bottom:0}
#w-storefront-float-layer .w-storefront-float-layer__slot{position:absolute;pointer-events:auto;max-width:min(100vw,28rem);transition:transform var(--weline-theme-duration-normal,.28s) var(--weline-theme-easing-standard,cubic-bezier(.22,1,.36,1));will-change:auto}
#w-storefront-float-layer .w-storefront-float-layer__slot--start{left:max(.85rem,env(safe-area-inset-left,0px));bottom:max(.95rem,env(safe-area-inset-bottom,0px))}
#w-storefront-float-layer .w-storefront-float-layer__slot--end{right:max(var(--weline-space-5,1.25rem),env(safe-area-inset-right,0px));bottom:max(var(--weline-space-5,1.25rem),env(safe-area-inset-bottom,0px))}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed{will-change:transform}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-slot=start],#w-storefront-float-layer .w-storefront-float-layer__slot--start.is-edge-collapsed:has(.w-store-music){left:max(0px,env(safe-area-inset-left,0px))!important;transform:translateX(-100%)}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-slot=end]{right:max(0px,env(safe-area-inset-right,0px))!important;transform:translateX(100%)}
#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed .w-storefront-float-edge__body,#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed .w-storefront-float-edge__body *{visibility:hidden!important;opacity:0!important;pointer-events:none!important}
#w-storefront-float-layer .w-store-music[data-store-music],#w-storefront-float-layer .customer-service-widget{position:relative!important;left:auto!important;right:auto!important;bottom:auto!important;top:auto!important}
@media (max-width:720px){#w-storefront-float-layer .w-store-music__avatar{width:2.5rem;height:2.5rem}#w-storefront-float-layer .cs-chat-button{width:2.5rem;height:2.5rem}}
</style>
<div id="w-storefront-float-layer"
     class="w-storefront-float-layer"
     data-storefront-float-layer
     data-weline-load="storefrontFloatLayer"
     data-testid="storefront-float-layer"
     data-required-injection-host="float-layer"
     aria-live="off">
    <w:slot id="storefront-float-start"
            name="悬浮左侧"
            accept="store-music,layout-storefront-float-start,content"
            reject="header,footer,navigation"
            multiple="true"
            append="true"
            position="footer"
            class="w-storefront-float-layer__slot w-storefront-float-layer__slot--start"
            data-float-slot="start"
            data-edge-dismiss-label="<?= $floatEdgeDismissLabel ?>"
            data-edge-recall-label="<?= $floatEdgeRecallLabel ?>"
            data-testid="storefront-float-start"></w:slot>
    <w:slot id="storefront-float-end"
            name="悬浮右侧"
            accept="customer-service-float,layout-storefront-float-end,content"
            reject="header,footer,navigation"
            multiple="true"
            append="true"
            position="footer"
            class="w-storefront-float-layer__slot w-storefront-float-layer__slot--end"
            data-float-slot="end"
            data-edge-dismiss-label="<?= $floatEdgeDismissLabel ?>"
            data-edge-recall-label="<?= $floatEdgeRecallLabel ?>"
            data-testid="storefront-float-end"></w:slot>
</div>
HTML;
    }
}
