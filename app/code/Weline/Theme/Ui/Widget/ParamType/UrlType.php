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
 * URL 类型参数 UI 组件
 * 
 * 渲染 URL 输入框，带链接图标前缀，支持 URL 验证
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
        $allowRelative = $param['allow_relative'] ?? true;
        $openInNewTab = $param['open_in_new_tab'] ?? null; // 是否显示"新窗口打开"选项
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        
        $inputHtml = '<div class="url-input-wrapper">';
        
        // 带图标的输入框
        $inputHtml .= '<div class="input-group">';
        $inputHtml .= '<span class="input-group-text"><i class="ri-link"></i></span>';
        $inputHtml .= '<input type="url" class="form-control" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '" placeholder="' . htmlspecialchars($placeholder) . '"';
        if ($required) {
            $inputHtml .= ' required';
        }
        $inputHtml .= '>';
        
        // 测试链接按钮
        if (!empty($currentValue)) {
            $inputHtml .= '<button type="button" class="btn btn-outline-secondary btn-test-url" data-target="' . htmlspecialchars($fieldId) . '" title="' . __('测试链接') . '">';
            $inputHtml .= '<i class="ri-external-link-line"></i>';
            $inputHtml .= '</button>';
        }
        $inputHtml .= '</div>';
        
        // 新窗口打开选项
        if ($openInNewTab !== null) {
            $targetFieldId = $fieldId . '_target';
            $targetValue = is_array($value) && isset($value['target']) ? $value['target'] === '_blank' : $openInNewTab;
            
            $inputHtml .= '<div class="form-check mt-2">';
            $inputHtml .= '<input class="form-check-input" type="checkbox" id="' . htmlspecialchars($targetFieldId) . '" name="' . htmlspecialchars($key) . '_target" value="_blank"';
            if ($targetValue) {
                $inputHtml .= ' checked';
            }
            $inputHtml .= '>';
            $inputHtml .= '<label class="form-check-label" for="' . htmlspecialchars($targetFieldId) . '">' . __('在新窗口打开') . '</label>';
            $inputHtml .= '</div>';
        }
        
        $inputHtml .= '</div>';
        
        // 快捷链接建议
        $suggestions = $param['suggestions'] ?? [];
        if (!empty($suggestions)) {
            $inputHtml .= '<div class="url-suggestions">';
            $inputHtml .= '<small class="text-muted">' . __('快捷链接:') . ' </small>';
            foreach ($suggestions as $label => $suggestUrl) {
                $inputHtml .= '<button type="button" class="btn btn-link btn-sm p-0 me-2 url-suggestion-btn" data-url="' . htmlspecialchars($suggestUrl) . '" data-target="' . htmlspecialchars($fieldId) . '">' . htmlspecialchars($label) . '</button>';
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
        
        // 允许相对路径
        if ($allowRelative) {
            if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
                return true;
            }
        }
        
        // 允许 mailto 和 tel 链接
        if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return true;
        }
        
        // 允许 javascript: 链接（谨慎使用）
        if (str_starts_with($url, 'javascript:')) {
            return true;
        }
        
        // 验证绝对 URL
        return (bool)filter_var($url, FILTER_VALIDATE_URL);
    }

    public function processValue(mixed $value, array $param): mixed
    {
        $url = trim((string)$value);
        
        // 自动添加 http:// 前缀（如果看起来像域名）
        if (!empty($url) && !str_contains($url, '://') && !str_starts_with($url, '/') && !str_starts_with($url, '#') && !str_starts_with($url, 'mailto:') && !str_starts_with($url, 'tel:')) {
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.[a-zA-Z]{2,}/', $url)) {
                $url = 'https://' . $url;
            }
        }
        
        return $url;
    }
}
