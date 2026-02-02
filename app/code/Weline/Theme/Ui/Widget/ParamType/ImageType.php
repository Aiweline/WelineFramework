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
 * 图片类型参数 UI 组件
 * 
 * 渲染图片上传/选择控件，集成 FileManager，支持预览
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
        $maxSize = $param['max_size'] ?? null; // KB
        $aspectRatio = $param['aspect_ratio'] ?? null; // 如 '16:9'
        $placeholder = $param['placeholder'] ?? __('点击选择图片或输入URL');
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $hasImage = !empty($currentValue);
        
        $inputHtml = '<div class="image-picker-wrapper">';
        
        // 图片预览区域
        $inputHtml .= '<div class="image-preview-container' . ($hasImage ? ' has-image' : '') . '">';
        $inputHtml .= '<div class="image-preview" id="' . htmlspecialchars($fieldId) . '_preview">';
        if ($hasImage) {
            $inputHtml .= '<img src="' . htmlspecialchars($currentValue) . '" alt="' . __('预览') . '">';
        } else {
            $inputHtml .= '<div class="image-placeholder">';
            $inputHtml .= '<i class="ri-image-add-line"></i>';
            $inputHtml .= '<span>' . htmlspecialchars($placeholder) . '</span>';
            $inputHtml .= '</div>';
        }
        $inputHtml .= '</div>';
        
        // 操作按钮
        $inputHtml .= '<div class="image-actions">';
        $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-primary btn-select-image" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('从媒体库选择') . '">';
        $inputHtml .= '<i class="ri-folder-image-line"></i>';
        $inputHtml .= '</button>';
        $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary btn-upload-image" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('上传图片') . '">';
        $inputHtml .= '<i class="ri-upload-2-line"></i>';
        $inputHtml .= '</button>';
        if ($hasImage) {
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-danger btn-clear-image" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('清除图片') . '">';
            $inputHtml .= '<i class="ri-delete-bin-line"></i>';
            $inputHtml .= '</button>';
        }
        $inputHtml .= '</div>';
        $inputHtml .= '</div>';
        
        // URL 输入框
        $inputHtml .= '<div class="image-url-input">';
        $inputHtml .= '<div class="input-group">';
        $inputHtml .= '<span class="input-group-text"><i class="ri-link"></i></span>';
        $inputHtml .= '<input type="text" class="form-control" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '" placeholder="' . __('图片URL') . '" data-preview="' . htmlspecialchars($fieldId) . '_preview">';
        $inputHtml .= '</div>';
        $inputHtml .= '</div>';
        
        // 隐藏的文件上传输入
        $inputHtml .= '<input type="file" class="d-none image-file-input" id="' . htmlspecialchars($fieldId) . '_file" accept="' . htmlspecialchars($accept) . '" data-target="' . htmlspecialchars($fieldId) . '"';
        if ($maxSize !== null) {
            $inputHtml .= ' data-max-size="' . htmlspecialchars((string)$maxSize) . '"';
        }
        $inputHtml .= '>';
        
        // 提示信息
        $hints = [];
        if ($maxSize !== null) {
            $hints[] = sprintf(__('最大 %s KB'), $maxSize);
        }
        if ($aspectRatio !== null) {
            $hints[] = sprintf(__('建议比例 %s'), $aspectRatio);
        }
        if (!empty($hints)) {
            $inputHtml .= '<small class="form-text text-muted">' . implode(' | ', $hints) . '</small>';
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
        
        // 验证 URL 格式
        $url = (string)$value;
        
        // 允许相对路径
        if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return true;
        }
        
        // 验证绝对 URL
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return true;
        }
        
        // 验证 data URL
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
