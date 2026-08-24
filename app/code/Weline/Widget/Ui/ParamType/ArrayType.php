<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 数组类型参数 UI 组件
 */
class ArrayType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'array';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $itemSchema = $param['item_schema'] ?? [];
        $minItems = $param['min_items'] ?? 0;
        $maxItems = $param['max_items'] ?? null;
        $sortable = $param['sortable'] ?? true;
        $addLabel = $param['add_label'] ?? __('添加项目');
        $emptyMessage = $param['empty_message'] ?? __('暂无项目，点击下方按钮添加');
        $items = $this->normalizeItems($value ?? $this->getDefaultValue($param) ?? []);
        $inputHtml = '<div class="w-param-array" data-field-id="' . htmlspecialchars($fieldId) . '" data-key="' . htmlspecialchars($key) . '" data-min-items="' . $minItems . '"' . ($maxItems !== null ? ' data-max-items="' . $maxItems . '"' : '') . '>';
        $reorderAttributes = $sortable
            ? ' data-w-component="reorder-list" data-w-reorder-axis="vertical" data-w-reorder-announcement="'
                . htmlspecialchars((string)__('已移动到第 {position} 项，共 {total} 项'), ENT_QUOTES, 'UTF-8') . '"'
            : '';
        $inputHtml .= '<div class="w-param-array-items" id="' . htmlspecialchars($fieldId) . '_items"' . $reorderAttributes . '>';
        if (empty($items)) {
            $inputHtml .= '<div class="w-param-array-empty"><p>' . htmlspecialchars($emptyMessage) . '</p></div>';
        } else {
            foreach ($items as $index => $item) {
                $inputHtml .= $this->renderArrayItem($key, $fieldId, $index, $item, $itemSchema, $sortable, $layoutId);
            }
        }
        $inputHtml .= '</div>';
        $inputHtml .= '<div class="w-param-array-actions">';
        $inputHtml .= '<button type="button" class="w-button w-param-array-add" data-tone="primary" data-variant="outline" data-target="' . htmlspecialchars($fieldId) . '" data-key="' . htmlspecialchars($key) . '"' . ($maxItems !== null && count($items) >= $maxItems ? ' disabled' : '') . '>+ ' . htmlspecialchars($addLabel);
        $inputHtml .= '</button>';
        $inputHtml .= $this->renderAddWithMediaButton($fieldId, $key, $itemSchema, $maxItems, count($items));
        if ($maxItems !== null) {
            $inputHtml .= '<span class="w-param-array-count">' . sprintf(__('%d / %d 项'), count($items), $maxItems) . '</span>';
        }
        $inputHtml .= '</div>';
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE)) . '">';
        $inputHtml .= '<template id="' . htmlspecialchars($fieldId) . '_template">';
        $inputHtml .= $this->renderArrayItem($key, $fieldId, '__INDEX__', [], $itemSchema, $sortable, $layoutId);
        $inputHtml .= '</template>';
        $schemaJson = json_encode(
            $itemSchema,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';
        $inputHtml .= '<script type="application/json" id="' . htmlspecialchars($fieldId) . '_schema">' . $schemaJson . '</script>';
        $inputHtml .= '</div>';
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    private function normalizeItems(mixed $items): array
    {
        if (is_string($items)) {
            $trimmed = trim($items);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            return [];
        }

        return is_array($items) ? $items : [];
    }

    /**
     * 当 item_schema 中存在图片字段时，输出「选择图片添加」按钮，用于先选图再新增一项并回填图片，其余字段可编辑
     */
    private function renderAddWithMediaButton(string $fieldId, string $key, array $itemSchema, ?int $maxItems, int $currentCount): string
    {
        $imageFieldKey = null;
        $defaultDir = 'banner';
        $recommendW = '';
        $recommendH = '';
        foreach ($itemSchema as $fieldKey => $fieldDef) {
            if (in_array(($fieldDef['type'] ?? ''), ['image', 'image_picker', 'media_image', 'file_image'], true)) {
                $imageFieldKey = $fieldKey;
                $opts = $fieldDef['media_options'] ?? [];
                $defaultDir = $opts['default_directory'] ?? $fieldDef['default_directory'] ?? 'banner';
                $recommendW = (string)($opts['recommend_width'] ?? $fieldDef['recommend_width'] ?? '');
                $recommendH = (string)($opts['recommend_height'] ?? $fieldDef['recommend_height'] ?? '');
                break;
            }
        }
        if ($imageFieldKey === null) {
            return '';
        }
        $disabled = $maxItems !== null && $currentCount >= $maxItems;
        $btn = '<button type="button" class="w-button w-param-array-add-with-media" data-tone="neutral" data-variant="outline" '
            . 'data-target="' . htmlspecialchars($fieldId) . '" data-key="' . htmlspecialchars($key) . '" '
            . 'data-image-field="' . htmlspecialchars($imageFieldKey) . '" '
            . 'data-default-dir="' . htmlspecialchars($defaultDir) . '" '
            . ($recommendW !== '' ? ' data-recommend-w="' . htmlspecialchars($recommendW) . '"' : '')
            . ($recommendH !== '' ? ' data-recommend-h="' . htmlspecialchars($recommendH) . '"' : '')
            . ($disabled ? ' disabled' : '')
            . ' title="' . __('从媒体库选择图片并添加为一项，可再编辑标题等') . '">' . __('选择图片添加') . '</button>';
        return $btn;
    }

    private function renderArrayItem(string $key, string $fieldId, int|string $index, mixed $item, array $itemSchema, bool $sortable, int|string $layoutId): string
    {
        $html = '<div class="w-param-array-item" data-index="' . htmlspecialchars((string)$index) . '"' . ($sortable ? ' data-w-reorder-item' : '') . '>';
        if ($sortable) {
            $reorderLabel = htmlspecialchars((string)__('拖拽或使用方向键排序'), ENT_QUOTES, 'UTF-8');
            $html .= '<button type="button" class="w-param-array-handle" data-w-reorder-handle aria-label="' . $reorderLabel . '" title="' . $reorderLabel . '">⋮⋮</button>';
        }
        $html .= '<div class="w-param-array-content">';
        if (empty($itemSchema)) {
            $itemValue = is_scalar($item) ? $item : '';
            $html .= '<input type="text" class="w-input w-param-array-item-input" value="' . htmlspecialchars((string)$itemValue) . '" data-key="' . htmlspecialchars($key) . '" data-index="' . htmlspecialchars((string)$index) . '">';
        } else {
            $itemData = is_array($item) ? $item : [];
            $html .= '<div class="w-param-array-fields">';
            foreach ($itemSchema as $fieldKey => $fieldDef) {
                $fieldValue = $itemData[$fieldKey] ?? $fieldDef['default'] ?? '';
                $fieldLabel = $fieldDef['label'] ?? $fieldKey;
                $isTranslatable = self::isTranslatable($fieldDef);

                if ($isTranslatable) {
                    $inputHtml = $this->renderItemField($key, $fieldId, $index, $fieldKey, $fieldValue, $fieldDef);
                    $compositeKey = "{$key}.{$index}.{$fieldKey}";
                    $paramForWrap = array_merge($fieldDef, ['label' => $fieldLabel]);
                    $html .= '<div class="w-param-array-field">';
                    $html .= $this->renderTranslatableWrap($compositeKey, $paramForWrap, $layoutId, $inputHtml, [
                        'array_key' => $key,
                        'array_index' => (string)$index,
                    ]);
                    $html .= '</div>';
                } else {
                    $html .= '<div class="w-param-array-field">';
                    $html .= '<label class="w-param-array-label">' . htmlspecialchars($fieldLabel) . '</label>';
                    $html .= $this->renderItemField($key, $fieldId, $index, $fieldKey, $fieldValue, $fieldDef);
                    $html .= '</div>';
                }
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<div class="w-param-array-item-actions">';
        $html .= '<button type="button" class="w-button w-param-array-remove" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" aria-label="' . __('删除') . '">×</button>';
        $html .= '</div></div>';
        return $html;
    }

    private function renderItemField(string $key, string $fieldId, int|string $index, string $fieldKey, mixed $fieldValue, array $fieldDef): string
    {
        $type = $fieldDef['type'] ?? 'string';
        $placeholder = $fieldDef['placeholder'] ?? '';
        $html = '';
        $itemFieldId = $fieldId . '_' . $index . '_' . $fieldKey;
        switch ($type) {
            case 'select':
                $options = $fieldDef['options'] ?? [];
                $html = '<select class="w-select" data-field="' . htmlspecialchars($fieldKey) . '">';
                foreach ($options as $optValue => $optLabel) {
                    $html .= '<option value="' . htmlspecialchars((string)$optValue) . '"' . ((string)$fieldValue === (string)$optValue ? ' selected' : '') . '>' . htmlspecialchars((string)$optLabel) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'image':
            case 'image_picker':
            case 'media_image':
            case 'file_image': {
                $mediaOptions = $fieldDef['media_options'] ?? [];
                $defaultDir = $mediaOptions['default_directory'] ?? $fieldDef['default_directory'] ?? 'banner';
                $recommendW = $mediaOptions['recommend_width'] ?? $fieldDef['recommend_width'] ?? '';
                $recommendH = $mediaOptions['recommend_height'] ?? $fieldDef['recommend_height'] ?? '';
                $hasImage = !empty($fieldValue);
                $storedValue = $this->serializeImageFormValue($fieldValue);
                $previewUrl = $this->imagePreviewUrl($fieldValue);
                $placeholderText = $previewUrl !== ''
                    ? (string)__('从媒体库选择')
                    : ($hasImage ? (string)__('缩略图加载中…') : (string)__('从媒体库选择'));
                $html = '<div class="w-param-media-image">';
                $html .= '<div class="w-param-image-preview' . ($hasImage ? ' w-param-has-image' : '') . '" id="' . htmlspecialchars($itemFieldId) . '_preview">';
                if ($previewUrl !== '') {
                    $html .= '<img src="' . htmlspecialchars($previewUrl) . '" alt="' . __('预览') . '">';
                }
                $html .= '<div class="w-param-image-placeholder"' . ($previewUrl !== '' ? ' hidden' : '') . '>' . htmlspecialchars($placeholderText) . '</div>';
                $html .= '<div class="w-param-image-actions">';
                $html .= '<button type="button" class="w-button w-param-media-image-select" data-tone="primary" data-variant="outline" data-size="sm" '
                    . 'data-target="' . htmlspecialchars($itemFieldId) . '" data-field="' . htmlspecialchars($fieldKey) . '" '
                    . 'data-default-dir="' . htmlspecialchars($defaultDir) . '" '
                    . ($recommendW !== '' ? ' data-recommend-w="' . htmlspecialchars((string)$recommendW) . '"' : '')
                    . ($recommendH !== '' ? ' data-recommend-h="' . htmlspecialchars((string)$recommendH) . '"' : '')
                    . '>' . __('选择') . '</button>';
                if ($hasImage) {
                    $html .= '<button type="button" class="w-button w-param-image-clear" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" data-target="' . htmlspecialchars($itemFieldId) . '" aria-label="' . __('清除图片') . '">×</button>';
                }
                $html .= '</div></div>';
                $html .= '<input type="hidden" class="w-param-array-item-input" value="' . htmlspecialchars($storedValue) . '" data-field="' . htmlspecialchars($fieldKey) . '" id="' . htmlspecialchars($itemFieldId) . '" data-preview="' . htmlspecialchars($itemFieldId) . '_preview" data-clear-label="' . __('清除图片') . '"' . $this->buildImageHiddenInputExtraAttrs($fieldValue) . '>';
                $html .= '</div>';
                break;
            }
            case 'url':
                $html = '<div class="w-param-input-group"><span class="w-param-input-group-text">URL</span>';
                $html .= '<input type="text" class="w-input" value="' . htmlspecialchars((string)$fieldValue) . '" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '"></div>';
                break;
            case 'color': {
                $allowTransparent = $fieldDef['allow_transparent'] ?? true;
                $textValue = (string)$fieldValue;
                if ($textValue === '') {
                    $textValue = (string)($fieldDef['default'] ?? '#000000');
                }
                $pickerValue = $this->normalizeColorForPickerInArray($textValue);
                $html = '<div class="w-param-color">';
                $html .= '<input type="color" class="w-param-form-control-color" id="' . htmlspecialchars($itemFieldId) . '_picker" value="' . htmlspecialchars($pickerValue) . '" data-target="' . htmlspecialchars($itemFieldId) . '">';
                $html .= '<input type="text" class="w-input w-param-array-item-input" id="' . htmlspecialchars($itemFieldId) . '" value="' . htmlspecialchars($textValue) . '" placeholder="#000000" data-field="' . htmlspecialchars($fieldKey) . '">';
                if ($allowTransparent) {
                    $isTransparent = strtolower($textValue) === 'transparent';
                    $html .= '<button type="button" class="w-button w-param-btn-transparent" data-tone="neutral" data-variant="outline" data-size="sm" data-state="' . ($isTransparent ? 'active' : 'idle') . '" data-target="' . htmlspecialchars($itemFieldId) . '" title="' . __('设为透明') . '">□</button>';
                }
                $html .= '</div>';
                $presets = array_values(array_filter(array_map(
                    fn (mixed $preset): ?string => $this->normalizeCssColorScalar($preset),
                    is_array($fieldDef['presets'] ?? null) ? $fieldDef['presets'] : []
                ), static fn (?string $preset): bool => $preset !== null));
                if ($presets !== []) {
                    $html .= '<div class="w-param-color-presets">';
                    foreach ($presets as $preset) {
                        $safePreset = htmlspecialchars($preset, ENT_QUOTES, 'UTF-8');
                        $html .= '<button type="button" class="w-param-color-preset" style="--w-param-preset-color:' . $safePreset . '" data-color="' . $safePreset . '" data-target="' . htmlspecialchars($itemFieldId) . '" title="' . $safePreset . '"></button>';
                    }
                    $html .= '</div>';
                }
                break;
            }
            case 'bool':
                $html = '<div class="w-check w-param-form-check"><input type="checkbox" data-field="' . htmlspecialchars($fieldKey) . '"' . ($fieldValue ? ' checked' : '') . '></div>';
                break;
            case 'textarea':
                $html = '<textarea class="w-textarea" rows="2" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">' . htmlspecialchars((string)$fieldValue) . '</textarea>';
                break;
            default:
                $inputType = $type === 'number' ? 'number' : 'text';
                $html = '<input type="' . $inputType . '" class="w-input" value="' . htmlspecialchars((string)$fieldValue) . '" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">';
        }
        return $html;
    }

    private function normalizeColorForPickerInArray(string $color): string
    {
        $color = strtolower(trim($color));
        if ($color === '' || in_array($color, ['transparent', 'inherit', 'initial'], true)) {
            return '#000000';
        }
        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return $color;
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $color, $matches)) {
            return '#' . $matches[1] . $matches[1] . $matches[2] . $matches[2] . $matches[3] . $matches[3];
        }
        $named = ['white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff', 'yellow' => '#ffff00', 'orange' => '#ffa500', 'purple' => '#800080', 'gray' => '#808080', 'grey' => '#808080'];
        return $named[$color] ?? '#000000';
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        if ($value === null || $value === '' || $value === '[]') {
            return true;
        }
        if (is_string($value)) {
            $value = json_decode($value, true) ?? [];
        }
        if (!is_array($value)) {
            return false;
        }
        $minItems = $param['min_items'] ?? 0;
        $maxItems = $param['max_items'] ?? null;
        if (count($value) < $minItems) {
            return false;
        }
        if ($maxItems !== null && count($value) > $maxItems) {
            return false;
        }
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    public function getDefaultValue(array $param): mixed
    {
        return $param['default'] ?? [];
    }
}
