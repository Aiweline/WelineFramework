<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * Generic async select. A widget declares its provider/operation; this type
 * stays business-neutral and asks Weline.Api instead of owning any endpoint.
 */
class QuerySelectType extends AbstractParamType
{
    public function getTypeCode(): string { return 'query_select'; }
    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $id = $this->generateFieldId($key, $layoutId); $provider = htmlspecialchars((string)($param['query_provider'] ?? ''), ENT_QUOTES, 'UTF-8'); $operation = htmlspecialchars((string)($param['query_operation'] ?? ''), ENT_QUOTES, 'UTF-8');
        $valueKey = htmlspecialchars((string)($param['value_key'] ?? 'value'), ENT_QUOTES, 'UTF-8'); $labelKey = htmlspecialchars((string)($param['label_key'] ?? 'label'), ENT_QUOTES, 'UTF-8'); $current = htmlspecialchars((string)($value ?? $this->getDefaultValue($param) ?? ''), ENT_QUOTES, 'UTF-8');
        $required = !empty($param['required']) ? ' required' : '';
        $html = '<div class="w-query-select" data-w-query-select data-provider="' . $provider . '" data-operation="' . $operation . '" data-value-key="' . $valueKey . '" data-label-key="' . $labelKey . '"><input type="search" class="w-param-input" placeholder="' . htmlspecialchars((string)($param['placeholder'] ?? __('搜索')), ENT_QUOTES, 'UTF-8') . '" data-query-search><select id="' . $id . '" class="w-param-select" name="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '" data-query-value' . $required . '><option value="' . $current . '">' . $current . '</option></select></div>';
        $html .= '<script>(function(){document.querySelectorAll("[data-w-query-select]").forEach(function(root){if(root.dataset.bound)return;root.dataset.bound="1";var input=root.querySelector("[data-query-search]"),select=root.querySelector("[data-query-value]"),run=async function(){try{var api=await Weline.load("api"),items=await api.resource(root.dataset.provider)[root.dataset.operation]({search:input.value});select.innerHTML="";(items||[]).forEach(function(item){var o=document.createElement("option");o.value=item[root.dataset.valueKey]||"";o.textContent=item[root.dataset.labelKey]||o.value;if(o.value===select.dataset.current)o.selected=true;select.appendChild(o)})}catch(e){}};select.dataset.current=select.value;input.addEventListener("input",function(){clearTimeout(input._q);input._q=setTimeout(run,180)});run()})})();</script>';
        return $this->wrapField($key, $param, $html, $layoutId);
    }
    public function validate(mixed $value, array $param): bool { return parent::validate($value, $param); }
    public function processValue(mixed $value, array $param): mixed { return trim((string)$value); }
}
