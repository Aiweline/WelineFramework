<?php

declare(strict_types=1);

namespace Weline\Framework\View\Helper;

/**
 * Single admission gate for process-level HTML caches (FPC, hook output,
 * reusable template fragments). Reject — do not rewrite — poisoned bodies.
 */
final class HtmlCacheAdmission
{
    public static function admit(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        if (!EmbeddedPageTitle::htmlAdmissible($html)) {
            return false;
        }

        return self::storefrontProductCardCssOk($html);
    }

    /**
     * Heal missing product-card CSS before FPC/hook admission.
     *
     * Slot/DOM repair and asset extract can drop the inline <style> while leaving
     * cards, which permanently blocks Full Page Cache publish. Re-inject once.
     */
    public static function healStorefrontProductCardCss(string $html): string
    {
        if ($html === '' || self::storefrontProductCardCssOk($html)) {
            return $html;
        }

        $tag = self::buildProductCardStyleTag();
        if ($tag === '') {
            return $html;
        }

        if (\preg_match('/<body\b[^>]*>/i', $html, $match, \PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int)$match[0][1] + \strlen($match[0][0]);

            return \substr($html, 0, $offset) . $tag . \substr($html, $offset);
        }

        $cardPos = \stripos($html, 'data-testid="weline-product-card"');
        if ($cardPos === false) {
            $cardPos = \stripos($html, "data-testid='weline-product-card'");
        }
        if ($cardPos !== false) {
            return \substr($html, 0, $cardPos) . $tag . \substr($html, $cardPos);
        }

        return $tag . $html;
    }

    /**
     * Storefront pages that render canonical product cards must carry the inline
     * product-card CSS marker.
     */
    public static function storefrontProductCardCssOk(string $body): bool
    {
        $hasCard = \str_contains($body, 'data-testid="weline-product-card"')
            || \str_contains($body, "data-testid='weline-product-card'");
        if (!$hasCard) {
            return true;
        }

        return \str_contains($body, 'data-weline-product-card-css');
    }

    private static function buildProductCardStyleTag(): string
    {
        if (\class_exists(\Weline\Product\Service\ProductCardRenderer::class, false)
            || \class_exists(\Weline\Product\Service\ProductCardRenderer::class)
        ) {
            try {
                $tag = \Weline\Product\Service\ProductCardRenderer::buildProductCardStyleTag();
                if (\is_string($tag) && $tag !== '') {
                    return $tag;
                }
            } catch (\Throwable) {
            }
        }

        $cssPath = \dirname(__DIR__, 3)
            . '/Product/view/statics/css/frontend/product-card.css';
        $css = \is_file($cssPath) ? (string)\file_get_contents($cssPath) : '';
        if ($css === '') {
            return '';
        }

        return '<style data-weline-product-card-css="1" data-no-extract="true">'
            . $css
            . '</style>' . "\n";
    }
}
