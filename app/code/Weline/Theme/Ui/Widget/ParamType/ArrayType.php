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
 * 数组类型参数 UI 组件
 * 
 * 渲染动态列表编辑器，支持添加/删除/排序项目
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
        $itemSchema = $param['item_schema'] ?? []; // 每项的字段定义
        $minItems = $param['min_items'] ?? 0;
        $maxItems = $param['max_items'] ?? null;
        $sortable = $param['sortable'] ?? true;
        $addLabel = $param['add_label'] ?? __('添加项目');
        $emptyMessage = $param['empty_message'] ?? __('暂无项目，点击下方按钮添加');
        
        // 获取当前值
        $items = $value ?? $this->getDefaultValue($param) ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        
        $inputHtml = '<div class="array-editor-wrapper" data-field-id="' . htmlspecialchars($fieldId) . '" data-min-items="' . $minItems . '"';
        if ($maxItems !== null) {
            $inputHtml .= ' data-max-items="' . $maxItems . '"';
        }
        $inputHtml .= '>';
        
        // 列表容器
        $inputHtml .= '<div class="array-items-container" id="' . htmlspecialchars($fieldId) . '_items">';
        
        if (empty($items)) {
            $inputHtml .= '<div class="array-empty-state">';
            $inputHtml .= '<i class="ri-list-check-2"></i>';
            $inputHtml .= '<p>' . htmlspecialchars($emptyMessage) . '</p>';
            $inputHtml .= '</div>';
        } else {
            foreach ($items as $index => $item) {
                $inputHtml .= $this->renderArrayItem($key, $fieldId, $index, $item, $itemSchema, $sortable, $layoutId);
            }
        }
        
        $inputHtml .= '</div>';
        
        // 添加按钮
        $inputHtml .= '<div class="array-actions">';
        $inputHtml .= '<button type="button" class="btn btn-outline-primary btn-add-array-item" data-target="' . htmlspecialchars($fieldId) . '" data-key="' . htmlspecialchars($key) . '"';
        if ($maxItems !== null && count($items) >= $maxItems) {
            $inputHtml .= ' disabled';
        }
        $inputHtml .= '>';
        $inputHtml .= '<i class="ri-add-line"></i> ' . htmlspecialchars($addLabel);
        $inputHtml .= '</button>';
        
        if ($maxItems !== null) {
            $inputHtml .= '<span class="array-count-info">' . sprintf(__('%d / %d 项'), count($items), $maxItems) . '</span>';
        }
        $inputHtml .= '</div>';
        
        // 隐藏字段存储 JSON 数据
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE)) . '">';
        
        // 项目模板（用于 JavaScript 动态添加）
        $inputHtml .= '<template id="' . htmlspecialchars($fieldId) . '_template">';
        $inputHtml .= $this->renderArrayItem($key, $fieldId, '__INDEX__', [], $itemSchema, $sortable, $layoutId);
        $inputHtml .= '</template>';
        
        // 字段 Schema（供 JavaScript 使用）
        $inputHtml .= '<script type="application/json" id="' . htmlspecialchars($fieldId) . '_schema">' . json_encode($itemSchema, JSON_UNESCAPED_UNICODE) . '</script>';
        
        $inputHtml .= '</div>';
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    /**
     * 渲染单个数组项
     */
    private function renderArrayItem(string $key, string $fieldId, int|string $index, array $item, array $itemSchema, bool $sortable, int|string $layoutId): string
    {
        $html = '<div class="array-item" data-index="' . htmlspecialchars((string)$index) . '">';
        
        // 拖拽手柄
        if ($sortable) {
            $html .= '<div class="array-item-handle" title="' . __('拖拽排序') . '">';
            $html .= '<i class="ri-draggable"></i>';
            $html .= '</div>';
        }
        
        // 项目内容
        $html .= '<div class="array-item-content">';
        
        if (empty($itemSchema)) {
            // 简单字符串数组
            $itemValue = is_scalar($item) ? $item : '';
            $html .= '<input type="text" class="form-control array-item-input" value="' . htmlspecialchars((string)$itemValue) . '" data-key="' . htmlspecialchars($key) . '" data-index="' . htmlspecialchars((string)$index) . '">';
        } else {
            // 复杂对象数组
            $html .= '<div class="array-item-fields">';
            foreach ($itemSchema as $fieldKey => $fieldDef) {
                $fieldValue = $item[$fieldKey] ?? $fieldDef['default'] ?? '';
                $fieldLabel = $fieldDef['label'] ?? $fieldKey;
                $fieldType = $fieldDef['type'] ?? 'string';
                
                $html .= '<div class="array-item-field">';
                $html .= '<label class="array-field-label">' . htmlspecialchars($fieldLabel) . '</label>';
                
                // 根据类型渲染不同的输入控件
                $html .= $this->renderItemField($key, $index, $fieldKey, $fieldValue, $fieldDef);
                
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // 删除按钮
        $html .= '<div class="array-item-actions">';
        $html .= '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-array-item" title="' . __('删除') . '">';
        $html .= '<i class="ri-delete-bin-line"></i>';
        $html .= '</button>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * 渲染数组项中的单个字段
     */
    private function renderItemField(string $key, int|string $index, string $fieldKey, mixed $fieldValue, array $fieldDef): string
    {
        $type = $fieldDef['type'] ?? 'string';
        $placeholder = $fieldDef['placeholder'] ?? '';
        $fieldName = $key . '[' . $index . '][' . $fieldKey . ']';
        
        $html = '';
        
        switch ($type) {
            case 'select':
                $options = $fieldDef['options'] ?? [];
                $html = '<select class="form-select form-select-sm" data-field="' . htmlspecialchars($fieldKey) . '">';
                foreach ($options as $optValue => $optLabel) {
                    $selected = (string)$fieldValue === (string)$optValue ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars((string)$optValue) . '"' . $selected . '>' . htmlspecialchars((string)$optLabel) . '</option>';
                }
                $html .= '</select>';
                break;
                
            case 'image':
            case 'url':
                $html = '<div class="input-group input-group-sm">';
                $html .= '<span class="input-group-text"><i class="ri-link"></i></span>';
                $html .= '<input type="text" class="form-control" value="' . htmlspecialchars((string)$fieldValue) . '" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">';
                $html .= '</div>';
                break;
                
            case 'bool':
                $checked = $fieldValue ? ' checked' : '';
                $html = '<div class="form-check form-switch">';
                $html .= '<input class="form-check-input" type="checkbox" data-field="' . htmlspecialchars($fieldKey) . '"' . $checked . '>';
                $html .= '</div>';
                break;
                
            case 'textarea':
                $html = '<textarea class="form-control form-control-sm" rows="2" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">' . htmlspecialchars((string)$fieldValue) . '</textarea>';
                break;
                
            default: // string, number
                $inputType = $type === 'number' ? 'number' : 'text';
                $html = '<input type="' . $inputType . '" class="form-control form-control-sm" value="' . htmlspecialchars((string)$fieldValue) . '" placeholder="' . htmlspecialchars($placeholder) . '" data-field="' . htmlspecialchars($fieldKey) . '">';
        }
        
        return $html;
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        if ($value === null || $value === '' || $value === '[]') {
            $value = [];
        }
        
        // 如果是 JSON 字符串，先解析
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
        
        // 如果是 JSON 字符串，先解析
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return [];
        }
        
        return is_array($value) ? $value : [];
    }

    public function getDefaultValue(array $param): mixed
    {
        return $param['default'] ?? [];
    }
}
