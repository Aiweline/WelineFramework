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
        
        $calculationType = $template->getData(RateTemplate::schema_fields_CALCULATION_TYPE);
        $baseFee = (float)$template->getData(RateTemplate::schema_fields_BASE_FEE);
        
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
     * Checkout Quote path: calculate entirely in integer minor units.
     *
     * `weight_minor` is 1/1000 weight unit and `volume_minor` is 1/1,000,000
     * volume unit. Quantity is an integer unit count.
     *
     * @param list<array<string,mixed>> $lines
     */
    public function calculateMinor(int $templateId, array $lines, int $currencyPrecision = 2): int
    {
        $template = $this->getModel()->load($templateId);
        if (!$template->getId()) {
            throw new \RuntimeException(__('费用模板不存在'));
        }

        return $this->calculateTemplateMinor($template, $lines, $currencyPrecision);
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    public function calculateTemplateMinor(
        RateTemplate $template,
        array $lines,
        int $currencyPrecision = 2,
    ): int {
        if ($currencyPrecision < 0 || $currencyPrecision > 6) {
            throw new \InvalidArgumentException(__('币种精度非法'));
        }
        if (!(bool)$template->getData(RateTemplate::schema_fields_IS_ACTIVE)) {
            throw new \RuntimeException(__('费用模板未启用'));
        }

        $quantity = 0;
        $weightMinor = 0;
        $volumeMinor = 0;
        foreach ($lines as $line) {
            $quantity = $this->checkedAdd($quantity, max(0, (int)($line['qty_minor'] ?? 0)));
            $weightMinor = $this->checkedAdd($weightMinor, max(0, (int)($line['weight_minor'] ?? 0)));
            $volumeMinor = $this->checkedAdd($volumeMinor, max(0, (int)($line['volume_minor'] ?? 0)));
        }

        $fee = $this->decimalToMinor(
            (string)$template->getData(RateTemplate::schema_fields_BASE_FEE),
            $currencyPrecision,
        );
        $type = (string)$template->getData(RateTemplate::schema_fields_CALCULATION_TYPE);
        $fee = match ($type) {
            RateTemplate::CALC_TYPE_FIXED => $fee,
            RateTemplate::CALC_TYPE_QUANTITY => $this->checkedAdd(
                $fee,
                $this->checkedMultiply(
                    $this->decimalToMinor(
                        (string)$template->getData(RateTemplate::schema_fields_QUANTITY_RATE),
                        $currencyPrecision,
                    ),
                    $quantity,
                ),
            ),
            RateTemplate::CALC_TYPE_WEIGHT => $this->checkedAdd(
                $fee,
                $this->scaledCharge(
                    (string)$template->getData(RateTemplate::schema_fields_WEIGHT_RATE),
                    $weightMinor,
                    1_000,
                    $currencyPrecision,
                ),
            ),
            RateTemplate::CALC_TYPE_VOLUME => $this->checkedAdd(
                $fee,
                $this->scaledCharge(
                    (string)$template->getData(RateTemplate::schema_fields_VOLUME_RATE),
                    $volumeMinor,
                    1_000_000,
                    $currencyPrecision,
                ),
            ),
            RateTemplate::CALC_TYPE_MIXED => $this->calculateMixedMinor(
                $template,
                $fee,
                $quantity,
                $weightMinor,
                $volumeMinor,
                $currencyPrecision,
            ),
            default => throw new \RuntimeException(__('未知配送计费类型：%{1}', [$type])),
        };

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
        $weightRate = (float)$template->getData(RateTemplate::schema_fields_WEIGHT_RATE);
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
        $volumeRate = (float)$template->getData(RateTemplate::schema_fields_VOLUME_RATE);
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
        $quantityRate = (float)$template->getData(RateTemplate::schema_fields_QUANTITY_RATE);
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

    private function calculateMixedMinor(
        RateTemplate $template,
        int $baseMinor,
        int $quantity,
        int $weightMinor,
        int $volumeMinor,
        int $currencyPrecision,
    ): int {
        $config = $template->getMixedConfig();
        $fee = $baseMinor;
        if (!empty($config['weight']['enabled'])) {
            $fee = $this->checkedAdd($fee, $this->scaledCharge(
                (string)($config['weight']['rate'] ?? '0'),
                $weightMinor,
                1_000,
                $currencyPrecision,
            ));
        }
        if (!empty($config['volume']['enabled'])) {
            $fee = $this->checkedAdd($fee, $this->scaledCharge(
                (string)($config['volume']['rate'] ?? '0'),
                $volumeMinor,
                1_000_000,
                $currencyPrecision,
            ));
        }
        if (!empty($config['quantity']['enabled'])) {
            $fee = $this->checkedAdd(
                $fee,
                $this->checkedMultiply(
                    $this->decimalToMinor(
                        (string)($config['quantity']['rate'] ?? '0'),
                        $currencyPrecision,
                    ),
                    $quantity,
                ),
            );
        }

        return $fee;
    }

    private function scaledCharge(
        string $decimalRate,
        int $dimensionMinor,
        int $dimensionScale,
        int $currencyPrecision,
    ): int {
        $rateMinor = $this->decimalToMinor($decimalRate, $currencyPrecision);
        $product = $this->checkedMultiply($rateMinor, $dimensionMinor);

        return intdiv($this->checkedAdd($product, intdiv($dimensionScale, 2)), $dimensionScale);
    }

    private function decimalToMinor(string $decimal, int $precision): int
    {
        $decimal = trim($decimal);
        if (!preg_match('/^\+?([0-9]+)(?:\.([0-9]+))?$/D', $decimal, $match)) {
            throw new \InvalidArgumentException(__('配送金额格式非法'));
        }
        $whole = (int)$match[1];
        $fraction = (string)($match[2] ?? '');
        $scale = 10 ** $precision;
        $minor = $this->checkedMultiply($whole, $scale);
        if ($precision === 0) {
            if ($fraction !== '' && (int)$fraction[0] >= 5) {
                $minor = $this->checkedAdd($minor, 1);
            }
            return $minor;
        }
        $padded = str_pad($fraction, $precision + 1, '0');
        $minor = $this->checkedAdd($minor, (int)substr($padded, 0, $precision));
        if ((int)$padded[$precision] >= 5) {
            $minor = $this->checkedAdd($minor, 1);
        }

        return $minor;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new \OverflowException(__('配送金额加法溢出'));
        }

        return $left + $right;
    }

    private function checkedMultiply(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new \OverflowException(__('配送金额乘法溢出'));
        }

        return $left * $right;
    }
}
