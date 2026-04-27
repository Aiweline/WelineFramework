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
        $inputHtml .= '<div class="w-param-array-items" id="' . htmlspecialchars($fieldId) . '_items">';
        if (empty($items)) {
            $inputHtml .= '<div class="w-param-array-empty"><p>' . htmlspecialchars($emptyMessage) . '</p></div>';
        } else {
            foreach ($items as $index => $item) {
                $inputHtml .= $this->renderArrayItem($key, $fieldId, $index, $item, $itemSchema, $sortable, $layoutId);
            }
        }
        $inputHtml .= '</div>';
        $inputHtml .= '<div class="w-param-array-actions">';
        $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-outline-primary w-param-array-add" data-target="' . htmlspecialchars($fieldId) . '" data-key="' . htmlspecialchars($key) . '"' . ($maxItems !== null && count($items) >= $maxItems ? ' disabled' : '') . '>+ ' . htmlspecialchars($addLabel);
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
        $inputHtml .= '<script type="application/json" id="' . htmlspecialchars($fieldId) . '_schema">' . json_encode($itemSchema, JSON_UNESCAPED_UNICODE) . '</script>';
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
     * 当 item_schema 中存在 media_image 字段时，输出「选择图片添加」按钮，用于先选图再新增一项并回填图片，其余字段可编辑
     */
    private function renderAddWithMediaButton(string $fieldId, string $key, array $itemSchema, ?int $maxItems, int $currentCount): string
    {
        $imageFieldKey = null;
        $defaultDir = 'banner';
        $recommendW = '';
        $recommendH = '';
        foreach ($itemSchema as $fieldKey => $fieldDef) {
            if (($fieldDef['type'] ?? '') === 'media_image') {
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
        $btn = '<button type="button" class="w-param-btn w-param-btn-outline-secondary w-param-array-add-with-media" '
            . 'data-target="' . htmlspecialchars($fieldId) . '" data-key="' . htmlspecialchars($key) . '" '
            . 'data-image-field="' . htmlspecialchars($imageFieldKey) . '" '
            . 'data-default-dir="' . htmlspecialchars($defaultDir) . '" '
            . ($recommendW !== '' ? ' data-recommend-w="' . htmlspecialchars($recommendW) . '"' : '')
            . ($recommendH !== '' ? ' data-recommend-h="' . htmlspecialchars($recommendH) . '"' : '')
            . ($disabled ? ' disabled' : '')
            . ' title="' . __('从媒体库选择图片并添加为一项，可再编辑标题等') . '">' . __('选择图片添加') . '</button>';
        return $btn;
    }

    private function renderArrayItem(string $key, string $fieldId, int|string $index, array $item, array $itemSchema, bool $sortable, int|string $layoutId): string
    {
        $html = '<div class="w-param-array-item" data-index="' . htmlspecialchars((string)$index) . '">';
        if ($sortable) {
            $html .= '<div class="w-param-array-handle" title="' . __('拖拽排序') . '">⋮⋮</div>';
        }
        $html .= '<div class="w-param-array-content">';
        if (empty($itemSchema)) {
            $itemValue = is_scalar($item) ? $item : '';
            $html .= '<input type="text" class="w-param-input w-param-array-item-input" value="' . htmlspecialchars((string)$itemValue) . '" data-key="' . htmlspecialchars($key) . '" data-index="' . htmlspecialchars((string)$index) . '">';
        } else {
            $html .= '<div class="w-param-array-fields">';
            foreach ($itemSchema as $fieldKey => $fieldDef) {
                $fieldValue = $item[$fieldKey] ?? $fieldDef['default'] ?? '';
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
        $html .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-danger w-param-array-remove" title="' . __('删除') . '">×</button>';
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
                $html = '<select class="w-param-select" data-field="' . htmlspecialchars($fieldKey) . '">';
                foreach ($options as $optValue => $optLabel) {
                    $html .= '<option value="' . htmlspecialchars((string)$optValue) . '"' . ((string)$fieldValue === (string)$optValue ? ' selected' : '') . '>' . htmlspecialchars((string)$optLabel) . '</option>';
                }
                $html .= '</select>';
                break;
            case 'media_image': {
                $mediaOptions = $fieldDef['media_options'] ?? [];
                $defaultDir = $mediaOptions['default_directory'] ?? $fieldDef['default_directory'] ?? 'banner';
                $recommendW = $mediaOptions['recommend_width'] ?? $fieldDef['recommend_width'] ?? '';
                $recommendH = $mediaOptions['recommend_height'] ?? $fieldDef['recommend_height'] ?? '';
                $hasImage = !empty($fieldValue);
                $html = '<div class="w-param-media-image">';
                $html .= '<div class="w-param-image-preview' . ($hasImage ? ' w-param-has-image' : '') . '" id="' . htmlspecialchars($itemFieldId) . '_preview">';
                if ($hasImage) {
                    $html .= '<img src="' . htmlspecialchars((string)$fieldValue) . '" alt="' . __('预览') . '">';
                }
                $html .= '<div class="w-param-image-placeholder" style="' . ($hasImage ? 'display:none;' : '') . '">' . htmlspecialchars(__('从媒体库选择')) . '</div>';
                $html .= '<div class="w-param-image-actions">';
                $html .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-primary w-param-media-image-select" '
                    . 'data-target="' . htmlspecialchars($itemFieldId) . '" data-field="' . htmlspecialchars($fieldKey) . '" '
                    . 'data-default-dir="' . htmlspecialchars($defaultDir) . '" '
                    . ($recommendW !== '' ? ' data-recommend-w="' . htmlspecialchars((string)$recommendW) . '"' : '')
                    . ($recommendH !== '' ? ' data-recommend-h="' . htmlspecialchars((string)$recommendH) . '"' : '')
                    . '>' . __('选择') . '</button>';
                if ($hasImage) {
                    $html .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-danger w-param-image-clear" data-target="' . htmlspecialchars($itemFieldId) . '">×</button>';
                }
                $html .= '</div></div>';
                $html .= '<input type="hidden" class="w-param-array-item-input" value="' . htmlspecialchars((string)$fieldValue) . '" data-field="' . htmlspecialchars($fieldKey) . '" id="' . htmlspecialchars($itemFieldId) . '" data-preview="' . htmlspecialchars($itemFieldId) . '_preview">';
                $html .= '</div>';
                break;
            }
            case 'image':
            case 'url':
                $html = '<div class="w-param-input-group"><span class="w-param-input-group-text">URL</span>';
                $html .= '<input type="text" class="w-param-input" value="' . htmlspecialchars((string)$fieldValue) . '" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '"></div>';
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
                $html .= '<input type="text" class="w-param-input w-param-array-item-input" id="' . htmlspecialchars($itemFieldId) . '" value="' . htmlspecialchars($textValue) . '" placeholder="#000000" data-field="' . htmlspecialchars($fieldKey) . '">';
                if ($allowTransparent) {
                    $isTransparent = strtolower($textValue) === 'transparent';
                    $btnClass = $isTransparent ? 'w-param-btn w-param-btn-sm w-param-btn-outline-secondary w-param-btn-transparent active' : 'w-param-btn w-param-btn-sm w-param-btn-outline-secondary w-param-btn-transparent';
                    $html .= '<button type="button" class="' . $btnClass . '" data-target="' . htmlspecialchars($itemFieldId) . '" title="' . __('设为透明') . '">□</button>';
                }
                $html .= '</div>';
                $presets = $fieldDef['presets'] ?? [];
                if (!empty($presets)) {
                    $html .= '<div class="w-param-color-presets">';
                    foreach ($presets as $preset) {
                        $html .= '<button type="button" class="w-param-color-preset" style="background-color: ' . htmlspecialchars($preset) . ';" data-color="' . htmlspecialchars($preset) . '" data-target="' . htmlspecialchars($itemFieldId) . '" title="' . htmlspecialchars($preset) . '"></button>';
                    }
                    $html .= '</div>';
                }
                break;
            }
            case 'bool':
                $html = '<div class="w-param-form-check"><input type="checkbox" data-field="' . htmlspecialchars($fieldKey) . '"' . ($fieldValue ? ' checked' : '') . '></div>';
                break;
            case 'textarea':
                $html = '<textarea class="w-param-input" rows="2" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">' . htmlspecialchars((string)$fieldValue) . '</textarea>';
                break;
            default:
                $inputType = $type === 'number' ? 'number' : 'text';
                $html = '<input type="' . $inputType . '" class="w-param-input" value="' . htmlspecialchars((string)$fieldValue) . '" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">';
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
