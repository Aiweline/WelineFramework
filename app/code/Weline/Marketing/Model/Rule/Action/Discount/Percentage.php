<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Action\Discount;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\Action\AbstractAction;
use Weline\Marketing\Service\MarketingBaseCurrencyAmount;

/**
 * 百分比折扣动作
 *
 * 百分比无货币单位；max_discount（最大折扣金额）按站点基准货币录入并换算。
 *
 * @package Weline_Marketing
 */
class Percentage extends AbstractAction
{
    public function getCode(): string
    {
        return 'discount_percentage';
    }

    public function getName(): string
    {
        return __('百分比折扣');
    }

    public function getDescription(): string
    {
        return __('按百分比折扣，支持设置最大折扣金额');
    }

    public function execute(array $action, array $context): array
    {
        // Storefront cart already bakes Promotion theme deals into unit_price_minor.
        // Skip the synced automatic rule to avoid double discounting at quote time.
        if (!empty($action['external_managed'])
            && (string)($action['source_type'] ?? '') === 'promotion_activity_theme'
        ) {
            return [
                'discount_amount' => 0,
                'messages' => [],
            ];
        }

        $discountValue = (float)($action['discount_value'] ?? 0);
        $fx = $this->baseCurrencyAmount();
        $checkoutCurrency = $fx->checkoutCurrencyFromContext($context);
        $maxDiscount = null;
        if (isset($action['max_discount']) && $action['max_discount'] !== '' && $action['max_discount'] !== null) {
            $maxDiscount = $fx->convertBaseMajorToCheckout((float)$action['max_discount'], $checkoutCurrency);
            if ($maxDiscount === null) {
                return [
                    'discount_amount' => 0,
                    'messages' => [__('百分比封顶额换算失败：缺少基准货币到结账货币的汇率')],
                ];
            }
        }
        $applyTo = \strtolower(\trim((string)($action['apply_to'] ?? 'subtotal')));
        if ($applyTo === 'cart' || $applyTo === '') {
            $applyTo = 'subtotal';
        }

        $amount = 0;
        if ($applyTo === 'subtotal') {
            $order = $context['order'] ?? [];
            $amount = (float)($order['subtotal'] ?? $order['total'] ?? $context['subtotal'] ?? 0);
        } elseif ($applyTo === 'shipping') {
            $order = $context['order'] ?? [];
            $amount = (float)($order['shipping_amount'] ?? $context['shipping_amount'] ?? 0);
        } elseif ($applyTo === 'matched_products') {
            $amount = $this->matchedProductsAmount($action, $context);
        }

        $discountAmount = $this->calculateDiscount($amount, 'percentage', $discountValue, $maxDiscount);

        return [
            'discount_amount' => $discountAmount,
            'messages' => [sprintf(
                __('享受 %.2f%% 折扣，优惠 %s %.2f'),
                $discountValue,
                $checkoutCurrency,
                $discountAmount
            )],
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $context
     */
    private function matchedProductsAmount(array $action, array $context): float
    {
        $skuList = $action['sku_list'] ?? [];
        if (!is_array($skuList)) {
            $skuList = array_filter(array_map('trim', explode(',', (string)$skuList)));
        }
        $allowed = [];
        foreach ($skuList as $sku) {
            $sku = trim((string)$sku);
            if ($sku !== '') {
                $allowed[$sku] = true;
            }
        }
        if ($allowed === []) {
            return 0.0;
        }

        $amount = 0.0;
        $products = $context['products'] ?? $context['items'] ?? [];
        if (!is_array($products)) {
            return 0.0;
        }
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $sku = trim((string)($product['sku'] ?? ''));
            if ($sku === '' || !isset($allowed[$sku])) {
                continue;
            }
            $amount += (float)($product['price'] ?? 0) * (float)($product['qty'] ?? 1);
        }

        return $amount;
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'discount_value',
                'label' => __('折扣百分比'),
                'type' => 'number',
                'step' => '0.01',
                'min' => 0,
                'max' => 100,
                'required' => true,
            ],
            [
                'name' => 'max_discount',
                'label' => __('最大折扣金额（%{1}）', $this->baseCurrencyAmount()->baseCurrency()),
                'type' => 'number',
                'step' => '0.01',
                'required' => false,
                'hint' => __('封顶金额按站点基准货币录入；结账时按汇率换算。'),
            ],
            [
                'name' => 'apply_to',
                'label' => __('应用于'),
                'type' => 'select',
                'options' => [
                    'subtotal' => __('订单小计'),
                    'shipping' => __('运费'),
                    'matched_products' => __('匹配商品行'),
                ],
                'required' => true,
            ],
        ];
    }

    private function baseCurrencyAmount(): MarketingBaseCurrencyAmount
    {
        return ObjectManager::getInstance(MarketingBaseCurrencyAmount::class);
    }
}

