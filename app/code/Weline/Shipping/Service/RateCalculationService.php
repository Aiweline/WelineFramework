<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\RateTemplate;

/**
 * 费用计算服务
 * 
 * @package Weline_Shipping
 */
class RateCalculationService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取费用模板模型实例
     * 
     * @return RateTemplate
     */
    private function getModel(): RateTemplate
    {
        return $this->objectManager->getInstance(RateTemplate::class);
    }

    /**
     * 根据模板计算配送费用
     * 
     * @param int $templateId 模板ID
     * @param float $weight 重量（kg）
     * @param float $volume 体积（m³）
     * @param int $quantity 件数
     * @return float 配送费用
     * @throws \RuntimeException
     */
    public function calculate(int $templateId, float $weight = 0, float $volume = 0, int $quantity = 1): float
    {
        $template = $this->getModel()->load($templateId);
        if (!$template->getId()) {
            throw new \RuntimeException(__('费用模板不存在'));
        }
        
        $calculationType = $template->getData(RateTemplate::fields_CALCULATION_TYPE);
        $baseFee = (float)$template->getData(RateTemplate::fields_BASE_FEE);
        
        $fee = $baseFee;
        
        switch ($calculationType) {
            case RateTemplate::CALC_TYPE_WEIGHT:
                $fee += $this->calculateByWeight($template, $weight);
                break;
                
            case RateTemplate::CALC_TYPE_VOLUME:
                $fee += $this->calculateByVolume($template, $volume);
                break;
                
            case RateTemplate::CALC_TYPE_QUANTITY:
                $fee += $this->calculateByQuantity($template, $quantity);
                break;
                
            case RateTemplate::CALC_TYPE_FIXED:
                // 固定费用，只使用base_fee
                break;
                
            case RateTemplate::CALC_TYPE_MIXED:
                $fee += $this->calculateMixed($template, $weight, $volume, $quantity);
                break;
        }
        
        return max(0, $fee);
    }

    /**
     * 按重量计算
     * 
     * @param RateTemplate $template
     * @param float $weight
     * @return float
     */
    private function calculateByWeight(RateTemplate $template, float $weight): float
    {
        $weightRate = (float)$template->getData(RateTemplate::fields_WEIGHT_RATE);
        return $weight * $weightRate;
    }

    /**
     * 按体积计算
     * 
     * @param RateTemplate $template
     * @param float $volume
     * @return float
     */
    private function calculateByVolume(RateTemplate $template, float $volume): float
    {
        $volumeRate = (float)$template->getData(RateTemplate::fields_VOLUME_RATE);
        return $volume * $volumeRate;
    }

    /**
     * 按件数计算
     * 
     * @param RateTemplate $template
     * @param int $quantity
     * @return float
     */
    private function calculateByQuantity(RateTemplate $template, int $quantity): float
    {
        $quantityRate = (float)$template->getData(RateTemplate::fields_QUANTITY_RATE);
        return $quantity * $quantityRate;
    }

    /**
     * 混合模式计算
     * 
     * @param RateTemplate $template
     * @param float $weight
     * @param float $volume
     * @param int $quantity
     * @return float
     */
    private function calculateMixed(RateTemplate $template, float $weight, float $volume, int $quantity): float
    {
        $config = $template->getMixedConfig();
        $fee = 0;
        
        if (isset($config['weight']) && $config['weight']['enabled']) {
            $fee += $weight * ($config['weight']['rate'] ?? 0);
        }
        
        if (isset($config['volume']) && $config['volume']['enabled']) {
            $fee += $volume * ($config['volume']['rate'] ?? 0);
        }
        
        if (isset($config['quantity']) && $config['quantity']['enabled']) {
            $fee += $quantity * ($config['quantity']['rate'] ?? 0);
        }
        
        return $fee;
    }
}

