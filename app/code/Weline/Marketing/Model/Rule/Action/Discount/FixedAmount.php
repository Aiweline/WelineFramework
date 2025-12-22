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
 * 固定金额折扣动作
 * 
 * @package Weline_Marketing
 */
class FixedAmount extends AbstractAction
{
    public function getCode(): string
    {
        return 'discount_fixed_amount';
    }

    public function getName(): string
    {
        return __('固定金额折扣');
    }

    public function getDescription(): string
    {
        return __('按固定金额折扣');
    }

    public function execute(array $action, array $context): array
    {
        $discountValue = (float)($action['discount_value'] ?? 0);
        $applyTo = $action['apply_to'] ?? 'subtotal';

        $amount = 0;
        if ($applyTo === 'subtotal') {
            $order = $context['order'] ?? [];
            $amount = (float)($order['subtotal'] ?? $order['total'] ?? $context['subtotal'] ?? 0);
        } elseif ($applyTo === 'shipping') {
            $order = $context['order'] ?? [];
            $amount = (float)($order['shipping_amount'] ?? $context['shipping_amount'] ?? 0);
        }

        $discountAmount = $this->calculateDiscount($amount, 'fixed_amount', $discountValue);

        return [
            'discount_amount' => $discountAmount,
            'messages' => [sprintf(__('优惠 %.2f 元'), $discountAmount)],
        ];
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'discount_value',
                'label' => __('折扣金额'),
                'type' => 'number',
                'step' => '0.01',
                'min' => 0,
                'required' => true,
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

