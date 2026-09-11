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
            'begin_checkout' => 'begin_checkout',
            'checkout_success' => 'purchase',
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
}
