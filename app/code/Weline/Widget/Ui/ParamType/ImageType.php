<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 图片类型参数 UI 组件
 */
class ImageType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'image';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $accept = $param['accept'] ?? 'image/*';
        $maxSize = $param['max_size'] ?? null;
        $aspectRatio = $param['aspect_ratio'] ?? null;
        $placeholder = $param['placeholder'] ?? __('点击选择图片或输入URL');
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $hasImage = !empty($currentValue);
        $inputHtml = '<div class="w-param-image">';
        $inputHtml .= '<div class="w-param-image-preview' . ($hasImage ? ' w-param-has-image' : '') . '" id="' . htmlspecialchars($fieldId) . '_preview">';
        if ($hasImage) {
            $inputHtml .= '<img src="' . htmlspecialchars($currentValue) . '" alt="' . __('预览') . '">';
        }
        $inputHtml .= '<div class="w-param-image-placeholder" style="' . ($hasImage ? 'display:none;' : '') . '">' . htmlspecialchars($placeholder) . '</div>';
        $inputHtml .= '<div class="w-param-image-actions">';
        $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-primary" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('从媒体库选择') . '">' . __('选择') . '</button>';
        $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-secondary" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('上传图片') . '">' . __('上传') . '</button>';
        if ($hasImage) {
            $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-danger w-param-image-clear" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('清除图片') . '">×</button>';
        }
        $inputHtml .= '</div></div>';
        $inputHtml .= '<div class="w-param-input-group">';
        $inputHtml .= '<span class="w-param-input-group-text">URL</span>';
        $inputHtml .= '<input type="text" class="w-param-input" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '" placeholder="' . __('图片URL') . '" data-preview="' . htmlspecialchars($fieldId) . '_preview">';
        $inputHtml .= '</div>';
        $inputHtml .= '<input type="file" style="display:none;" id="' . htmlspecialchars($fieldId) . '_file" accept="' . htmlspecialchars($accept) . '" data-target="' . htmlspecialchars($fieldId) . '"' . ($maxSize !== null ? ' data-max-size="' . (string)$maxSize . '"' : '') . '>';
        $hints = [];
        if ($maxSize !== null) {
            $hints[] = sprintf(__('最大 %s KB'), $maxSize);
        }
        if ($aspectRatio !== null) {
            $hints[] = sprintf(__('建议比例 %s'), $aspectRatio);
        }
        if (!empty($hints)) {
            $inputHtml .= '<small class="w-param-image-hint">' . implode(' | ', $hints) . '</small>';
        }
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
        $url = (string)$value;
        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return true;
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return true;
        }
        if (str_starts_with($url, 'data:image/')) {
            return true;
        }
        return false;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return trim((string)$value);
    }
}
