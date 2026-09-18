<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Template;

/**
 * 商品卡片购买操作参数（Cart 加购 + Checkout 立即购买）。
 */
final class ProductCardAddToCartParams
{
    /**
     * @param array<string, mixed> $product
     * @param array{
     *     enabled?: bool,
     *     buy_now_enabled?: bool,
     *     variant?: string,
     *     button_class?: string,
     *     buy_now_button_class?: string,
     *     wrapper_class?: string,
     *     label?: string,
     *     buy_now_label?: string,
     *     css_owned_by_card?: bool
     * } $options
     * @return array<string, mixed>
     */
    public static function fetchDictionary(array $product, array $options = []): array
    {
        return self::buildDictionary(self::offerFromProduct($product), $options);
    }

    /**
     * @param array<string, mixed> $offer
     * @param array{
     *     enabled?: bool,
     *     buy_now_enabled?: bool,
     *     variant?: string,
     *     button_class?: string,
     *     buy_now_button_class?: string,
     *     wrapper_class?: string,
     *     label?: string,
     *     buy_now_label?: string,
     *     css_owned_by_card?: bool
     * } $options
     * @return array<string, mixed>
     */
    public static function fetchDictionaryFromOffer(array $offer, array $options = []): array
    {
        return self::buildDictionary(self::normalizeOffer($offer), $options);
    }

    public static function resolveEnabled(Template $template): bool
    {
        if (!$template->hasData('card_add_to_cart_enabled')) {
            return true;
        }

        return (bool)$template->getData('card_add_to_cart_enabled');
    }

    public static function resolveBuyNowEnabled(Template $template): bool
    {
        if (!$template->hasData('card_buy_now_enabled')) {
            return true;
        }

        return (bool)$template->getData('card_buy_now_enabled');
    }

    private const PURCHASE_ACTIONS_ASSETS_FLAG = 'theme.product_card_purchase_actions_assets_emitted';
    private const PURCHASE_ACTIONS_DISCARD_HOOK = 'theme.product_card_purchase_actions_discard';
    private const PURCHASE_ACTIONS_STYLE_MARKER = 'data-weline-product-card-purchase-actions';

    /**
     * Emit purchase-actions CSS once per request when the product-card purchase partial renders.
     * Styles travel with the component — do not inject from layout/preview page heads.
     *
     * Flag is set only after $emitAssets runs; Fiber discardCapture resets it for re-render.
     *
     * @param callable():void $emitAssets
     */
    public static function emitPurchaseActionsAssetsOnce(callable $emitAssets): void
    {
        if (RequestContext::has(self::PURCHASE_ACTIONS_ASSETS_FLAG)) {
            return;
        }

        $emitAssets();
        RequestContext::set(self::PURCHASE_ACTIONS_ASSETS_FLAG, true);
        RequestContext::onCaptureDiscard(
            static function (): void {
                self::resetPurchaseActionsAssetsEmission();
            },
            self::PURCHASE_ACTIONS_DISCARD_HOOK
        );
    }

    /**
     * Allow a subsequent component render (e.g. DEV/preview widget repair / Fiber discard) to emit again.
     * The first emission may live only in HTML that is about to be replaced.
     */
    public static function resetPurchaseActionsAssetsEmission(): void
    {
        RequestContext::remove(self::PURCHASE_ACTIONS_ASSETS_FLAG);
        RequestContext::removeCaptureDiscard(self::PURCHASE_ACTIONS_DISCARD_HOOK);
    }

    /**
     * Inline CSS owned by the product-card purchase partial (Cart add + Checkout buy-now).
     */
    public static function buildPurchaseActionsStyleTag(): string
    {
        $cssPath = dirname(__DIR__) . '/view/statics/css/partials/product-card-purchase-actions.css';
        $css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';
        if ($css === '') {
            return '';
        }

        return '<style ' . self::PURCHASE_ACTIONS_STYLE_MARKER . '="1" data-no-extract="true">'
            . $css
            . '</style>';
    }

    /**
     * @param array<string, mixed> $offer
     * @param array{
     *     enabled?: bool,
     *     buy_now_enabled?: bool,
     *     variant?: string,
     *     button_class?: string,
     *     buy_now_button_class?: string,
     *     wrapper_class?: string,
     *     label?: string,
     *     buy_now_label?: string,
     *     css_owned_by_card?: bool
     * } $options
     * @return array<string, mixed>
     */
    private static function buildDictionary(array $offer, array $options): array
    {
        return [
            'storefront_offer' => $offer,
            'card_add_to_cart_enabled' => (bool)($options['enabled'] ?? true),
            'card_buy_now_enabled' => (bool)($options['buy_now_enabled'] ?? true),
            'card_add_to_cart_variant' => trim((string)($options['variant'] ?? 'cta')),
            'card_add_to_cart_button_class' => trim((string)($options['button_class'] ?? '')),
            'card_buy_now_button_class' => trim((string)($options['buy_now_button_class'] ?? '')),
            'card_add_to_cart_wrapper_class' => trim((string)($options['wrapper_class'] ?? '')),
            'card_add_to_cart_label' => trim((string)($options['label'] ?? '')),
            'card_buy_now_label' => trim((string)($options['buy_now_label'] ?? '')),
            // Canonical <w:product:card> owns CTA chrome via product-card.css — skip second style tag.
            'card_css_owned_by_product_card' => (bool)($options['css_owned_by_card'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function offerFromProduct(array $product): array
    {
        $productId = max(0, (int)($product['product_id'] ?? $product['id'] ?? 0));
        $stock = (int)($product['stock'] ?? 0);
        $inStock = array_key_exists('in_stock', $product)
            ? (bool)$product['in_stock']
            : ($stock > 0 || !array_key_exists('stock', $product));

        return [
            'product_id' => $productId,
            'global_offer_uuid' => trim((string)($product['global_offer_uuid'] ?? '')),
            'slug' => strtolower(trim((string)($product['slug'] ?? ''))),
            'sellable' => array_key_exists('sellable', $product)
                ? !empty($product['sellable'])
                : $inStock,
            'quote_only' => !empty($product['quote_only']),
            'provider_code' => trim((string)($product['provider_code'] ?? 'product')) ?: 'product',
            'message' => trim((string)($product['message'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    public static function normalizeOffer(array $offer): array
    {
        $productId = max(0, (int)($offer['product_id'] ?? $offer['id'] ?? 0));

        return [
            'product_id' => $productId,
            'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
            'slug' => strtolower(trim((string)($offer['slug'] ?? ''))),
            'sellable' => !empty($offer['sellable']),
            'quote_only' => !empty($offer['quote_only']),
            'provider_code' => trim((string)($offer['provider_code'] ?? 'product')) ?: 'product',
            'message' => trim((string)($offer['message'] ?? '')),
        ];
    }
}
