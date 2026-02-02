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
 * 选择类型参数 UI 组件
 * 
 * 渲染下拉选择框，支持单选和多选模式
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
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param);
        $selectedValues = $multiple ? (array)$currentValue : [$currentValue];
        
        // 构建选择框属性
        $selectAttrs = array_merge([
            'class' => 'form-select',
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
        
        // 添加占位选项
        if (!$multiple && !empty($placeholder)) {
            $inputHtml .= '<option value="">' . htmlspecialchars($placeholder) . '</option>';
        }
        
        // 渲染选项
        foreach ($options as $optValue => $optLabel) {
            $isSelected = in_array((string)$optValue, array_map('strval', $selectedValues));
            $selectedAttr = $isSelected ? ' selected' : '';
            $inputHtml .= '<option value="' . htmlspecialchars((string)$optValue) . '"' . $selectedAttr . '>' . htmlspecialchars((string)$optLabel) . '</option>';
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
            return true; // 空值已在父类验证
        }
        
        $options = $param['options'] ?? [];
        $validKeys = array_keys($options);
        
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!in_array((string)$v, array_map('strval', $validKeys))) {
                    return false;
                }
            }
        } else {
            if (!in_array((string)$value, array_map('strval', $validKeys))) {
                return false;
            }
        }
        
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        $multiple = $param['multiple'] ?? false;
        
        if ($multiple) {
            return is_array($value) ? $value : [$value];
        }
        
        return $value;
    }
}
