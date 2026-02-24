<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 选择类型参数 UI 组件
 */
class SelectType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'select';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $options = $param['options'] ?? [];
        $multiple = $param['multiple'] ?? false;
        $placeholder = $param['placeholder'] ?? '';
        $required = $param['required'] ?? false;
        $currentValue = $value ?? $this->getDefaultValue($param);
        $selectedValues = $multiple ? (array)$currentValue : [$currentValue];
        $selectAttrs = array_merge([
            'class' => 'w-param-select',
            'id' => $fieldId,
            'name' => $multiple ? $key . '[]' : $key,
        ], $attrs);
        if ($multiple) {
            $selectAttrs['multiple'] = true;
        }
        if ($required) {
            $selectAttrs['required'] = true;
        }
        $inputHtml = '<select ' . $this->buildAttrString($selectAttrs) . '>';
        if (!$multiple && !empty($placeholder)) {
            $inputHtml .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
        }
        foreach ($options as $optValue => $optLabel) {
            $isSelected = in_array((string)$optValue, array_map('strval', $selectedValues));
            $inputHtml .= '<option value="' . htmlspecialchars((string)$optValue) . '"' . ($isSelected ? ' selected' : '') . '>' . htmlspecialchars((string)$optLabel) . '</option>';
        }
        $inputHtml .= '</select>';
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return true;
        }
        $options = $param['options'] ?? [];
        $validKeys = array_map('strval', array_keys($options));
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!in_array((string)$v, $validKeys, true)) {
                    return false;
                }
            }
        } else {
            if (!in_array((string)$value, $validKeys, true)) {
                return false;
            }
        }
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return ($param['multiple'] ?? false) ? (is_array($value) ? $value : [$value]) : $value;
    }
}
