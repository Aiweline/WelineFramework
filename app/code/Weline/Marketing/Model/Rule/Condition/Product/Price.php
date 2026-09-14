<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Marketing\Model\Rule\Condition\Product;

use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Model\Rule\Condition\AbstractCondition;
use Weline\Marketing\Service\MarketingBaseCurrencyAmount;

/**
 * 产品价格条件
 * 
 * @package Weline_Marketing
 */
class Price extends AbstractCondition
{
    public function getCode(): string
    {
        return 'product_price';
    }

    public function getName(): string
    {
        return __('产品价格');
    }

    public function getDescription(): string
    {
        return __('根据产品价格进行条件判断');
    }

    public function validate(array $condition, array $context): bool
    {
        $products = $context['products'] ?? [];
        if (empty($products)) {
            $products = $context['items'] ?? [];
        }

        if (empty($products)) {
            return false;
        }

        $operator = $condition['operator'] ?? '>=';
        $value = $condition['value'] ?? null;

        if ($value === null) {
            return false;
        }

        $fx = ObjectManager::getInstance(MarketingBaseCurrencyAmount::class);
        $checkoutCurrency = $fx->checkoutCurrencyFromContext($context);
        $threshold = $fx->convertBaseMajorToCheckout((float)$value, $checkoutCurrency);
        if ($threshold === null) {
            return false;
        }

        $matchType = $condition['match_type'] ?? 'any'; // any, all

        foreach ($products as $product) {
            $price = (float)($product['price'] ?? 0);
            $result = $this->compare($price, $operator, $threshold);

            if ($matchType === 'any' && $result) {
                return true;
            }
            if ($matchType === 'all' && !$result) {
                return false;
            }
        }

        return $matchType === 'all';
    }

    public function getFormFields(): array
    {
        $base = ObjectManager::getInstance(MarketingBaseCurrencyAmount::class)->baseCurrency();

        return [
            [
                'name' => 'operator',
                'label' => __('操作符'),
                'type' => 'select',
                'options' => [
                    '>=' => __('价格 >='),
                    '<=' => __('价格 <='),
                    '>' => __('价格 >'),
                    '<' => __('价格 <'),
                ],
                'required' => true,
            ],
            [
                'name' => 'value',
                'label' => __('价格（%{1}）', $base),
                'type' => 'number',
                'step' => '0.01',
                'required' => true,
                'hint' => __('价格门槛按站点基准货币录入；结账时按汇率换算后与商品行价格比较。'),
            ],
            [
                'name' => 'match_type',
                'label' => __('匹配类型'),
                'type' => 'select',
                'options' => [
                    'any' => __('任一产品'),
                    'all' => __('所有产品'),
                ],
                'required' => true,
            ],
        ];
    }
}

