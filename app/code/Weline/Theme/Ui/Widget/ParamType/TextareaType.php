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
 * 多行文本类型参数 UI 组件
 * 
 * 渲染多行文本编辑器，支持 HTML 模式和代码编辑模式
 */
class TextareaType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'textarea';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $rows = $param['rows'] ?? 4;
        $maxlength = $param['maxlength'] ?? null;
        $placeholder = $param['placeholder'] ?? '';
        $required = $param['required'] ?? false;
        $mode = $param['mode'] ?? 'text'; // text, html, code
        $language = $param['language'] ?? 'html'; // 代码模式的语言
        $autoResize = $param['auto_resize'] ?? false;
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        
        $wrapperClass = 'textarea-wrapper';
        if ($mode === 'html') {
            $wrapperClass .= ' html-mode';
        } elseif ($mode === 'code') {
            $wrapperClass .= ' code-mode';
        }
        
        $inputHtml = '<div class="' . $wrapperClass . '">';
        
        // 工具栏（HTML 模式）
        if ($mode === 'html') {
            $inputHtml .= '<div class="textarea-toolbar">';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="bold" title="' . __('粗体') . '"><i class="ri-bold"></i></button>';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="italic" title="' . __('斜体') . '"><i class="ri-italic"></i></button>';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="underline" title="' . __('下划线') . '"><i class="ri-underline"></i></button>';
            $inputHtml .= '<span class="toolbar-divider"></span>';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="link" title="' . __('链接') . '"><i class="ri-link"></i></button>';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="image" title="' . __('图片') . '"><i class="ri-image-line"></i></button>';
            $inputHtml .= '<span class="toolbar-divider"></span>';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="preview" title="' . __('预览') . '"><i class="ri-eye-line"></i></button>';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-action="source" title="' . __('源代码') . '"><i class="ri-code-line"></i></button>';
            $inputHtml .= '</div>';
        }
        
        // 文本域
        $textareaClass = 'form-control';
        if ($autoResize) {
            $textareaClass .= ' auto-resize';
        }
        if ($mode === 'code') {
            $textareaClass .= ' code-textarea';
        }
        
        $inputHtml .= '<textarea class="' . $textareaClass . '" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" rows="' . (int)$rows . '"';
        
        if (!empty($placeholder)) {
            $inputHtml .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        }
        if ($maxlength !== null) {
            $inputHtml .= ' maxlength="' . (int)$maxlength . '"';
        }
        if ($required) {
            $inputHtml .= ' required';
        }
        if ($mode === 'code') {
            $inputHtml .= ' data-language="' . htmlspecialchars($language) . '"';
            $inputHtml .= ' spellcheck="false"';
        }
        
        $inputHtml .= '>' . htmlspecialchars($currentValue) . '</textarea>';
        
        // HTML 预览区域
        if ($mode === 'html') {
            $inputHtml .= '<div class="textarea-preview" id="' . htmlspecialchars($fieldId) . '_preview" style="display:none;"></div>';
        }
        
        // 字符计数
        if ($maxlength !== null) {
            $currentLength = mb_strlen($currentValue);
            $inputHtml .= '<div class="textarea-counter">';
            $inputHtml .= '<span class="current-count">' . $currentLength . '</span> / <span class="max-count">' . $maxlength . '</span>';
            $inputHtml .= '</div>';
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
        
        $maxlength = $param['maxlength'] ?? null;
        if ($maxlength !== null && mb_strlen((string)$value) > $maxlength) {
            return false;
        }
        
        return true;
    }

    public function processValue(mixed $value, array $param): mixed
    {
        $mode = $param['mode'] ?? 'text';
        $text = (string)$value;
        
        // HTML 模式下进行基本的 XSS 过滤
        if ($mode === 'html') {
            // 允许的标签
            $allowedTags = '<p><br><strong><b><em><i><u><a><img><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><span><div>';
            $text = strip_tags($text, $allowedTags);
        }
        
        return $text;
    }
}
