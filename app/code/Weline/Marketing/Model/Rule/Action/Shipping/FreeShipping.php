<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Action\Shipping;

use Weline\Marketing\Model\Rule\Action\AbstractAction;

/**
 * 免运费动作
 * 
 * @package Weline_Marketing
 */
class FreeShipping extends AbstractAction
{
    public function getCode(): string
    {
        return 'free_shipping';
    }

    public function getName(): string
    {
        return __('免运费');
    }

    public function getDescription(): string
    {
        return __('免除订单运费');
    }

    public function execute(array $action, array $context): array
    {
        $order = $context['order'] ?? [];
        $shippingAmount = (float)($order['shipping_amount'] ?? $context['shipping_amount'] ?? 0);

        return [
            'free_shipping' => true,
            'shipping_discount' => $shippingAmount,
            'messages' => [__('免运费')],
        ];
    }

    public function getFormFields(): array
    {
        return [];
    }
}

