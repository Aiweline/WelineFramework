<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Ui\Widget\ParamType;

/**
 * 范围滑块类型参数 UI 组件
 * 
 * 渲染范围滑块控件，支持 min、max、step，实时显示当前值
 */
class RangeType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'range';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $min = $param['min'] ?? 0;
        $max = $param['max'] ?? 100;
        $step = $param['step'] ?? 1;
        $unit = $param['unit'] ?? '';
        $showInput = $param['show_input'] ?? true;
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? $min;
        
        $inputHtml = '<div class="range-slider-wrapper">';
        
        // 范围滑块
        $inputHtml .= '<div class="range-slider-container">';
        $inputHtml .= '<input type="range" class="form-range" id="' . htmlspecialchars($fieldId) . '_slider" ';
        $inputHtml .= 'min="' . htmlspecialchars((string)$min) . '" ';
        $inputHtml .= 'max="' . htmlspecialchars((string)$max) . '" ';
        $inputHtml .= 'step="' . htmlspecialchars((string)$step) . '" ';
        $inputHtml .= 'value="' . htmlspecialchars((string)$currentValue) . '" ';
        $inputHtml .= 'data-target="' . htmlspecialchars($fieldId) . '">';
        $inputHtml .= '</div>';
        
        // 值显示/输入框
        $inputHtml .= '<div class="range-value-display">';
        if ($showInput) {
            $inputHtml .= '<input type="number" class="form-control range-value-input" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" ';
            $inputHtml .= 'min="' . htmlspecialchars((string)$min) . '" ';
            $inputHtml .= 'max="' . htmlspecialchars((string)$max) . '" ';
            $inputHtml .= 'step="' . htmlspecialchars((string)$step) . '" ';
            $inputHtml .= 'value="' . htmlspecialchars((string)$currentValue) . '" ';
            $inputHtml .= 'data-slider="' . htmlspecialchars($fieldId) . '_slider">';
        } else {
            $inputHtml .= '<span class="range-value-label" id="' . htmlspecialchars($fieldId) . '_label">' . htmlspecialchars((string)$currentValue) . '</span>';
            $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$currentValue) . '">';
        }
        
        if (!empty($unit)) {
            $inputHtml .= '<span class="range-unit">' . htmlspecialchars($unit) . '</span>';
        }
        $inputHtml .= '</div>';
        
        $inputHtml .= '</div>';
        
        // 范围指示
        $inputHtml .= '<div class="range-limits">';
        $inputHtml .= '<span class="range-min">' . htmlspecialchars((string)$min) . ($unit ? $unit : '') . '</span>';
        $inputHtml .= '<span class="range-max">' . htmlspecialchars((string)$max) . ($unit ? $unit : '') . '</span>';
        $inputHtml .= '</div>';
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        if ($value === null || $value === '') {
            return true;
        }
        
        if (!is_numeric($value)) {
            return false;
        }
        
        $numValue = (float)$value;
        $min = $param['min'] ?? 0;
        $max = $param['max'] ?? 100;
        
        return $numValue >= $min && $numValue <= $max;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        if ($value === null || $value === '') {
            return $param['min'] ?? 0;
        }
        
        $numValue = (float)$value;
        $min = $param['min'] ?? 0;
        $max = $param['max'] ?? 100;
        
        // 确保值在范围内
        return max($min, min($max, $numValue));
    }
}
