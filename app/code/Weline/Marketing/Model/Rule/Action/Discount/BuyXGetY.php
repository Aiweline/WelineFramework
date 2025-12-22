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
 * 买X送Y动作
 * 
 * @package Weline_Marketing
 */
class BuyXGetY extends AbstractAction
{
    public function getCode(): string
    {
        return 'buy_x_get_y';
    }

    public function getName(): string
    {
        return __('买X送Y');
    }

    public function getDescription(): string
    {
        return __('购买X件商品，赠送Y件商品（或折扣）');
    }

    public function execute(array $action, array $context): array
    {
        $buyX = (int)($action['buy_x'] ?? 1);
        $getY = (int)($action['get_y'] ?? 1);
        $discountType = $action['discount_type'] ?? 'free'; // free, percentage, fixed_amount
        $discountValue = isset($action['discount_value']) ? (float)$action['discount_value'] : null;

        $items = $context['items'] ?? $context['products'] ?? [];
        $itemCount = count($items);

        if ($itemCount < $buyX) {
            return [
                'discount_amount' => 0,
                'messages' => [],
            ];
        }

        // 计算可以享受多少次买X送Y
        $times = floor($itemCount / $buyX);
        $freeItems = $times * $getY;

        $discountAmount = 0;
        $messages = [];

        if ($discountType === 'free') {
            // 免费赠送，需要计算免费商品的总价值
            $avgPrice = 0;
            $totalPrice = 0;
            foreach ($items as $item) {
                $totalPrice += (float)($item['price'] ?? 0) * (float)($item['qty'] ?? 1);
            }
            if ($itemCount > 0) {
                $avgPrice = $totalPrice / $itemCount;
            }
            $discountAmount = $avgPrice * $freeItems;
            $messages[] = sprintf(__('买 %d 送 %d，共赠送 %d 件商品，优惠 %.2f 元'), $buyX, $getY, $freeItems, $discountAmount);
        } elseif ($discountType === 'percentage' && $discountValue !== null) {
            $order = $context['order'] ?? [];
            $subtotal = (float)($order['subtotal'] ?? $order['total'] ?? $context['subtotal'] ?? 0);
            $discountAmount = $this->calculateDiscount($subtotal, 'percentage', $discountValue);
            $messages[] = sprintf(__('买 %d 送 %d，享受 %.2f%% 折扣，优惠 %.2f 元'), $buyX, $getY, $discountValue, $discountAmount);
        } elseif ($discountType === 'fixed_amount' && $discountValue !== null) {
            $discountAmount = $discountValue * $times;
            $messages[] = sprintf(__('买 %d 送 %d，每次优惠 %.2f 元，共优惠 %.2f 元'), $buyX, $getY, $discountValue, $discountAmount);
        }

        return [
            'discount_amount' => $discountAmount,
            'messages' => $messages,
        ];
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'buy_x',
                'label' => __('购买数量（X）'),
                'type' => 'number',
                'min' => 1,
                'required' => true,
            ],
            [
                'name' => 'get_y',
                'label' => __('赠送数量（Y）'),
                'type' => 'number',
                'min' => 1,
                'required' => true,
            ],
            [
                'name' => 'discount_type',
                'label' => __('赠送类型'),
                'type' => 'select',
                'options' => [
                    'free' => __('免费赠送'),
                    'percentage' => __('百分比折扣'),
                    'fixed_amount' => __('固定金额折扣'),
                ],
                'required' => true,
            ],
            [
                'name' => 'discount_value',
                'label' => __('折扣值（百分比或金额）'),
                'type' => 'number',
                'step' => '0.01',
                'required' => false,
            ],
        ];
    }
}

