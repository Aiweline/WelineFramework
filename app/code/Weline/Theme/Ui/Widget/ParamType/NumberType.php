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
 * 数字类型参数 UI 组件
 * 
 * 渲染数字输入框，支持 min、max、step 等属性
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
        
        // 使用当前值或默认值
        $currentValue = $value ?? $this->getDefaultValue($param);
        
        // 构建输入框属性
        $inputAttrs = array_merge([
            'type' => 'number',
            'class' => 'form-control',
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
        
        // 如果有 min/max，显示范围提示
        if ($min !== null || $max !== null) {
            $rangeHint = '';
            if ($min !== null && $max !== null) {
                $rangeHint = sprintf(__('范围: %s - %s'), $min, $max);
            } elseif ($min !== null) {
                $rangeHint = sprintf(__('最小值: %s'), $min);
            } else {
                $rangeHint = sprintf(__('最大值: %s'), $max);
            }
            $inputHtml .= '<small class="form-text text-muted">' . $rangeHint . '</small>';
        }
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        if ($value === null || $value === '') {
            return true; // 空值已在父类验证
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
        
        // 判断是否为整数
        if (is_numeric($value) && floor((float)$value) == $value) {
            return (int)$value;
        }
        
        return (float)$value;
    }
}
