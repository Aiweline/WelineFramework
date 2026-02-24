<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * URL 类型参数 UI 组件
 */
class UrlType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'url';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $placeholder = $param['placeholder'] ?? 'https://';
        $required = $param['required'] ?? false;
        $openInNewTab = $param['open_in_new_tab'] ?? null;
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $inputHtml = '<div class="w-param-url">';
        $inputHtml .= '<div class="w-param-input-group">';
        $inputHtml .= '<span class="w-param-input-group-text">URL</span>';
        $inputHtml .= '<input type="url" class="w-param-input" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '" placeholder="' . htmlspecialchars($placeholder) . '"' . ($required ? ' required' : '') . '>';
        if (!empty($currentValue)) {
            $inputHtml .= '<a href="' . htmlspecialchars($currentValue) . '" target="_blank" rel="noopener" class="w-param-btn w-param-btn-outline-secondary">' . __('测试') . '</a>';
        }
        $inputHtml .= '</div>';
        if ($openInNewTab !== null) {
            $targetFieldId = $fieldId . '_target';
            $targetValue = is_array($value) && isset($value['target']) ? $value['target'] === '_blank' : $openInNewTab;
            $inputHtml .= '<div class="w-param-form-check" style="margin-top:0.5rem;">';
            $inputHtml .= '<input type="checkbox" id="' . htmlspecialchars($targetFieldId) . '" name="' . htmlspecialchars($key) . '_target" value="_blank"' . ($targetValue ? ' checked' : '') . '>';
            $inputHtml .= '<label for="' . htmlspecialchars($targetFieldId) . '">' . __('在新窗口打开') . '</label>';
            $inputHtml .= '</div>';
        }
        $inputHtml .= '</div>';
        $suggestions = $param['suggestions'] ?? [];
        if (!empty($suggestions)) {
            $inputHtml .= '<div class="w-param-field-desc">' . __('快捷链接:') . ' ';
            foreach ($suggestions as $label => $suggestUrl) {
                $inputHtml .= '<button type="button" class="w-param-btn" style="padding:0 0.25rem;" data-url="' . htmlspecialchars($suggestUrl) . '" data-target="' . htmlspecialchars($fieldId) . '">' . htmlspecialchars($label) . '</button> ';
            }
            $inputHtml .= '</div>';
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
        $url = (string)$value;
        $allowRelative = $param['allow_relative'] ?? true;
        if ($allowRelative && (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../') || str_starts_with($url, '#') || str_starts_with($url, '?'))) {
            return true;
        }
        if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return true;
        }
        if (str_starts_with($url, 'javascript:')) {
            return true;
        }
        return (bool)filter_var($url, FILTER_VALIDATE_URL);
    }

    public function processValue(mixed $value, array $param): mixed
    {
        $url = trim((string)$value);
        if (!empty($url) && !str_contains($url, '://') && !str_starts_with($url, '/') && !str_starts_with($url, '#') && !str_starts_with($url, 'mailto:') && !str_starts_with($url, 'tel:')) {
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.[a-zA-Z]{2,}/', $url)) {
                $url = 'https://' . $url;
            }
        }
        return $url;
    }
}
