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
        $storedValue = $this->serializeImageFormValue($currentValue);
        $previewUrl = $this->imagePreviewUrl($currentValue);

        $inputHtml = '<div class="w-param-media-image">';
        $inputHtml .= '<div class="w-param-image-preview' . ($hasImage ? ' w-param-has-image' : '') . '" id="' . htmlspecialchars($fieldId) . '_preview">';
        if ($previewUrl !== '') {
            $inputHtml .= '<img src="' . htmlspecialchars($previewUrl) . '" alt="' . __('预览') . '">';
        }
        $inputHtml .= '<div class="w-param-image-placeholder"' . ($hasImage ? ' hidden' : '') . '>' . htmlspecialchars($placeholder) . '</div>';
        $inputHtml .= '<div class="w-param-image-actions">';
        $inputHtml .= '<button type="button" class="w-button w-param-media-image-select" data-tone="primary" data-variant="outline" data-size="sm" '
            . 'data-target="' . htmlspecialchars($fieldId) . '" '
            . 'data-default-dir="' . htmlspecialchars($defaultDir) . '" '
            . ($recommendW !== '' ? ' data-recommend-w="' . htmlspecialchars((string)$recommendW) . '"' : '')
            . ($recommendH !== '' ? ' data-recommend-h="' . htmlspecialchars((string)$recommendH) . '"' : '')
            . ' title="' . __('从媒体库选择') . '">' . __('选择') . '</button>';
        if ($hasImage) {
            $inputHtml .= '<button type="button" class="w-button w-param-image-clear" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" data-target="' . htmlspecialchars($fieldId) . '" aria-label="' . __('清除图片') . '">×</button>';
        }
        $inputHtml .= '</div></div>';
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($storedValue) . '" data-preview="' . htmlspecialchars($fieldId) . '_preview" data-clear-label="' . __('清除图片') . '"' . $this->buildImageHiddenInputExtraAttrs($currentValue) . '>';
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
        if ($this->normalizeFileImageNode($value) !== null) {
            return true;
        }
        if (!is_scalar($value)) {
            return false;
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
        return $this->normalizeFileImageNode($value) ?? trim((string)$value);
    }
}
