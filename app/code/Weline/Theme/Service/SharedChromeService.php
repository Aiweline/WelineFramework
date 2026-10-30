<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;

/**
 * 页头/页脚全局 chrome：默认继承站点一套；本布局显式脱离后才独立。
 *
 * 持久化载体仍是 homepage workspace（存储载体，不是归属）。
 * 语义：本布局 chrome 区无本地节点 = inherit；有本地节点 = local。
 */
final class SharedChromeService
{
    public const MODE_INHERIT = 'inherit';
    public const MODE_LOCAL = 'local';

    /** @var list<string> */
    public const CHROME_AREAS = ['header', 'footer'];

    /**
     * Root chrome destinations baked into chrome.phtml / shell.phtml.
     * `delivery` sits in the chrome bake (header-adjacent strip) even though it is
     * not under the header-/footer- prefix.
     *
     * @var list<string>
     */
    public const CHROME_SLOTS = ['header', 'footer', 'delivery'];

    /**
     * Header nested slots in partials/header/default.phtml that omit the header- prefix
     * but still belong to global shared chrome (add/remove must bake chrome).
     *
     * @var list<string>
     */
    public const HEADER_NESTED_CHROME_SLOTS = [
        'top-bar',
        'top-bar-rights',
        'logo',
        'search',
        'language',
        'currency',
        'user-area',
        'all-menu',
        'category-menu',
        // Short names (legacy chrome keys) + real slot ids used in partials.
        'policy-links',
        'header-policy-links',
        'nav-extensions',
        'header-nav-extensions',
        'navigation',
        'pixel-bootstrap',
        'header-pixel-bootstrap',
    ];

    /**
     * Footer nested float slots in partials/footer/default.phtml that omit the footer- prefix
     * but still belong to global shared chrome (add/remove must bake chrome).
     *
     * @var list<string>
     */
    /**
     * Float-layer destinations only (used by StorefrontFloatLayerHost safety net).
     *
     * @var list<string>
     */
    public const FOOTER_FLOAT_LAYER_SLOTS = [
        'storefront-float-start',
        'storefront-float-end',
        'storefront-float-side',
    ];

    public const FOOTER_NESTED_CHROME_SLOTS = [
        'storefront-float-start',
        'storefront-float-end',
        'storefront-float-side',
        // Eight-direction float containers (empty by default; widgets enter via default_injections).
        'storefront-float-top-start',
        'storefront-float-top-center',
        'storefront-float-top-end',
        'storefront-float-middle-start',
        'storefront-float-bottom-center',
        // Guest-neutral tail chrome (Compare / Consent / B2B / Theme idle) — same bag as float.
        'storefront-tail-chrome',
    ];

    /**
     * Footer shell + tail chrome layout-pack CSS (one emit; modules must not scatter
     * emitModuleLayoutCssHtml from former body-end hooks).
     *
     * @var list<string>
     */
    public const PARTIALS_FOOTER_SHELL_LAYOUT_CSS = [
        'Weline_Theme::css/storefront-float-layer.css',
        'Weline_Compare::css/product-shopper-chrome.css',
        'Weline_Consent::css/consent-banner.css',
        'Weline_B2B::css/b2b-storefront.css',
    ];

