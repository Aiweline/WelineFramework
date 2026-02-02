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
 * 字符串类型参数 UI 组件
 * 
 * 渲染单行文本输入框，支持 placeholder、maxlength、pattern 等属性
 */
class StringType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'string';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $placeholder = $param['placeholder'] ?? '';
        $maxlength = $param['maxlength'] ?? null;
        $pattern = $param['pattern'] ?? null;
        $required = $param['required'] ?? false;
        
        // 构建输入框属性
        $inputAttrs = array_merge([
            'type' => 'text',
            'class' => 'form-control',
            'id' => $fieldId,
            'name' => $key,
            'value' => htmlspecialchars((string)($value ?? $this->getDefaultValue($param) ?? '')),
        ], $attrs);
        
        if (!empty($placeholder)) {
            $inputAttrs['placeholder'] = $placeholder;
        }
        if ($maxlength !== null) {
            $inputAttrs['maxlength'] = (string)$maxlength;
        }
        if ($pattern !== null) {
            $inputAttrs['pattern'] = $pattern;
        }
        if ($required) {
            $inputAttrs['required'] = true;
        }
        
        $inputHtml = '<input ' . $this->buildAttrString($inputAttrs) . '>';
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        $maxlength = $param['maxlength'] ?? null;
        if ($maxlength !== null && strlen((string)$value) > $maxlength) {
            return false;
        }
        
        $pattern = $param['pattern'] ?? null;
        if ($pattern !== null && !preg_match('/' . $pattern . '/', (string)$value)) {
            return false;
        }
        
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return (string)$value;
    }
}
