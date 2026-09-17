<?php

declare(strict_types=1);

namespace Weline\Marketing\Extends;

use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailTemplateDefaultLocales;

class MailChannelProvider implements MailChannelProviderInterface
{
    public function getChannels(): array
    {
        $itemsHtmlSample = (new \Weline\Marketing\Service\WinbackMailItemsHtmlBuilder())->render([
            [
                'name' => '示例汉服·明制交领',
                'sku' => 'HF-DEMO-001',
                'qty' => 1,
                'unit_price' => '299.00',
                'row_total' => '299.00',
                'options_text' => '尺码 M · 靛蓝',
            ],
        ], 'CNY', '');

        $unpaidVariables = [
            ['code' => 'order_uuid', 'label' => __('订单 UUID'), 'sample' => 'ord-uuid-example'],
            ['code' => 'order_number', 'label' => __('订单号'), 'sample' => 'ORD202601010001'],
            ['code' => 'customer_name', 'label' => __('客户姓名'), 'sample' => '张三'],
            ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'grand_total', 'label' => __('订单金额'), 'sample' => '99.00'],
            ['code' => 'currency', 'label' => __('货币'), 'sample' => 'CNY'],
            ['code' => 'continue_pay_url', 'label' => __('继续支付链接'), 'sample' => 'https://example.com/checkout/success?order_uuid=x&checkout_token=y'],
            ['code' => 'created_at', 'label' => __('下单时间'), 'sample' => '2026-09-14 10:00:00'],
            ['code' => 'payment_status', 'label' => __('支付状态'), 'sample' => 'pending'],
            ['code' => 'items_html', 'label' => __('商品明细 HTML（|raw）'), 'sample' => $itemsHtmlSample],
            ['code' => 'coupon_code', 'label' => __('激励券码'), 'sample' => 'MWABC123'],
        ];

        $checkoutVariables = [
            ['code' => 'quote_token', 'label' => __('报价令牌'), 'sample' => 'qt-example'],
            ['code' => 'customer_name', 'label' => __('客户姓名'), 'sample' => '张三'],
            ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'email', 'label' => __('收件邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'grand_total', 'label' => __('结账金额'), 'sample' => '99.00'],
            ['code' => 'grand_total_minor', 'label' => __('结账金额（分）'), 'sample' => '9900'],
            ['code' => 'currency', 'label' => __('货币'), 'sample' => 'CNY'],
            ['code' => 'continue_checkout_url', 'label' => __('继续结账链接'), 'sample' => 'https://example.com/checkout?quote_token=x'],
            ['code' => 'created_at', 'label' => __('结账开始时间'), 'sample' => '2026-09-14 10:00:00'],
            ['code' => 'checkout_entry', 'label' => __('结账入口'), 'sample' => 'cart'],
            ['code' => 'locale', 'label' => __('语言'), 'sample' => 'zh_Hans_CN'],
            ['code' => 'website_id', 'label' => __('网站 ID'), 'sample' => '1'],
            ['code' => 'items_html', 'label' => __('商品明细 HTML（|raw）'), 'sample' => $itemsHtmlSample],
            ['code' => 'coupon_code', 'label' => __('激励券码'), 'sample' => 'MWABC123'],
        ];

        $cartVariables = [
            ['code' => 'cart_key', 'label' => __('购物车键'), 'sample' => 'customer:42'],
            ['code' => 'customer_name', 'label' => __('客户姓名'), 'sample' => '张三'],
            ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'email', 'label' => __('收件邮箱'), 'sample' => 'user@example.com'],
            ['code' => 'grand_total', 'label' => __('购物车金额'), 'sample' => '99.00'],
            ['code' => 'grand_total_minor', 'label' => __('购物车金额（分）'), 'sample' => '9900'],
            ['code' => 'currency', 'label' => __('货币'), 'sample' => 'CNY'],
            ['code' => 'continue_cart_url', 'label' => __('继续购物链接'), 'sample' => 'https://example.com/cart'],
            ['code' => 'created_at', 'label' => __('加购/创建时间'), 'sample' => '2026-09-14 10:00:00'],
            ['code' => 'updated_at', 'label' => __('购物车更新时间'), 'sample' => '2026-09-14 11:00:00'],
            ['code' => 'locale', 'label' => __('语言'), 'sample' => 'zh_Hans_CN'],
            ['code' => 'website_id', 'label' => __('网站 ID'), 'sample' => '1'],
            ['code' => 'items_html', 'label' => __('商品明细 HTML（|raw）'), 'sample' => $itemsHtmlSample],
            ['code' => 'coupon_code', 'label' => __('激励券码'), 'sample' => 'MWABC123'],
        ];

        return [
            [
                'code' => 'Weline_Marketing::unpaid_order_reminder',
                'name' => __('未付订单催付'),
                'description' => __('营销挽回：未支付订单定时催付邮件'),
                'module' => 'Weline_Marketing',
                'variables' => $unpaidVariables,
                'default_templates' => MailTemplateDefaultLocales::fileEntries('unpaid_order_reminder'),
            ],
            [
                'code' => 'Weline_Marketing::checkout_abandon_reminder',
                'name' => __('结账遗弃提醒'),
                'description' => __('营销挽回：遗弃结账报价定时提醒邮件'),
                'module' => 'Weline_Marketing',
                'variables' => $checkoutVariables,
                'default_templates' => MailTemplateDefaultLocales::fileEntries('checkout_abandon_reminder'),
            ],
            [
                'code' => 'Weline_Marketing::cart_abandon_reminder',
                'name' => __('购物车遗弃提醒'),
                'description' => __('营销挽回：遗弃购物车定时提醒邮件'),
                'module' => 'Weline_Marketing',
                'variables' => $cartVariables,
                'default_templates' => MailTemplateDefaultLocales::fileEntries('cart_abandon_reminder'),
            ],
            [
                'code' => 'Weline_Marketing::welcome_customer',
                'name' => __('新客欢迎'),
                'description' => __('生命周期：注册后欢迎邮件'),
                'module' => 'Weline_Marketing',
                'variables' => [
                    ['code' => 'customer_name', 'label' => __('客户姓名'), 'sample' => '张三'],
                    ['code' => 'customer_email', 'label' => __('客户邮箱'), 'sample' => 'user@example.com'],
                    ['code' => 'email', 'label' => __('收件邮箱'), 'sample' => 'user@example.com'],
                    ['code' => 'coupon_code', 'label' => __('见面礼券码'), 'sample' => 'WELCOME1'],
                ],
                'default_templates' => MailTemplateDefaultLocales::fileEntries('welcome_customer'),
            ],
        ];
    }
}