    /**
     * 悬浮层边缘 chrome 的首帧 critical CSS 正文（**单一出处**，未分层）。
     *
     * 为什么是「未分层」：权威 sheet 被资产发布器包进 `@layer components`
     * （WidgetAssetArtifactPublisher::layerWidgetSourceCss），而页面里未分层的基础规则
     * （如主题 `button{min-block-size:2.5rem}`）会反过来压过分层 sheet —— 实测把本块包进
     * `@layer components` 后 chip 由 24×24 变 24×40，尺寸钉不住。故尺寸钉住与交互态都留在
     * 本块，并与 sheet 同值镜像（漂移由 FloatChromeCriticalParityTest 锁住）。
     *
     * 唯一允许的手写源 = 本常量：默认主题页脚 / design 主题页脚 / StorefrontFloatLayerHost
     * 的 heal 注入都必须调用 {@see self::emitFloatLayerCriticalCss()}，禁止再复制 CSS 正文。
     *
     * Marker: storefront-float-critical-single-source-v1
     */
    private const FLOAT_LAYER_CRITICAL_CSS = <<<'CSS'
    /* 内联 critical 保持「未分层」：权威 sheet 被发布器包进 `@layer components`，而页面里未分层的基础规则
     * （如主题 button{min-block-size:2.5rem}）会反过来压过分层 sheet —— 实测分层后 chip 从 24×24 变 24×40。
     * 因此 chip 的尺寸钉住与交互态都放在本块（未分层），并与 sheet 同值镜像。
     * Marker: storefront-float-critical-unlayered-v1 */
    /* Marker: storefront-float-edge-transform-clear-v1 */
    #w-storefront-float-layer{position:fixed;top:0;left:0;right:0;bottom:var(--weline-product-sticky-purchase-clearance,0px);z-index:2147482900;box-sizing:border-box;pointer-events:none;padding-bottom:0}
    #w-storefront-float-layer .w-storefront-float-layer__slot{position:absolute;pointer-events:auto;max-width:min(100vw,28rem);--w-float-dock-base:translate3d(0,0,0);transition:transform var(--weline-theme-duration-normal,.28s) var(--weline-theme-easing-standard,cubic-bezier(.22,1,.36,1));will-change:auto}
    #w-storefront-float-layer .w-storefront-float-layer__slot--start{left:max(.85rem,env(safe-area-inset-left,0px));bottom:max(.95rem,env(safe-area-inset-bottom,0px))}
    #w-storefront-float-layer .w-storefront-float-layer__slot--end{right:max(var(--weline-space-5,1.25rem),env(safe-area-inset-right,0px));bottom:max(var(--weline-space-5,1.25rem),env(safe-area-inset-bottom,0px))}
    #w-storefront-float-layer .w-storefront-float-layer__slot--side{right:max(var(--weline-space-5,1.25rem),env(safe-area-inset-right,0px));top:50%;transform:translateY(-50%);--w-float-dock-base:translateY(-50%);overflow:visible}
    /* Eight-direction containers (empty shells; only content-bearing ones occupy space). */
    #w-storefront-float-layer .w-storefront-float-layer__slot--top-start{left:max(.85rem,env(safe-area-inset-left,0px));top:calc(var(--weline-header-height,4.5rem) + .75rem)}
    #w-storefront-float-layer .w-storefront-float-layer__slot--top-center{left:50%;transform:translateX(-50%);--w-float-dock-base:translateX(-50%);top:calc(var(--weline-header-height,4.5rem) + .75rem)}
    #w-storefront-float-layer .w-storefront-float-layer__slot--top-end{right:max(var(--weline-space-5,1.25rem),env(safe-area-inset-right,0px));top:calc(var(--weline-header-height,4.5rem) + .75rem)}
    #w-storefront-float-layer .w-storefront-float-layer__slot--middle-start{left:max(.85rem,env(safe-area-inset-left,0px));top:50%;transform:translateY(-50%);--w-float-dock-base:translateY(-50%)}
    #w-storefront-float-layer .w-storefront-float-layer__slot--bottom-center{left:50%;transform:translateX(-50%);--w-float-dock-base:translateX(-50%);bottom:max(.95rem,env(safe-area-inset-bottom,0px))}
    /* 同意条 / 社交条让位镜像（未分层）：sheet 里这些规则在 @layer components 内，压不过本块未分层基础态
     * （实测 PIC 390px 同意条 167px 时 slot--end 仍 bottom:20px → 遮挡 41%）。必须在此同值镜像；
     * sheet 保留同值作为非 critical 场景回退。Marker: storefront-float-critical-consent-clearance-v1 */
    body:has(#weline-consent-banner:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--start{bottom:calc(max(.95rem,env(safe-area-inset-bottom,0px)) + var(--weline-consent-banner-clearance,4.75rem))}
    body:has(#weline-consent-banner:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--end:not(:has(.customer-service-widget.is-open)){bottom:calc(max(var(--weline-space-5,1.25rem),env(safe-area-inset-bottom,0px)) + var(--weline-consent-banner-clearance,4.75rem))}
    body:has(.weline-social-quick-bar:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--end:not(:has(.customer-service-widget.is-open)){bottom:calc(max(var(--weline-space-5,1.25rem),env(safe-area-inset-bottom,0px)) + 10.5rem)}
    body:has(#weline-consent-banner:not([hidden])):has(.weline-social-quick-bar:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--end:not(:has(.customer-service-widget.is-open)){bottom:calc(max(var(--weline-space-5,1.25rem),env(safe-area-inset-bottom,0px)) + 10.5rem + var(--weline-consent-banner-clearance,4.75rem))}
    /* 空壳边缘容器上收（原写在 PIC 首页布局里的 !important 主题补丁）：body 无元素子节点、只剩「×」的槽
     * 与相邻槽目标重叠，Lighthouse 判「触摸目标的尺寸或间距不足」。空壳无可展示内容，直接隐藏；
     * 有内容后 :has() 会自动恢复显示。这是框架 chrome 语义，design 主题禁止再自行 !important 强藏。
     * Marker: storefront-float-edge-empty-shell-hide-v1 */
    #w-storefront-float-layer .w-storefront-float-layer__slot:has(> .w-storefront-float-edge__chrome > .w-storefront-float-edge__body:empty){display:none}
    #w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed{will-change:transform;overflow:visible;max-height:none}
    /* One dock rule per direction — every slot carrying data-float-dock collapses the same way. Marker: storefront-float-edge-dock-directions-v2 */
    #w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-dock=left]{left:max(0px,env(safe-area-inset-left,0px))!important;right:auto;transform:var(--w-float-dock-base,) translateX(-100%)}
    #w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-dock=right]{right:max(0px,env(safe-area-inset-right,0px))!important;left:auto;transform:var(--w-float-dock-base,) translateX(100%)}
    #w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-dock=up]{transform:var(--w-float-dock-base,) translateY(-100%)}
    #w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed[data-float-dock=down]{transform:var(--w-float-dock-base,) translateY(100%)}
    #w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed .w-storefront-float-edge__body,#w-storefront-float-layer .w-storefront-float-layer__slot.is-edge-collapsed .w-storefront-float-edge__body *{visibility:hidden!important;opacity:0!important;pointer-events:none!important}
    /* Marker: storefront-float-edge-chrome-critical-v1 — edge chrome must paint without the external sheet.
     * 本块必须与 sheet `Theme/view/statics/css/storefront-float-layer.css` 的 .w-storefront-float-edge__dismiss 同值，
     * 否则 ID 选择器会反压 sheet（曾把 sheet 的 1.5rem 压回 1.25rem）。Marker: storefront-float-critical-parity-v1 */
    #w-storefront-float-layer .w-storefront-float-edge__chrome{position:absolute;inset:0;pointer-events:none;z-index:3}
    #w-storefront-float-layer .w-storefront-float-edge__body{position:relative;z-index:1}
    #w-storefront-float-layer .w-storefront-float-edge__dismiss{pointer-events:auto;position:absolute;top:-.35rem;z-index:4;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;box-sizing:border-box;width:1.5rem;height:1.5rem;min-inline-size:1.5rem;min-block-size:1.5rem;max-inline-size:1.5rem;max-block-size:1.5rem;margin:0;padding:0;border:1px solid color-mix(in srgb,var(--weline-theme-text,#111) 60%,transparent);border-radius:999px;background:var(--weline-theme-surface-raised,#fff);color:var(--weline-theme-text,#111);font-size:1rem;line-height:1;cursor:pointer;appearance:none}
    /* 交互态镜像（未分层）：sheet 的 hover 在 components 层内，赢不过本块未分层基础态 → 必须在此同步声明。 */
    #w-storefront-float-layer .w-storefront-float-edge__dismiss:is(:hover,:focus-visible){opacity:1;color:var(--weline-theme-text,#111);background:var(--weline-theme-surface-raised,#fff);border-color:color-mix(in srgb,var(--weline-theme-text,#111) 78%,transparent);outline:none}
    #w-storefront-float-layer .w-storefront-float-edge__dismiss:focus-visible{box-shadow:0 0 0 var(--weline-theme-focus-ring-width,2px) color-mix(in srgb,var(--weline-theme-primary,#0f766e) 45%,transparent)}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=left] .w-storefront-float-edge__dismiss{right:-.15rem}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=right] .w-storefront-float-edge__dismiss{left:-.15rem}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=up] .w-storefront-float-edge__dismiss{top:auto;bottom:-.35rem;left:50%;transform:translateX(-50%)}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=down] .w-storefront-float-edge__dismiss{left:50%;transform:translateX(-50%)}
    #w-storefront-float-layer .w-storefront-float-edge__recall{pointer-events:auto;position:absolute;top:50%;z-index:5;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;box-sizing:border-box;width:var(--weline-space-5,1.25rem);height:calc(var(--weline-space-8,2rem) + .25rem);min-inline-size:0;min-block-size:0;margin:0;padding:0;transform:translateY(-50%);color:var(--weline-theme-primary,#0f766e);background:var(--weline-theme-surface-raised,#fff);border:1px solid var(--weline-theme-border,#e2e8f0);box-shadow:var(--weline-theme-shadow-xs,0 1px 2px rgba(0,0,0,.08));cursor:pointer;appearance:none}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=left] .w-storefront-float-edge__recall{left:100%;border-radius:0 var(--weline-radius-md,8px) var(--weline-radius-md,8px) 0;border-left:none}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=right] .w-storefront-float-edge__recall{right:100%;border-radius:var(--weline-radius-md,8px) 0 0 var(--weline-radius-md,8px);border-right:none}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=up] .w-storefront-float-edge__recall{top:100%;left:50%;transform:translateX(-50%);border-radius:0 0 var(--weline-radius-md,8px) var(--weline-radius-md,8px);border-top:none}
    #w-storefront-float-layer .w-storefront-float-layer__slot[data-float-dock=down] .w-storefront-float-edge__recall{top:auto;bottom:100%;left:50%;transform:translateX(-50%);border-radius:var(--weline-radius-md,8px) var(--weline-radius-md,8px) 0 0;border-bottom:none}
    #w-storefront-float-layer .w-storefront-float-edge__recall:is(:hover,:focus-visible){color:var(--weline-theme-on-primary,#fff);background:var(--weline-theme-primary,#0f766e);outline:none}
    #w-storefront-float-layer .w-store-music[data-store-music],#w-storefront-float-layer .customer-service-widget{position:relative;left:auto;right:auto;bottom:auto;top:auto}
    #w-storefront-float-layer .w-store-music-host[data-store-music-host]{width:auto;height:auto;overflow:visible}
    @media (max-width:720px){
        /* 移动端档位镜像（sheet:448-516 区）：否则未分层 critical 会压掉分层 sheet 的移动端基线/让位。 */
        #w-storefront-float-layer .w-storefront-float-layer__slot--start{left:max(.55rem,env(safe-area-inset-left,0px));bottom:max(.7rem,env(safe-area-inset-bottom,0px))}
        #w-storefront-float-layer .w-storefront-float-layer__slot--end{right:max(var(--weline-space-3,.75rem),env(safe-area-inset-right,0px));bottom:max(var(--weline-space-3,.75rem),env(safe-area-inset-bottom,0px))}
        #w-storefront-float-layer .w-storefront-float-layer__slot--side{right:max(var(--weline-space-3,.75rem),env(safe-area-inset-right,0px))}
        body:has(.weline-social-quick-bar:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--end:not(:has(.customer-service-widget.is-open)){bottom:calc(max(var(--weline-space-3,.75rem),env(safe-area-inset-bottom,0px)) + 10.5rem)}
        body:has(#weline-consent-banner:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--start{bottom:calc(max(.7rem,env(safe-area-inset-bottom,0px)) + var(--weline-consent-banner-clearance,5.25rem))}
        body:has(#weline-consent-banner:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--end:not(:has(.customer-service-widget.is-open)){bottom:calc(max(var(--weline-space-3,.75rem),env(safe-area-inset-bottom,0px)) + var(--weline-consent-banner-clearance,5.25rem))}
        body:has(#weline-consent-banner:not([hidden])):has(.weline-social-quick-bar:not([hidden])) #w-storefront-float-layer .w-storefront-float-layer__slot--end:not(:has(.customer-service-widget.is-open)){bottom:calc(max(var(--weline-space-3,.75rem),env(safe-area-inset-bottom,0px)) + 10.5rem + var(--weline-consent-banner-clearance,5.25rem))}
        #w-storefront-float-layer .w-store-music__avatar{width:2.5rem;height:2.5rem}
        #w-storefront-float-layer .cs-chat-button{width:2.5rem;height:2.5rem}
    }
    CSS;

    /**
     * Nested chrome extensions inside header mini-cart (not float-layer hosts).
     * Primary path: Partials storefront_chrome MISS builder fills these into the
     * shared bag (schema v24+). Outbound fillEmptyNestedChromeSlots remains a
     * safety net when a published/incomplete shell still arrives empty.
     *
     * @var list<string>
     */
    public const MINI_CART_NESTED_CHROME_SLOTS = [
        'footer-extras',
    ];

    /**
     * Chrome host widgets that embed nested slot injections whose CSS must paint
     * on first drawer open — promote to host layout pack (blocking), not late
     * source late pack `media=print` defer (WidgetAssetOptimizer: after first N).
     *
     * @var array<string, list<string>> host widget_code => Module::css paths
     */
    public const CHROME_HOST_NESTED_LAYOUT_CSS_PEERS = [
        'mini-cart-icon' => [
            'Weline_Checkout::css/widgets/storefront-money-summary.css',
            'Weline_Marketing::css/widgets/checkout-coupon.css',
            'Weline_Marketing::css/widgets/mini-cart-coupon.css',
            'Weline_Order::css/widgets/order-notice.css',
        ],
    ];

    /**
     * Partials / design header shell CSS (same trio as header-container layout_source,
     * minus widget-instance-styles). Must be emitted with data-weline-widget-asset=layout
     * so WidgetAssetHtmlPlacement merges them into the page layout pack — never bare
     * &lt;link&gt; that bypasses css_merge.
     *
     * @var list<string>
     */
    public const PARTIALS_HEADER_SHELL_LAYOUT_CSS = [
        'Weline_Theme::css/widgets/header-chrome.css',
        'Weline_Theme::css/widgets/header-search.css',
        'Weline_Theme::css/partials/header-default.css',
        // Nested header chrome widgets — ride layout pack (avoid mid-body 1KB source packs).
        'Weline_Websites::css/widgets/scope-switcher.css',
        'Weline_Visitor::css/widgets/widget-storefront-pixel-bootstrap.css',
        // Weline UI lazy leaves used on every storefront chrome — fold into layout pack
        // so weline-ui.js does not open separate sub-1KB stylesheet RTTs.
        'Weline_Theme::ui/components/weline-choice-selector.css',
        'Weline_Theme::ui/components/weline-mega-menu.css',
    ];

    /**
     * Emit one Module::css leaf as a layout-pack marker (mergeable; never bare RTT).
     *
     * @param array<string,string> $extraAttrs HTML attribute map (data-* etc.)
     */
    public static function emitModuleLayoutCssHtml(string $modulePath, array $extraAttrs = []): string
    {
        $modulePath = \trim($modulePath);
        if ($modulePath === '' || !\str_contains($modulePath, '::')) {
            return '';
        }
        $url = '';
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $url = \trim((string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $modulePath));
        } catch (\Throwable) {
            $url = '';
        }
        if ($url === '') {
            return '';
        }
        $safeUrl = \htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeSrc = \htmlspecialchars($modulePath, ENT_QUOTES, 'UTF-8');
        $extra = '';
        foreach ($extraAttrs as $name => $value) {
            $name = \trim((string)$name);
            if ($name === '' || !\preg_match('/^[a-zA-Z_:][\w:.-]*$/', $name)) {
                continue;
            }
            $extra .= ' ' . $name . '="' . \htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<link rel="stylesheet" href="' . $safeUrl
            . '" data-weline-widget-asset="layout"'
            . ' data-weline-source-position="head"'
            . ' data-weline-module-source="' . $safeSrc . '"'
            . $extra . '>';
    }

    /**
     * Emit Partials / design shell header CSS as layout-pack markers.
     *
     * Must include data-weline-module-source so WidgetAssetArtifactPublisher::canMerge
     * can resolve the file and fold it into the page layout pack (same contract as
     * ThemeLayoutEntityAssetCollector::emitHtml). layout marker alone is not enough.
     */
    public static function emitPartialsHeaderShellLayoutCssHtml(): string
    {
        $chunks = [];
        foreach (self::PARTIALS_HEADER_SHELL_LAYOUT_CSS as $modulePath) {
            $html = self::emitModuleLayoutCssHtml($modulePath);
            if ($html !== '') {
                $chunks[] = $html;
            }
        }

        return $chunks === [] ? '' : \implode("\n", $chunks) . "\n";
    }

    /**
     * Emit footer float + tail chrome CSS as layout-pack markers (single bag wave).
     */
    public static function emitPartialsFooterShellLayoutCssHtml(): string
    {
        $chunks = [];
        foreach (self::PARTIALS_FOOTER_SHELL_LAYOUT_CSS as $modulePath) {
            $html = self::emitModuleLayoutCssHtml($modulePath);
            if ($html !== '') {
                $chunks[] = $html;
            }
        }

        return $chunks === [] ? '' : \implode("\n", $chunks) . "\n";
    }

    /**
     * 悬浮层边缘 chrome 首帧 critical CSS（**单一出处**，未分层）。
     *
     * 默认主题页脚 / design 主题页脚 / StorefrontFloatLayerHost 的 heal 注入都必须调用本方法，
     * 禁止再各自内联手写 CSS 正文（历史上最多 8 个手写作者，导致 1.25rem 与 1.5rem 漂移）。
     * Marker: storefront-float-critical-single-source-v1
     *
     * @param string $styleAttrs 附加到 `<style>` 上的字面属性；仅供框架内部调用方传常量
     *                           （如 `data-required-injection-host="float-layer"`）。
     *                           调用方不得传入来自请求/模板变量的内容。
     */
    public static function emitFloatLayerCriticalCss(string $styleAttrs = ''): string
    {
        $attrs = \trim($styleAttrs);
        $attrs = $attrs === '' ? '' : ' ' . $attrs;

        return '<style data-storefront-float-layer-critical' . $attrs . '>' . "\n"
            . self::FLOAT_LAYER_CRITICAL_CSS . "\n"
            . '</style>';
    }

    public function __construct(
        private readonly ThemeScopedWorkspaceInterface $workspace,
    ) {
    }

    public function isChromeCarrierPageType(string $pageType): bool
    {
        return \trim($pageType) === ThemeLayout::PAGE_TYPE_HOME;
    }

    public function isChromeArea(string $area): bool
    {
        return \in_array(\strtolower(\trim($area)), self::CHROME_AREAS, true);
    }

    public function isChromeSlot(?string $slotId): bool
    {
        $slotId = \strtolower(\trim((string)$slotId));
        if ($slotId === '') {
            return false;
        }
        if (\in_array($slotId, self::CHROME_SLOTS, true)) {
            return true;
        }
        if (\in_array($slotId, self::HEADER_NESTED_CHROME_SLOTS, true)) {
            return true;
        }
        if (\in_array($slotId, self::FOOTER_NESTED_CHROME_SLOTS, true)) {
            return true;
        }
        if (\in_array($slotId, self::MINI_CART_NESTED_CHROME_SLOTS, true)) {
            return true;
        }
        if (\str_starts_with($slotId, 'top-bar-') || \str_starts_with($slotId, 'top_bar_')) {
            return true;
        }

        // Nested chrome slots stay under the same global chrome ownership model.
        foreach (['header', 'footer'] as $root) {
            if ($slotId === $root || \str_starts_with($slotId, $root . '-') || \str_starts_with($slotId, $root . '_')) {
                return true;
            }
        }

        return false;
    }

    public function isChromeTarget(?string $area, ?string $slotId): bool
    {
        return $this->isChromeArea((string)$area) || $this->isChromeSlot($slotId);
    }

    /**
     * @return array{
     *   page_type:string,
     *   is_carrier:bool,
     *   slots:array<string,array{mode:string,local_node_count:int}>,
     *   overall_mode:string
     * }
     */
    public function resolveModes(ThemeEditorContext $pageContext): array
    {
        $pageContext = $pageContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $pageType = $pageContext->layoutType;
        $isCarrier = $this->isChromeCarrierPageType($pageType);
        $localNodes = $this->chromeNodesFromState($this->workspace->load($pageContext, true));

        $slots = [];
        foreach (self::CHROME_AREAS as $area) {
            $count = 0;
            foreach ($localNodes as $node) {
                if ($this->nodeBelongsToChromeArea($node, $area)) {
                    ++$count;
                }
            }
            // Hard cut: non-carrier layouts always inherit global chrome (MODE_LOCAL removed).
            $slots[$area] = [
                'mode' => $isCarrier ? self::MODE_LOCAL : self::MODE_INHERIT,
                'local_node_count' => $count,
            ];
        }

        return [
            'page_type' => $pageType,
            'is_carrier' => $isCarrier,
            'slots' => $slots,
            'overall_mode' => $isCarrier ? self::MODE_LOCAL : self::MODE_INHERIT,
            'carrier_page_type' => ThemeLayout::PAGE_TYPE_HOME,
        ];
    }

    /**
     * 继承态 chrome 写操作应落到全局载体（homepage），并同步 ThemeScopeVersion chrome 权威。
     * 非载体布局永久 inherit，因此 chrome 写一律路由到 homepage。
     */
    public function resolveWriteLayoutType(
        ThemeEditorContext $pageContext,
        ?string $area,
        ?string $slotId,
    ): string {
        $pageType = $pageContext->layoutType;
        if ($this->isChromeCarrierPageType($pageType) || !$this->isChromeTarget($area, $slotId)) {
            return $pageType;
        }

        $modes = $this->resolveModes($pageContext);
        $chromeArea = $this->isChromeArea((string)$area)
            ? \strtolower(\trim((string)$area))
            : $this->inferChromeAreaFromSlot($slotId);
        if ($chromeArea === null) {
            return $pageType;
        }

        $slotMode = (string)($modes['slots'][$chromeArea]['mode'] ?? self::MODE_INHERIT);
        return $slotMode === self::MODE_INHERIT ? ThemeLayout::PAGE_TYPE_HOME : $pageType;
    }

    /**
     * Detach (MODE_LOCAL) permanently removed — shared chrome is always inherit for non-carriers.
     *
     * @param list<string>|null $areas
     * @return array{copied:int,areas:list<string>,workspace:array<string,mixed>|null}
     */
    public function detach(
        ThemeEditorContext $pageContext,
        ?array $areas,
        string $actorId,
        string $actorName = '',
    ): array {
        throw new \InvalidArgumentException('shared_chrome_detach_removed');
    }

    /**
     * 清空非载体布局上的本地 chrome，恢复默认全局继承。
     *
     * @param list<string>|null $pageTypes null = ThemeLayout::getPageTypes() 全部（跳过载体与 dashboard）
     * @param list<string>|null $areas
     * @return array{
     *   restored_layouts:int,
     *   removed_nodes:int,
     *   layouts:list<array{page_type:string,removed:int}>,
     *   mode:string
     * }
     */
    public function restoreNonCarrierLayouts(
        ThemeEditorContext $baseContext,
        ?array $pageTypes,
        ?array $areas,
        string $actorId,
        string $actorName = '',
    ): array {
        $baseContext = $baseContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        if ($pageTypes === null || $pageTypes === []) {
            $pageTypes = \array_keys(ThemeLayout::getPageTypes());
        }

        $layouts = [];
        $restoredLayouts = 0;
        $removedNodes = 0;
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === ''
                || $this->isChromeCarrierPageType($pageType)
                || $pageType === ThemeLayout::PAGE_TYPE_DASHBOARD
            ) {
                continue;
            }

            try {
                $result = $this->restore(
                    $baseContext->withLayoutType($pageType),
                    $areas,
                    $actorId,
                    $actorName,
                );
            } catch (\Throwable) {
                continue;
            }

            $removed = (int)($result['removed'] ?? 0);
            if ($removed > 0) {
                ++$restoredLayouts;
                $removedNodes += $removed;
            }
            $layouts[] = [
                'page_type' => $pageType,
                'removed' => $removed,
            ];
        }

        return [
            'restored_layouts' => $restoredLayouts,
            'removed_nodes' => $removedNodes,
            'layouts' => $layouts,
            'mode' => self::MODE_INHERIT,
        ];
    }

    /**
     * 发版本覆盖：清空非载体本地 chrome，并把清理后的草稿发布，避免 published 仍残留旧头尾。
     *
     * @param list<string>|null $pageTypes
     * @param list<string>|null $areas
     * @return array{
     *   restored_layouts:int,
     *   removed_nodes:int,
     *   published_layouts:int,
     *   layouts:list<array{page_type:string,removed:int,published:bool}>,
     *   mode:string
     * }
     */
    public function forceInheritAndPublishNonCarriers(
        ThemeEditorContext $baseContext,
        ?array $pageTypes,
        ?array $areas,
        string $actorId,
        string $actorName = '',
        string $reason = 'shared_chrome_force_inherit_publish',
    ): array {
        $baseContext = $baseContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $restore = $this->restoreNonCarrierLayouts($baseContext, $pageTypes, $areas, $actorId, $actorName);
        $publishedLayouts = 0;
        $layouts = [];

        foreach ($restore['layouts'] as $layout) {
            $pageType = (string)($layout['page_type'] ?? '');
            $removed = (int)($layout['removed'] ?? 0);
            $published = false;
            if ($pageType === '') {
                continue;
            }

            $pageContext = $baseContext->withLayoutType($pageType);
            $needsPublish = $removed > 0;
            if (!$needsPublish) {
                // 草稿已空但 published 仍含本地 chrome 时也必须覆盖发布。
                try {
                    $state = $this->workspace->load($pageContext, true);
                    $needsPublish = $this->chromeNodesFromPayload(
                        \is_array($state['published_payload'] ?? null) ? $state['published_payload'] : [],
                    ) !== [];
                } catch (\Throwable) {
                    $needsPublish = false;
                }
            }

            if ($needsPublish) {
                try {
                    $state = $this->workspace->load($pageContext, true);
                    $revision = (int)($state['revision'] ?? 0);
                    if ($revision > 0) {
                        $this->workspace->publish(
                            context: $pageContext,
                            expectedRevision: $revision,
                            expectedParentReleaseId: $this->nullableReleaseId(
                                $state['expected_parent_release_id'] ?? null,
                            ),
                            actorId: $actorId,
                            actorName: $actorName,
                            reason: $reason,
                        );
                        $published = true;
                        ++$publishedLayouts;
                    }
                } catch (\Throwable) {
                    $published = false;
                }
            }

            $layouts[] = [
                'page_type' => $pageType,
                'removed' => $removed,
                'published' => $published,
            ];
        }

        return [
            'restored_layouts' => (int)($restore['restored_layouts'] ?? 0),
            'removed_nodes' => (int)($restore['removed_nodes'] ?? 0),
            'published_layouts' => $publishedLayouts,
            'layouts' => $layouts,
            'mode' => self::MODE_INHERIT,
        ];
    }

    /**
     * 清空本布局 chrome 占用，恢复跟随全局。
     *
     * @param list<string>|null $areas
     * @return array{removed:int,areas:list<string>,workspace:array<string,mixed>|null}
     */
    public function restore(
        ThemeEditorContext $pageContext,
        ?array $areas,
        string $actorId,
        string $actorName = '',
    ): array {
        $pageContext = $pageContext->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        if ($this->isChromeCarrierPageType($pageContext->layoutType)) {
            throw new \InvalidArgumentException('shared_chrome_carrier_cannot_restore');
        }

        $areas = $this->normalizeAreas($areas);
        $pageState = $this->workspace->load($pageContext, true);
        $commands = [];
        $removed = 0;
        foreach ($this->chromeNodesFromState($pageState) as $uid => $node) {
            if (!$this->nodeBelongsToAreas($node, $areas)) {
                continue;
            }
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_REMOVE_NODE,
                'path' => '/nodes/' . $uid,
                'node_uid' => $uid,
            ]);
            ++$removed;
        }

        $workspace = null;
        if ($commands !== []) {
            $workspace = $this->workspace->applyChanges(
                context: $pageContext,
                expectedRevision: (int)($pageState['revision'] ?? 0),
                expectedParentReleaseId: $this->nullableReleaseId($pageState['expected_parent_release_id'] ?? null),
                changes: $commands,
                actorId: $actorId,
                actorName: $actorName,
                summary: 'shared_chrome_restored',
            );
        }

        return [
            'removed' => $removed,
            'areas' => $areas,
            'workspace' => $workspace,
            'mode' => self::MODE_INHERIT,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,array<string,mixed>>
     */
    private function chromeNodesFromState(array $state): array
    {
        return $this->chromeNodesFromPayload(
            \is_array($state['draft_payload'] ?? null) ? $state['draft_payload'] : [],
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array<string,mixed>>
     */
    private function chromeNodesFromPayload(array $payload): array
    {
        $nodes = \is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];
        $chrome = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            $uid = \strtolower(\trim((string)$uid));
            if ($uid === '' || \preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
                continue;
            }
            $area = \strtolower(\trim((string)($node['area'] ?? '')));
            $slotId = isset($node['slot_id']) ? (string)$node['slot_id'] : null;
            if (!$this->isChromeTarget($area, $slotId)) {
                continue;
            }
            // Skip layout-state markers.
            if ((string)($node['widget_code'] ?? '') === '__no_widget_placements__') {
                continue;
            }
            $chrome[$uid] = $node;
        }

        return $chrome;
    }

    /** @param array<string,mixed> $node */
    private function nodeBelongsToChromeArea(array $node, string $area): bool
    {
        $area = \strtolower(\trim($area));
        $nodeArea = \strtolower(\trim((string)($node['area'] ?? '')));
        if ($nodeArea === $area) {
            return true;
        }
        $slotId = \strtolower(\trim((string)($node['slot_id'] ?? '')));
        return $slotId === $area
            || \str_starts_with($slotId, $area . '-')
            || \str_starts_with($slotId, $area . '_');
    }

    /**
     * @param array<string,mixed> $node
     * @param list<string> $areas
     */
    private function nodeBelongsToAreas(array $node, array $areas): bool
    {
        foreach ($areas as $area) {
            if ($this->nodeBelongsToChromeArea($node, $area)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string>|null $areas
     * @return list<string>
     */
    private function normalizeAreas(?array $areas): array
    {
        if ($areas === null || $areas === []) {
            return self::CHROME_AREAS;
        }
        $normalized = [];
        foreach ($areas as $area) {
            $area = \strtolower(\trim((string)$area));
            if ($this->isChromeArea($area)) {
                $normalized[] = $area;
            }
        }
        $normalized = \array_values(\array_unique($normalized));

        return $normalized !== [] ? $normalized : self::CHROME_AREAS;
    }

    private function inferChromeAreaFromSlot(?string $slotId): ?string
    {
        $slotId = \strtolower(\trim((string)$slotId));
        foreach (self::CHROME_AREAS as $area) {
            if ($slotId === $area || \str_starts_with($slotId, $area . '-') || \str_starts_with($slotId, $area . '_')) {
                return $area;
            }
        }

        return null;
    }

    private function nullableReleaseId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }
}
