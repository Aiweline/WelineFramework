<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * Generic async select. A widget declares its provider/operation; this type
 * stays business-neutral and asks Weline.Api instead of owning any endpoint.
 */
class QuerySelectType extends AbstractParamType
{
    public function getTypeCode(): string
    {
        return 'query_select';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $id = $this->generateFieldId($key, $layoutId);
        $statusId = $id . '_status';
        $provider = htmlspecialchars($this->normalizeIdentifier($param['query_provider'] ?? ''), ENT_QUOTES, 'UTF-8');
        $operation = htmlspecialchars($this->normalizeIdentifier($param['query_operation'] ?? ''), ENT_QUOTES, 'UTF-8');
        $valueKey = htmlspecialchars($this->normalizeResultKey($param['value_key'] ?? 'value', 'value'), ENT_QUOTES, 'UTF-8');
        $labelKey = htmlspecialchars($this->normalizeResultKey($param['label_key'] ?? 'label', 'label'), ENT_QUOTES, 'UTF-8');
        $currentValue = (string)($value ?? $this->getDefaultValue($param) ?? '');
        $current = htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8');
        $currentLabel = htmlspecialchars((string)($param['current_label'] ?? $currentValue), ENT_QUOTES, 'UTF-8');
        $placeholder = htmlspecialchars((string)($param['placeholder'] ?? __('搜索')), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $required = !empty($param['required']) ? ' required' : '';

        $html = '<div class="w-query-select" data-w-component="widget-query-select"'
            . ' data-provider="' . $provider . '"'
            . ' data-operation="' . $operation . '"'
            . ' data-value-key="' . $valueKey . '"'
            . ' data-label-key="' . $labelKey . '"'
            . ' data-current="' . $current . '"'
            . ' data-loading-label="' . htmlspecialchars((string)__('正在加载选项…'), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-empty-label="' . htmlspecialchars((string)__('没有匹配选项'), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-error-label="' . htmlspecialchars((string)__('选项加载失败，请重试'), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="search" class="w-input w-query-select__search"'
            . ' placeholder="' . $placeholder . '"'
            . ' autocomplete="off" data-query-search>';
        $html .= '<select id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="w-select w-query-select__value" name="' . $name . '"'
            . ' aria-describedby="' . htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8') . '" data-query-value' . $required . '>';
        $html .= '<option value="' . $current . '">' . $currentLabel . '</option>';
        $html .= '</select>';
        $html .= '<span id="' . htmlspecialchars($statusId, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="w-query-select__status" role="status" aria-live="polite" hidden></span>';
        $html .= '</div>';

        return $this->wrapField($key, $param, $html, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        return parent::validate($value, $param);
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return trim((string)$value);
    }

    private function normalizeIdentifier(mixed $value): string
    {
        $identifier = trim((string)$value);
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/i', $identifier)) {
            return '';
        }
        return in_array(strtolower($identifier), ['constructor', 'prototype', '__proto__'], true)
            ? ''
            : $identifier;
    }

    private function normalizeResultKey(mixed $value, string $fallback): string
    {
        $key = $this->normalizeIdentifier($value);
        return $key === '' ? $fallback : $key;
    }
}
