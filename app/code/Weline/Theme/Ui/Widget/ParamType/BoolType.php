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
 * 布尔类型参数 UI 组件
 * 
 * 渲染开关/复选框控件，使用 Bootstrap 的 form-switch 样式
 */
class BoolType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'bool';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $enableLabel = $param['enable_label'] ?? __('启用');
        
        // 获取当前值，如果未设置则使用默认值
        $currentValue = $value ?? $this->getDefaultValue($param);
        $checked = $this->isTruthy($currentValue);
        
        // 构建复选框属性
        $inputAttrs = array_merge([
            'type' => 'checkbox',
            'class' => 'form-check-input',
            'id' => $fieldId,
            'name' => $key,
            'value' => '1',
        ], $attrs);
        
        if ($checked) {
            $inputAttrs['checked'] = true;
        }
        
        $inputHtml = '<div class="form-check form-switch">';
        $inputHtml .= '<input ' . $this->buildAttrString($inputAttrs) . '>';
        $inputHtml .= '<label class="form-check-label" for="' . htmlspecialchars($fieldId) . '">' . htmlspecialchars($enableLabel) . '</label>';
        $inputHtml .= '</div>';
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    /**
     * 判断值是否为真
     */
    private function isTruthy(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on') {
            return true;
        }
        return false;
    }

    public function validate(mixed $value, array $param): bool
    {
        // 布尔值总是有效的
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return $this->isTruthy($value);
    }

    public function getDefaultValue(array $param): mixed
    {
        $default = $param['default'] ?? false;
        return $this->isTruthy($default);
    }
}
