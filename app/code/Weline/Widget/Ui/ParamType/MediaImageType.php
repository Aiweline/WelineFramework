<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 媒体库图片类型参数 UI 组件
 * 从媒体库选择图片（不手填 URL），支持 default_directory、recommend_width/height 等 media_options
 */
class MediaImageType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'media_image';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $mediaOptions = $param['media_options'] ?? [];
        $defaultDir = $mediaOptions['default_directory'] ?? $param['default_directory'] ?? 'banner';
        $recommendW = $mediaOptions['recommend_width'] ?? $param['recommend_width'] ?? '';
        $recommendH = $mediaOptions['recommend_height'] ?? $param['recommend_height'] ?? '';
        $placeholder = $param['placeholder'] ?? __('从媒体库选择图片');
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $hasImage = !empty($currentValue);

        $inputHtml = '<div class="w-param-media-image">';
        $inputHtml .= '<div class="w-param-image-preview' . ($hasImage ? ' w-param-has-image' : '') . '" id="' . htmlspecialchars($fieldId) . '_preview">';
        if ($hasImage) {
            $inputHtml .= '<img src="' . htmlspecialchars($currentValue) . '" alt="' . __('预览') . '">';
        }
        $inputHtml .= '<div class="w-param-image-placeholder" style="' . ($hasImage ? 'display:none;' : '') . '">' . htmlspecialchars($placeholder) . '</div>';
        $inputHtml .= '<div class="w-param-image-actions">';
        $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-primary w-param-media-image-select" '
            . 'data-target="' . htmlspecialchars($fieldId) . '" '
            . 'data-default-dir="' . htmlspecialchars($defaultDir) . '" '
            . ($recommendW !== '' ? ' data-recommend-w="' . htmlspecialchars((string)$recommendW) . '"' : '')
            . ($recommendH !== '' ? ' data-recommend-h="' . htmlspecialchars((string)$recommendH) . '"' : '')
            . ' title="' . __('从媒体库选择') . '">' . __('选择') . '</button>';
        if ($hasImage) {
            $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-danger w-param-image-clear" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('清除图片') . '">×</button>';
        }
        $inputHtml .= '</div></div>';
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '" data-preview="' . htmlspecialchars($fieldId) . '_preview">';
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
