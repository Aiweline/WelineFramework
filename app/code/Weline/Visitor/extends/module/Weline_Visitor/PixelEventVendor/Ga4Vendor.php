<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor;

use Weline\Visitor\Interface\PixelEventVendorInterface;

final class Ga4Vendor implements PixelEventVendorInterface
{
    public function getCode(): string
    {
        return 'ga4';
    }

    public function getDisplayName(): string
    {
        return 'Google Analytics 4';
    }

    public function getConfigSchema(): array
    {
        return [
            'measurement_id' => [
                'type' => 'text',
                'label' => 'Measurement ID',
                'required' => true,
                'default' => '',
            ],
        ];
    }

    public function getDefaultEventMap(): array
    {
        // 同名直发 + 仅形态/站内别名映射到 GA4 推荐名（见 doc/开发/spec/ga4-recommended-event-parity.md）
        return [
            'page_view' => 'page_view',
            'view_item' => 'view_item',
            'view_item_list' => 'view_item_list',
            'select_item' => 'select_item',
            'view_cart' => 'view_cart',
            'add_to_cart' => 'add_to_cart',
            'remove_from_cart' => 'remove_from_cart',
            'add_to_wishlist' => 'add_to_wishlist',
            'begin_checkout' => 'begin_checkout',
            'add_shipping_info' => 'add_shipping_info',
            'add_payment_info' => 'add_payment_info',
            'purchase' => 'purchase',
            'refund' => 'refund',
            'view_promotion' => 'view_promotion',
            'select_promotion' => 'select_promotion',
            'share' => 'share',
            'search' => 'search',
            'select_content' => 'select_content',
            'login' => 'login',
            'sign_up' => 'sign_up',
            'generate_lead' => 'generate_lead',
            'cta_click' => 'cta_click',
            'hero_cta_click' => 'cta_click',
            'friend_help_pay' => 'friend_help_pay',
            'selection_share' => 'share',
            'quick_buy' => 'begin_checkout',
            'quick_buy_checkout_ready' => 'add_payment_info',
            'express_pay' => 'add_payment_info',
            'express_pay_started' => 'begin_checkout',
            'express_pay_confirmed' => 'add_shipping_info',
            'checkout_success' => 'purchase',
            'friend_help_pay_checkout_success' => 'purchase',
            'selection_share_checkout_success' => 'purchase',
            'quick_buy_checkout_success' => 'purchase',
            'express_pay_checkout_success' => 'purchase',
            'payment_success' => 'purchase',
            'checkout_failure' => 'checkout_failure',
            'search_submit' => 'search',
            'search_suggestion_click' => 'select_content',
            'route_click' => 'route_click',
            'lead_submit' => 'generate_lead',
            'register' => 'sign_up',
        ];
    }

    public function getDefaultMode(): string
    {
        return 'sandbox';
    }

    public function getCapabilities(): array
    {
        return [
            'inject' => true,
            'sandbox' => true,
            'google_recommended' => true,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspDirectives(): array
    {
        // GA4 gtag.js Measurement ID path (www.googletagmanager.com/gtag/js).
        return [
            'script-src' => [
                'https://www.googletagmanager.com',
                'https://www.google-analytics.com',
                'https://www.google.com',
                'https://www.gstatic.com',
            ],
            'connect-src' => [
                'https://www.googletagmanager.com',
                'https://www.google-analytics.com',
                'https://region1.google-analytics.com',
                'https://www.google.com',
                'https://www.gstatic.com',
            ],
            'img-src' => [
                'https://www.googletagmanager.com',
                'https://www.google-analytics.com',
                'https://www.google.com',
                'https://www.gstatic.com',
            ],
        ];
    }
}
