<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Footer chrome 嵌套部件源内容指纹（浮层 + 尾 chrome）。
 *
 * 键消费方：Partials frontend footer → storefront_chrome。
 * 算法与 {@see NestedChromeSourceFingerprint} 相同：内容 sha256；mtime|size 仅 memo 门控。
 */
final class FooterNestedChromeSourceFingerprint
{
    /**
     * Absolute paths (Theme + float/tail widget sources).
     *
     * @return list<string>
     */
    public static function sourceAbsolutePaths(): array
    {
        $themeRoot = \dirname(__DIR__);
        $codeRoot = \dirname($themeRoot, 2);

        return [
            $themeRoot . '/view/theme/frontend/partials/footer/default.phtml',
            $themeRoot . '/view/theme/frontend/widgets/footer/theme-storefront-tail/default.phtml',
            $codeRoot . '/StoreMusic/view/templates/frontend/widgets/store-music.phtml',
            $codeRoot . '/CustomerService/view/templates/frontend/widgets/customer-service-float.phtml',
            $codeRoot . '/Compare/view/templates/frontend/widgets/compare-shopper-chrome.phtml',
            $codeRoot . '/Consent/view/templates/frontend/widgets/consent-banner.phtml',
            $codeRoot . '/B2B/view/templates/frontend/widgets/b2b-mini-cart-boot.phtml',
            $codeRoot . '/Shipping/view/templates/frontend/widgets/shipping-storefront-tail.phtml',
        ];
    }

    public static function compute(): string
    {
        $parts = [];
        foreach (self::sourceAbsolutePaths() as $path) {
            $parts[] = NestedChromeSourceFingerprint::hashFile($path);
        }

        return \implode(';', $parts);
    }
}
