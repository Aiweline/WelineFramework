<?php

declare(strict_types=1);

namespace Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor;

use Weline\Visitor\Interface\PixelEventVendorInterface;

/**
 * 系统像素（当前站内事件页）——用户可改搭接/脚本/范围，不依赖第三方凭证。
 */
final class SystemVendor implements PixelEventVendorInterface
{
    public function getCode(): string
    {
        return 'weline';
    }

    public function getDisplayName(): string
    {
        return '系统像素';
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function getDefaultEventMap(): array
    {
        return [
            'cta_click' => 'cta_click',
            'hero_cta_click' => 'hero_cta_click',
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
            'sign_up' => 'sign_up',
            'generate_lead' => 'generate_lead',
            'friend_help_pay' => 'friend_help_pay',
            'selection_share' => 'selection_share',
            'quick_buy' => 'quick_buy',
            'express_pay' => 'express_pay',
            'express_pay_started' => 'express_pay_started',
            'express_pay_confirmed' => 'express_pay_confirmed',
            'express_pay_transaction' => 'express_pay_transaction',
            'express_pay_checkout_success' => 'express_pay_checkout_success',
            'checkout_success' => 'checkout_success',
            'friend_help_pay_checkout_success' => 'friend_help_pay_checkout_success',
            'selection_share_checkout_success' => 'selection_share_checkout_success',
            'quick_buy_checkout_success' => 'quick_buy_checkout_success',
            'payment_success' => 'payment_success',
            'checkout_failure' => 'checkout_failure',
            'search_submit' => 'search_submit',
            'search_suggestion_click' => 'search_suggestion_click',
            'route_click' => 'route_click',
            'lead_submit' => 'lead_submit',
            'login' => 'login',
            'register' => 'register',
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
            'system_native' => true,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspDirectives(): array
    {
        // First-party system pixel — no third-party SDK hosts.
        return [];
    }
}
