<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 媒体库图片类型参数 UI 组件
 * 从媒体库选择图片（不手填 URL），支持 default_directory、aspect_ratio、recommend_width/height 等 media_options
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
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $inputHtml = $this->renderMediaLibraryPickerHtml(
            $fieldId,
            $key,
            $param,
            $currentValue,
            'w-param-media-image-select',
        );

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
