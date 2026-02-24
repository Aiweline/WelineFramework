<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 布尔类型参数 UI 组件
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
        $currentValue = $value ?? $this->getDefaultValue($param);
        $checked = $this->isTruthy($currentValue);
        $inputAttrs = array_merge([
            'type' => 'checkbox',
            'class' => 'w-param-input',
            'id' => $fieldId,
            'name' => $key,
            'value' => '1',
        ], $attrs);
        if ($checked) {
            $inputAttrs['checked'] = true;
        }
        $inputHtml = '<div class="w-param-form-check">';
        $inputHtml .= '<input ' . $this->buildAttrString($inputAttrs) . '>';
        $inputHtml .= '<label for="' . htmlspecialchars($fieldId) . '">' . htmlspecialchars($enableLabel) . '</label>';
        $inputHtml .= '</div>';
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    public function validate(mixed $value, array $param): bool
    {
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return $this->isTruthy($value);
    }

    public function getDefaultValue(array $param): mixed
    {
        return $this->isTruthy($param['default'] ?? false);
    }
}
