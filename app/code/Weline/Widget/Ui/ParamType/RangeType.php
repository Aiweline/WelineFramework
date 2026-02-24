<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 范围滑块类型参数 UI 组件
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
        $currentValue = $value ?? $this->getDefaultValue($param) ?? $min;
        $inputHtml = '<div class="w-param-range">';
        $inputHtml .= '<div class="w-param-range-slider">';
        $inputHtml .= '<input type="range" class="w-param-form-range" id="' . htmlspecialchars($fieldId) . '_slider" min="' . htmlspecialchars((string)$min) . '" max="' . htmlspecialchars((string)$max) . '" step="' . htmlspecialchars((string)$step) . '" value="' . htmlspecialchars((string)$currentValue) . '" data-target="' . htmlspecialchars($fieldId) . '">';
        $inputHtml .= '</div>';
        $inputHtml .= '<div class="w-param-range-value">';
        if ($showInput) {
            $inputHtml .= '<input type="number" class="w-param-range-input w-param-input" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" min="' . htmlspecialchars((string)$min) . '" max="' . htmlspecialchars((string)$max) . '" step="' . htmlspecialchars((string)$step) . '" value="' . htmlspecialchars((string)$currentValue) . '" data-slider="' . htmlspecialchars($fieldId) . '_slider">';
        } else {
            $inputHtml .= '<span class="w-param-range-label" id="' . htmlspecialchars($fieldId) . '_label">' . htmlspecialchars((string)$currentValue) . '</span>';
            $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$currentValue) . '">';
        }
        if (!empty($unit)) {
            $inputHtml .= '<span class="w-param-range-unit">' . htmlspecialchars($unit) . '</span>';
        }
        $inputHtml .= '</div></div>';
        $inputHtml .= '<div class="w-param-range-limits"><span>' . htmlspecialchars((string)$min) . $unit . '</span><span>' . htmlspecialchars((string)$max) . $unit . '</span></div>';
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
        return max($min, min($max, $numValue));
    }
}
