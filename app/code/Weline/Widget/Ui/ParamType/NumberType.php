<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 数字类型参数 UI 组件
 */
class NumberType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'number';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $min = $param['min'] ?? null;
        $max = $param['max'] ?? null;
        $step = $param['step'] ?? null;
        $placeholder = $param['placeholder'] ?? '';
        $required = $param['required'] ?? false;
        $currentValue = $value ?? $this->getDefaultValue($param);
        $inputAttrs = array_merge([
            'type' => 'number',
            'class' => 'w-param-input',
            'id' => $fieldId,
            'name' => $key,
            'value' => $currentValue !== null ? (string)$currentValue : '',
        ], $attrs);
        if ($min !== null) {
            $inputAttrs['min'] = (string)$min;
        }
        if ($max !== null) {
            $inputAttrs['max'] = (string)$max;
        }
        if ($step !== null) {
            $inputAttrs['step'] = (string)$step;
        }
        if (!empty($placeholder)) {
            $inputAttrs['placeholder'] = $placeholder;
        }
        if ($required) {
            $inputAttrs['required'] = true;
        }
        $inputHtml = '<input ' . $this->buildAttrString($inputAttrs) . '>';
        if ($min !== null || $max !== null) {
            $rangeHint = $min !== null && $max !== null ? sprintf(__('范围: %s - %s'), $min, $max) : ($min !== null ? sprintf(__('最小值: %s'), $min) : sprintf(__('最大值: %s'), $max));
            $inputHtml .= '<small class="w-param-field-desc">' . $rangeHint . '</small>';
        }
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
        $min = $param['min'] ?? null;
        $max = $param['max'] ?? null;
        if ($min !== null && $numValue < $min) {
            return false;
        }
        if ($max !== null && $numValue > $max) {
            return false;
        }
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value) && floor((float)$value) == $value) {
            return (int)$value;
        }
        return (float)$value;
    }
}
