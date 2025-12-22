<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Action\Discount;

use Weline\Marketing\Model\Rule\Action\AbstractAction;

/**
 * 百分比折扣动作
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
        $discountValue = (float)($action['discount_value'] ?? 0);
        $maxDiscount = isset($action['max_discount']) ? (float)$action['max_discount'] : null;
        $applyTo = $action['apply_to'] ?? 'subtotal'; // subtotal, shipping

        $amount = 0;
        if ($applyTo === 'subtotal') {
            $order = $context['order'] ?? [];
            $amount = (float)($order['subtotal'] ?? $order['total'] ?? $context['subtotal'] ?? 0);
        } elseif ($applyTo === 'shipping') {
            $order = $context['order'] ?? [];
            $amount = (float)($order['shipping_amount'] ?? $context['shipping_amount'] ?? 0);
        }

        $discountAmount = $this->calculateDiscount($amount, 'percentage', $discountValue, $maxDiscount);

        return [
            'discount_amount' => $discountAmount,
            'messages' => [sprintf(__('享受 %.2f%% 折扣，优惠 %.2f 元'), $discountValue, $discountAmount)],
        ];
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
                'label' => __('最大折扣金额'),
                'type' => 'number',
                'step' => '0.01',
                'required' => false,
            ],
            [
                'name' => 'apply_to',
                'label' => __('应用于'),
                'type' => 'select',
                'options' => [
                    'subtotal' => __('订单小计'),
                    'shipping' => __('运费'),
                ],
                'required' => true,
            ],
        ];
    }
}

