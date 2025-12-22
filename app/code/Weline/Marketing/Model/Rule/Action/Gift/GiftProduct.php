<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Action\Gift;

use Weline\Marketing\Model\Rule\Action\AbstractAction;

/**
 * 赠品产品动作
 * 
 * @package Weline_Marketing
 */
class GiftProduct extends AbstractAction
{
    public function getCode(): string
    {
        return 'gift_product';
    }

    public function getName(): string
    {
        return __('赠品产品');
    }

    public function getDescription(): string
    {
        return __('赠送指定产品');
    }

    public function execute(array $action, array $context): array
    {
        $productId = $action['product_id'] ?? null;
        $qty = (int)($action['qty'] ?? 1);

        if (!$productId) {
            return [
                'gifts' => [],
                'messages' => [],
            ];
        }

        return [
            'gifts' => [
                [
                    'product_id' => $productId,
                    'qty' => $qty,
                    'type' => 'product',
                ],
            ],
            'messages' => [sprintf(__('赠送产品 ID: %d，数量: %d'), $productId, $qty)],
        ];
    }

    public function getFormFields(): array
    {
        return [
            [
                'name' => 'product_id',
                'label' => __('产品ID'),
                'type' => 'number',
                'required' => true,
            ],
            [
                'name' => 'qty',
                'label' => __('数量'),
                'type' => 'number',
                'min' => 1,
                'required' => true,
            ],
        ];
    }
}

