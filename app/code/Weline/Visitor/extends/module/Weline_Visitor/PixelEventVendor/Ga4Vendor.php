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
        return [
            'cta_click' => 'cta_click',
            'hero_cta_click' => 'cta_click',
            'view_item' => 'view_item',
            'add_to_cart' => 'add_to_cart',
            'friend_help_pay' => 'friend_help_pay',
            'selection_share' => 'share',
            'quick_buy' => 'quick_buy',
            'express_pay' => 'express_pay',
            'begin_checkout' => 'begin_checkout',
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
            'login' => 'login',
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
