<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Theme\Service\SharedChromeService;

/**
 * Guarantees storefront float destinations exist in outbound HTML / footer source.
 *
 * Design themes may rewrite footer and drop Theme's `#w-storefront-float-layer`.
 * Required default_injections (store-music / customer-service-float) still need
 * `storefront-float-start|end` shells — synthesize them unless already present.
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
     */
    public static function ensureInHtml(string $html): string
    {
        if ($html === '' || self::htmlHasFloatDestinations($html)) {
            return $html;
        }

        $layer = self::publishedSlotLayerMarkup();
        if (stripos($html, '</body>') !== false) {
            return (string)preg_replace('/<\/body>/i', $layer . '</body>', $html, 1);
        }

        return $html . $layer;
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

    private static function publishedSlotLayerMarkup(): string
    {
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
<div data-slot-id="storefront-float-start" data-slot-multiple="true" data-testid="storefront-float-start" data-float-slot="start" data-edge-dismiss-label="收起悬浮" data-edge-recall-label="展开悬浮" class="w-storefront-float-layer__slot w-storefront-float-layer__slot--start theme-published-slot"></div>
<div data-slot-id="storefront-float-end" data-slot-multiple="true" data-testid="storefront-float-end" data-float-slot="end" data-edge-dismiss-label="收起悬浮" data-edge-recall-label="展开悬浮" class="w-storefront-float-layer__slot w-storefront-float-layer__slot--end theme-published-slot"></div>
</div>
HTML;
    }

    private static function sourceSlotLayerMarkup(): string
    {
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
            data-edge-dismiss-label="收起悬浮"
            data-edge-recall-label="展开悬浮"
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
            data-edge-dismiss-label="收起悬浮"
            data-edge-recall-label="展开悬浮"
            data-testid="storefront-float-end"></w:slot>
</div>
HTML;
    }
}
