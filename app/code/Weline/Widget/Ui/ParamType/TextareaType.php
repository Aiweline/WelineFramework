<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 多行文本类型参数 UI 组件
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
        $mode = $param['mode'] ?? 'text';
        $language = $param['language'] ?? 'html';
        $autoResize = $param['auto_resize'] ?? false;
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $inputHtml = '<div class="w-param-textarea">';
        $textareaClass = 'w-param-input' . ($autoResize ? ' w-param-auto-resize' : '') . ($mode === 'code' ? ' w-param-code' : '');
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
            $inputHtml .= ' data-language="' . htmlspecialchars($language) . '" spellcheck="false"';
        }
        $inputHtml .= '>' . htmlspecialchars($currentValue) . '</textarea>';
        if ($maxlength !== null) {
            $inputHtml .= '<div class="w-param-field-desc"><span class="w-param-count-current">' . mb_strlen($currentValue) . '</span> / <span class="w-param-count-max">' . $maxlength . '</span></div>';
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
        $text = (string)$value;
        if (($param['mode'] ?? 'text') === 'html') {
            $allowedTags = '<p><br><strong><b><em><i><u><a><img><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><span><div>';
            $text = strip_tags($text, $allowedTags);
        }
        return $text;
    }
}
