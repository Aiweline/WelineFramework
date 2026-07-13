<?php

declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Symfony\Component\Intl\Countries;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;
use Weline\Framework\Taglib\TaglibInterface;

class LanguageSelect implements TaglibInterface
{
    private static array $itemsCache = [];

    public static function name(): string
    {
        return 'i18n:language:select';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function tag_start(): bool
    {
        return false;
    }

    public static function tag_end(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => false,
            'value' => false,
            'multiple' => false,
            'class' => false,
            'style' => false,
            'required' => false,
            'allow-empty' => false,
            'display-only' => false,
            'readonly-values' => false,
            'allowed-values' => false,
            'option-values' => false,
            'options-values' => false,
            'display-locale' => false,
            'input-id' => false,
            'empty-text' => false,
            'search-placeholder' => false,
            'on-change' => false,
            'inline-dropdown' => false,
            'show-reference' => false,
        ];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            if (empty($attributes['id'])) {
                throw new \Exception(__('id属性不能为空'));
            }

            $code = \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);

            $html = [];
            $html[] = '<?php ' . $code . ' ?>';
$html[] = <<<'PHP'
<?php
$__wls_display_locale = \trim((string)($Taglib__display_locale ?? ''));
if ($__wls_display_locale === '') {
    $__wls_display_locale = \Weline\Framework\App\State::getLang() ?: \Weline\Framework\App\State::getLangLocal() ?: 'zh_Hans_CN';
}
$__wls_items_json = \Weline\I18n\Taglib\LanguageSelect::getLanguageItemsJson($__wls_display_locale);
?>
PHP;
$html[] = <<<'PHP'
<?php
$__wls_normalize_bool = static function ($value, bool $default = false): bool {
    if (\is_bool($value)) {
        return $value;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    $value = \strtolower(\trim((string) $value));
    if (\in_array($value, ['true', '1', 'yes', 'on'], true)) {
        return true;
    }
    if (\in_array($value, ['false', '0', 'no', 'off'], true)) {
        return false;
    }
    return $default;
};
$__wls_parse_values = static function ($raw): array {
    if (\is_array($raw)) {
        $values = $raw;
    } elseif ($raw === null || $raw === '') {
        $values = [];
    } else {
        $raw = \trim((string) $raw);
        if ($raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) {
            $decoded = \json_decode($raw, true);
            if (\json_last_error() === JSON_ERROR_NONE && \is_array($decoded)) {
                $values = $decoded;
            } else {
                $values = \preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        } else {
            $values = \preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
    }
    $result = [];
    foreach ($values as $value) {
        if (\is_array($value) && isset($value['code'])) {
            $value = $value['code'];
        }
        if (!\is_scalar($value)) {
            continue;
        }
        $value = (string) $value;
        if ($value === '' || \in_array($value, $result, true)) {
            continue;
        }
        $result[] = $value;
    }
    return $result;
};
$__wls_trim_text = static function ($value, string $default = ''): string {
    if ($value === null) {
        return $default;
    }
    $value = \trim((string) $value);
    if ($value === '') {
        return $default;
    }
    return \trim($value, "\"'");
};
$__wls_is_multiple = $__wls_normalize_bool($Taglib__multiple ?? false, false);
$__wls_display_only = $__wls_normalize_bool($Taglib__display_only ?? false, false);
$__wls_required = $__wls_normalize_bool($Taglib__required ?? false, false);
$__wls_allow_empty = $__wls_normalize_bool($Taglib__allow_empty ?? (!$__wls_required), !$__wls_required);
$__wls_selected_values = $__wls_parse_values($Taglib__value ?? []);
$__wls_readonly_values = $__wls_parse_values($Taglib__readonly_values ?? []);
$__wls_allowed_values = $__wls_parse_values(
    $Taglib__allowed_values ?? ($Taglib__option_values ?? ($Taglib__options_values ?? []))
);
foreach ($__wls_readonly_values as $__wls_readonly_value) {
    if (!\in_array($__wls_readonly_value, $__wls_selected_values, true)) {
        $__wls_selected_values[] = $__wls_readonly_value;
    }
}
if (!$__wls_is_multiple && \count($__wls_selected_values) > 1) {
    $__wls_selected_values = [\reset($__wls_selected_values) ?: ''];
}
$__wls_component_id = $__wls_trim_text($Taglib__id ?? 'language_select', 'language_select');
$__wls_field_id = $__wls_trim_text($Taglib__input_id ?? '', $__wls_component_id);
$__wls_name = $__wls_trim_text($Taglib__name ?? '');
$__wls_class = $__wls_trim_text($Taglib__class ?? '');
$__wls_style = $__wls_trim_text($Taglib__style ?? '');
$__wls_empty_text = $__wls_trim_text(
    $Taglib__empty_text ?? '',
    $__wls_is_multiple ? __('点击选择语言（可多选）') : __('点击选择语言')
);
$__wls_search_placeholder = $__wls_trim_text(
    $Taglib__search_placeholder ?? '',
    __('搜索国家、语言或代码...')
);
$__wls_on_change = $__wls_trim_text($Taglib__on_change ?? '');
$__wls_show_reference = $__wls_normalize_bool($Taglib__show_reference ?? true, true);
$__wls_inline_dropdown = $__wls_normalize_bool($Taglib__inline_dropdown ?? false, false);
$__wls_selected_json = \json_encode(
    $__wls_selected_values,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($__wls_selected_json === false) {
    $__wls_selected_json = '[]';
}
$__wls_readonly_json = \json_encode(
    $__wls_readonly_values,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($__wls_readonly_json === false) {
    $__wls_readonly_json = '[]';
}
$__wls_allowed_json = \json_encode(
    $__wls_allowed_values,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($__wls_allowed_json === false) {
    $__wls_allowed_json = '[]';
}
?>
PHP;
            $html[] = <<<'HTML'
<style>
.weline-language-select {
    position: relative;
    display: inline-block;
    max-width: 100%;
    overflow: visible;
    box-sizing: border-box;
    isolation: isolate;
}
.weline-language-select,
.weline-language-select * {
    box-sizing: border-box;
}
.weline-language-select.is-open {
    z-index: 4200;
}
.weline-language-select-trigger {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    width: 100%;
    min-height: 42px;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--backend-color-border-default, #ced4da);
    border-radius: var(--backend-border-radius-sm, 0.375rem);
    background: var(--backend-color-card-bg, #fff);
    color: var(--backend-color-text-primary, #212529);
    text-align: left;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.weline-language-select-trigger:hover,
.weline-language-select-trigger:focus {
    border-color: var(--backend-color-primary, #556ee6);
    box-shadow: 0 0 0 0.15rem rgba(85, 110, 230, 0.12);
    outline: none;
}
.weline-language-select-trigger.is-display-only,
.weline-language-select-trigger:disabled {
    cursor: default;
    box-shadow: none;
}
.weline-language-select-tags {
    display: flex;
    flex: 1;
    flex-wrap: wrap;
    gap: 0.4rem;
    align-items: center;
}
.weline-language-select-placeholder {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--backend-color-text-secondary, #6c757d);
    font-size: 0.9rem;
}
.weline-language-select-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.45rem;
    border-radius: 999px;
    background: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.12));
    color: var(--backend-color-primary, #556ee6);
    font-size: 0.8rem;
    line-height: 1.2;
    border: 1px solid rgba(85, 110, 230, 0.12);
}
.weline-language-select-tag.is-readonly {
    background: rgba(100, 116, 139, 0.12);
    border-color: rgba(100, 116, 139, 0.18);
    color: var(--backend-color-text-primary, #334155);
}
.weline-language-select-flag {
    display: inline-flex;
    align-items: center;
}
.weline-language-select-flag svg {
    display: inline-block;
    height: 1.1em;
    width: auto;
    max-width: 1.5em;
}
.weline-language-select-code {
    color: var(--backend-color-text-secondary, #64748b);
    font-size: 0.75rem;
}
.weline-language-select-state {
    display: inline-flex;
    align-items: center;
    padding: 0 0.35rem;
    border-radius: 999px;
    background: rgba(100, 116, 139, 0.14);
    color: var(--backend-color-text-secondary, #64748b);
    font-size: 0.72rem;
}
.weline-language-select-tag-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.1rem;
    height: 1.1rem;
    padding: 0;
    border: none;
    background: transparent;
    color: inherit;
    cursor: pointer;
    opacity: 0.72;
}
.weline-language-select-tag-remove:hover {
    opacity: 1;
}
.weline-language-select-dropdown {
    position: absolute;
    top: calc(100% + 0.25rem);
    left: 0;
    right: auto;
    width: 100%;
    box-sizing: border-box;
    z-index: 1080;
    padding: 0.75rem;
    border: 1px solid var(--backend-color-border-default, #dee2e6);
    border-radius: var(--backend-border-radius-md, 0.5rem);
    background: var(--backend-color-card-bg, #fff);
    box-shadow: var(--backend-shadow-lg, 0 0.75rem 2rem rgba(15, 23, 42, 0.14));
}
.weline-language-select-search {
    display: block;
    width: 100%;
    max-width: 100%;
    margin-bottom: 0.65rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--backend-color-border-default, #ced4da);
    border-radius: var(--backend-border-radius-sm, 0.375rem);
    background: var(--backend-color-card-bg, #fff);
    color: var(--backend-color-text-primary, #212529);
}
.weline-language-select-search:focus {
    border-color: var(--backend-color-primary, #556ee6);
    outline: none;
}
.weline-language-select-list {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid var(--backend-color-border-default, #e2e8f0);
    border-radius: var(--backend-border-radius-sm, 0.375rem);
    background: var(--backend-color-card-bg, #fff);
}
.weline-language-select-group-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.55rem 0.75rem;
    border-bottom: 1px solid var(--backend-color-border-light, #edf2f7);
    background: var(--backend-color-bg-secondary, #f8fafc);
    color: var(--backend-color-text-secondary, #64748b);
    font-size: 0.76rem;
    font-weight: 600;
    text-transform: uppercase;
}
.weline-language-select-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    width: 100%;
    padding: 0.65rem 0.75rem;
    border: none;
    border-bottom: 1px solid var(--backend-color-border-light, #edf2f7);
    background: transparent;
    color: var(--backend-color-text-primary, #0f172a);
    text-align: left;
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.weline-language-select-item:last-child {
    border-bottom: none;
}
.weline-language-select-item:hover {
    background: var(--backend-color-bg-secondary, #f8fafc);
}
.weline-language-select-item.is-selected {
    background: var(--backend-color-primary-bg-subtle, rgba(85, 110, 230, 0.08));
}
.weline-language-select-item.is-readonly {
    cursor: default;
}
.weline-language-select-indicator {
    width: 1.1rem;
    text-align: center;
    color: var(--backend-color-primary, #556ee6);
}
.weline-language-select-item-copy {
    display: grid;
    gap: 0.1rem;
    min-width: 0;
    flex: 1;
}
.weline-language-select-item-copy strong {
    color: inherit;
    font-size: 0.9rem;
}
.weline-language-select-item-copy small {
    color: var(--backend-color-text-secondary, #64748b);
    font-size: 0.76rem;
}
.weline-language-select-empty {
    padding: 1rem 0.9rem;
    color: var(--backend-color-text-secondary, #64748b);
    text-align: center;
    font-size: 0.86rem;
}
</style>

<div
    class="weline-language-select <?= htmlspecialchars($__wls_class, ENT_QUOTES, 'UTF-8') ?>"
    style="<?= htmlspecialchars($__wls_style, ENT_QUOTES, 'UTF-8') ?>"
    id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_wrapper"
    data-component-id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>"
    data-field-id="<?= htmlspecialchars($__wls_field_id, ENT_QUOTES, 'UTF-8') ?>"
    data-display-only="<?= $__wls_display_only ? 'true' : 'false' ?>"
    data-multiple="<?= $__wls_is_multiple ? 'true' : 'false' ?>"
    data-inline-dropdown="<?= $__wls_inline_dropdown ? 'true' : 'false' ?>"
>
    <button
        type="button"
        class="weline-language-select-trigger<?= $__wls_display_only ? ' is-display-only' : '' ?>"
        id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_trigger"
        <?= $__wls_display_only ? 'disabled' : '' ?>
    >
        <div class="weline-language-select-tags" id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_tags"></div>
        <?php if (!$__wls_display_only): ?>
            <i class="mdi mdi-chevron-down"></i>
        <?php endif; ?>
    </button>

    <?php if ($__wls_is_multiple): ?>
        <input
            type="hidden"
            id="<?= htmlspecialchars($__wls_field_id, ENT_QUOTES, 'UTF-8') ?>"
            value="<?= htmlspecialchars(\implode(',', $__wls_selected_values), ENT_QUOTES, 'UTF-8') ?>"
            data-name="<?= htmlspecialchars($__wls_name, ENT_QUOTES, 'UTF-8') ?>"
        >
        <div id="<?= htmlspecialchars($__wls_field_id, ENT_QUOTES, 'UTF-8') ?>_inputs"></div>
    <?php else: ?>
        <input
            type="hidden"
            id="<?= htmlspecialchars($__wls_field_id, ENT_QUOTES, 'UTF-8') ?>"
            name="<?= htmlspecialchars($__wls_name, ENT_QUOTES, 'UTF-8') ?>"
            value="<?= htmlspecialchars($__wls_selected_values[0] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            <?= $__wls_required ? 'required' : '' ?>
        >
    <?php endif; ?>

    <?php if (!$__wls_display_only): ?>
        <div
            class="weline-language-select-dropdown"
            id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_dropdown"
            style="display:none;"
        >
            <input
                type="text"
                class="weline-language-select-search"
                id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_search"
                placeholder="<?= htmlspecialchars($__wls_search_placeholder, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="off"
            >
            <div class="weline-language-select-list" id="<?= htmlspecialchars($__wls_component_id, ENT_QUOTES, 'UTF-8') ?>_list"></div>
        </div>
    <?php endif; ?>
</div>
HTML;
            $html[] = <<<'SCRIPT'
<script>
(function () {
    'use strict';

    var componentId = <?= json_encode($__wls_component_id, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var fieldId = <?= json_encode($__wls_field_id, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var fieldName = <?= json_encode($__wls_name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var isMultiple = <?= $__wls_is_multiple ? 'true' : 'false' ?>;
    var allowEmpty = <?= $__wls_allow_empty ? 'true' : 'false' ?>;
    var displayOnly = <?= $__wls_display_only ? 'true' : 'false' ?>;
    var items = <?= $__wls_items_json ?> || [];
    var selectedValues = <?= $__wls_selected_json ?> || [];
    var readonlyValues = <?= $__wls_readonly_json ?> || [];
    var allowedValues = <?= $__wls_allowed_json ?> || [];
    var onChangeName = <?= json_encode($__wls_on_change, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var showReference = <?= $__wls_show_reference ? 'true' : 'false' ?>;
    var inlineDropdown = <?= $__wls_inline_dropdown ? 'true' : 'false' ?>;
    var emptyText = <?= json_encode($__wls_empty_text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var displayOnlyText = <?= json_encode((string) __('仅展示'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var noMatchText = <?= json_encode((string) __('未找到匹配的语言'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var unknownCountryText = <?= json_encode((string) __('未分组国家'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var removeText = <?= json_encode((string) __('移除'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var clearText = <?= json_encode((string) __('清空选择'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    allowedValues = normalizeValues(allowedValues);
    var allowedMap = {};
    allowedValues.forEach(function (code) {
        allowedMap[code] = true;
    });
    if (allowedValues.length) {
        items = items.filter(function (item) {
            return item && isAllowed(item.code);
        });
    }
    selectedValues = filterAllowedValues(selectedValues);
    readonlyValues = filterAllowedValues(readonlyValues);

    var map = {};
    items.forEach(function (item) {
        map[item.code] = item;
    });

    var wrapper = document.getElementById(componentId + '_wrapper');
    var trigger = document.getElementById(componentId + '_trigger');
    var tags = document.getElementById(componentId + '_tags');
    var dropdown = document.getElementById(componentId + '_dropdown');
    var list = document.getElementById(componentId + '_list');
    var searchInput = document.getElementById(componentId + '_search');
    var fieldInput = document.getElementById(fieldId);
    var inputsContainer = isMultiple ? document.getElementById(fieldId + '_inputs') : null;

    if (!wrapper || !fieldInput || !tags) {
        return;
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeValues(values) {
        var source = [];
        if (Array.isArray(values)) {
            source = values;
        } else if (values === null || values === undefined || values === '') {
            source = [];
        } else {
            source = String(values).split(',');
        }

        var normalized = [];
        source.forEach(function (value) {
            if (value && typeof value === 'object' && value.code) {
                value = value.code;
            }
            value = String(value || '').trim();
            if (!value || normalized.indexOf(value) !== -1) {
                return;
            }
            normalized.push(value);
        });
        return normalized;
    }

    function isAllowed(code) {
        code = String(code || '').trim();
        return !allowedValues.length || !!allowedMap[code];
    }

    function filterAllowedValues(values) {
        return normalizeValues(values).filter(function (code) {
            return isAllowed(code);
        });
    }

    function getLocale(code) {
        if (map[code]) {
            return map[code];
        }
        return {
            code: code,
            name: code,
            self_name: code,
            english_name: code,
            display_name: code,
            tag_label: code,
            reference_name: code,
            country_code: '',
            country_name: unknownCountryText,
            flag: ''
        };
    }

    function ensureReadonlySelected() {
        readonlyValues = filterAllowedValues(readonlyValues);
        selectedValues = filterAllowedValues(selectedValues);
        readonlyValues.forEach(function (code) {
            if (selectedValues.indexOf(code) === -1) {
                selectedValues.push(code);
            }
        });
        if (!isMultiple && selectedValues.length > 1) {
            selectedValues = selectedValues.slice(0, 1);
        }
    }

    function renderFlag(locale) {
        if (locale && locale.flag) {
            return locale.flag;
        }
        return '<i class="mdi mdi-translate"></i>';
    }

    function buildTagHtml(code) {
        var locale = getLocale(code);
        var label = showReference
            ? (locale.display_name || locale.tag_label || locale.name || code)
            : (locale.name || locale.reference_name || code);
        var locked = displayOnly || readonlyValues.indexOf(code) !== -1;
        var html = '';
        html += '<span class="weline-language-select-tag' + (locked ? ' is-readonly' : '') + '" data-code="' + escapeHtml(code) + '">';
        html += '<span class="weline-language-select-flag">' + renderFlag(locale) + '</span>';
        html += '<span class="weline-language-select-label">' + escapeHtml(label) + '</span>';
        html += '<span class="weline-language-select-code">' + escapeHtml(locale.code) + '</span>';
        if (locked) {
            html += '<span class="weline-language-select-state">' + escapeHtml(displayOnlyText) + '</span>';
        } else if (isMultiple) {
            html += '<button type="button" class="weline-language-select-tag-remove" data-remove-code="' + escapeHtml(code) + '" aria-label="' + escapeHtml(removeText) + '"><i class="mdi mdi-close"></i></button>';
        }
        html += '</span>';
        return html;
    }

    function renderTags() {
        if (!selectedValues.length) {
            tags.innerHTML = '<span class="weline-language-select-placeholder"><i class="mdi mdi-translate"></i>' + escapeHtml(emptyText) + '</span>';
            return;
        }

        var html = '';
        selectedValues.forEach(function (code) {
            html += buildTagHtml(code);
        });
        tags.innerHTML = html;

        if (isMultiple && !displayOnly) {
            tags.querySelectorAll('[data-remove-code]').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    removeValue(button.getAttribute('data-remove-code'));
                });
            });
        }
    }

    function groupItems(filteredItems) {
        var groups = [];
        var currentGroup = null;

        filteredItems.forEach(function (item) {
            var countryKey = (item.country_name || '') + '|' + (item.country_code || '');
            if (!currentGroup || currentGroup.key !== countryKey) {
                currentGroup = {
                    key: countryKey,
                    country_name: item.country_name || unknownCountryText,
                    country_code: item.country_code || '',
                    items: []
                };
                groups.push(currentGroup);
            }
            currentGroup.items.push(item);
        });

        return groups;
    }

    function renderList(keyword) {
        if (!list) {
            return;
        }

        keyword = String(keyword || '').trim().toLowerCase();
        var filteredItems = items.filter(function (item) {
            if (!keyword) {
                return true;
            }
            return String(item.search || '').toLowerCase().indexOf(keyword) !== -1;
        });

        if (!filteredItems.length) {
            list.innerHTML = '<div class="weline-language-select-empty">' + escapeHtml(noMatchText) + '</div>';
            return;
        }

        var html = '';
        if (!isMultiple && allowEmpty) {
            html += '<button type="button" class="weline-language-select-item" data-code="__empty__">';
            html += '<span class="weline-language-select-indicator"><i class="mdi mdi-close-circle-outline"></i></span>';
            html += '<span class="weline-language-select-item-copy"><strong>' + escapeHtml(clearText) + '</strong><small></small></span>';
            html += '</button>';
        }
        groupItems(filteredItems).forEach(function (group) {
            html += '<div class="weline-language-select-group-label"><span>' + escapeHtml(group.country_name) + '</span><small>' + escapeHtml(group.country_code) + '</small></div>';
            group.items.forEach(function (item) {
                var label = showReference
                    ? (item.display_name || item.tag_label || item.name || item.code)
                    : (item.name || item.reference_name || item.code);
                var selected = selectedValues.indexOf(item.code) !== -1;
                var locked = readonlyValues.indexOf(item.code) !== -1 && selected;
                var icon = isMultiple
                    ? (selected ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline')
                    : (selected ? 'mdi-radiobox-marked' : 'mdi-radiobox-blank');
                var metaParts = [item.code];
                var englishName = String(item.english_name || item.reference_name || '').trim();
                if (englishName && englishName !== label && metaParts.indexOf(englishName) === -1) {
                    metaParts.push(englishName);
                }
                var countryName = String(item.country_name || '').trim();
                if (countryName && metaParts.indexOf(countryName) === -1) {
                    metaParts.push(countryName);
                }

                html += '<button type="button" class="weline-language-select-item' + (selected ? ' is-selected' : '') + (locked ? ' is-readonly' : '') + '" data-code="' + escapeHtml(item.code) + '">';
                html += '<span class="weline-language-select-indicator"><i class="mdi ' + icon + '"></i></span>';
                html += '<span class="weline-language-select-flag">' + renderFlag(item) + '</span>';
                html += '<span class="weline-language-select-item-copy">';
                html += '<strong>' + escapeHtml(label) + '</strong>';
                html += '<small>' + escapeHtml(metaParts.join(' | ')) + '</small>';
                html += '</span>';
                if (locked) {
                    html += '<span class="weline-language-select-state">' + escapeHtml(displayOnlyText) + '</span>';
                }
                html += '</button>';
            });
        });

        list.innerHTML = html;
        list.querySelectorAll('.weline-language-select-item').forEach(function (button) {
            button.addEventListener('click', function () {
                if (displayOnly) {
                    return;
                }

                var code = button.getAttribute('data-code') || '';
                if (!code) {
                    return;
                }
                if (code === '__empty__') {
                    if (!isMultiple && allowEmpty) {
                        setValues([]);
                        closeDropdown();
                    }
                    return;
                }

                if (readonlyValues.indexOf(code) !== -1 && selectedValues.indexOf(code) !== -1) {
                    return;
                }

                if (isMultiple) {
                    toggleValue(code);
                    return;
                }

                setValues([code]);
                closeDropdown();
            });
        });
    }

    function dispatchChange() {
        try {
            fieldInput.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (error) {
        }

        try {
            wrapper.dispatchEvent(new CustomEvent('weline:language-select:change', {
                bubbles: true,
                detail: api.getDetail()
            }));
        } catch (error) {
        }

        if (onChangeName && typeof window[onChangeName] === 'function') {
            window[onChangeName](api.getDetail());
        }
    }

    function syncInputs() {
        ensureReadonlySelected();

        if (isMultiple) {
            fieldInput.value = selectedValues.join(',');
            if (inputsContainer) {
                inputsContainer.innerHTML = '';
                if (fieldName) {
                    selectedValues.forEach(function (code) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = fieldName;
                        input.value = code;
                        inputsContainer.appendChild(input);
                    });
                }
            }
        } else {
            fieldInput.value = selectedValues[0] || '';
        }

        renderTags();
        renderList(searchInput ? searchInput.value : '');
        dispatchChange();
    }

    function setValues(values) {
        selectedValues = filterAllowedValues(values);
        ensureReadonlySelected();
        syncInputs();
    }

    function setReadonlyValues(values) {
        readonlyValues = filterAllowedValues(values);
        ensureReadonlySelected();
        syncInputs();
    }

    function addValue(code) {
        code = String(code || '').trim();
        if (!code || !isAllowed(code) || selectedValues.indexOf(code) !== -1) {
            return;
        }
        selectedValues.push(code);
        syncInputs();
    }

    function removeValue(code) {
        code = String(code || '').trim();
        if (!code || readonlyValues.indexOf(code) !== -1) {
            return;
        }
        selectedValues = selectedValues.filter(function (value) {
            return value !== code;
        });
        syncInputs();
    }

    function toggleValue(code) {
        if (selectedValues.indexOf(code) !== -1) {
            removeValue(code);
            return;
        }
        addValue(code);
    }

    function positionDropdown() {
        if (!dropdown || !trigger) {
            return;
        }

        var rect = trigger.getBoundingClientRect();
        var width = Math.max(
            1,
            Math.round(
                rect.width
                || trigger.offsetWidth
                || wrapper.getBoundingClientRect().width
                || 0
            )
        );

        if (!inlineDropdown && dropdown.parentNode !== document.body) {
            document.body.appendChild(dropdown);
        }

        if (inlineDropdown) {
            dropdown.style.position = 'absolute';
            dropdown.style.left = '0px';
            dropdown.style.right = 'auto';
            dropdown.style.top = 'calc(100% + 0.25rem)';
            dropdown.style.bottom = 'auto';
            dropdown.style.width = width + 'px';
            dropdown.style.minWidth = width + 'px';
            dropdown.style.maxWidth = 'none';
            dropdown.style.maxHeight = 'calc(100vh - 24px)';
            dropdown.style.boxSizing = 'border-box';
            dropdown.style.zIndex = '4200';
            dropdown.setAttribute('data-placement', 'internal');
            return;
        }

        var viewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
        var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
        var viewportPadding = 8;
        var availableWidth = Math.max(1, viewportWidth - viewportPadding * 2);
        var dropdownWidth = Math.min(width, availableWidth);
        var left = Math.min(
            Math.max(viewportPadding, Math.round(rect.left)),
            Math.max(viewportPadding, viewportWidth - dropdownWidth - viewportPadding)
        );
        var belowTop = Math.round(rect.bottom + 4);
        var belowSpace = viewportHeight - belowTop - viewportPadding;
        var aboveBottom = Math.round(viewportHeight - rect.top + 4);
        var aboveSpace = rect.top - viewportPadding - 4;
        var placeAbove = belowSpace < 220 && aboveSpace > belowSpace;
        var maxHeight = Math.min(360, Math.max(96, placeAbove ? aboveSpace : belowSpace));
        var listMaxHeight = Math.max(64, maxHeight - 86);
        var list = dropdown.querySelector('.weline-language-select-list');

        dropdown.style.position = 'fixed';
        dropdown.style.left = left + 'px';
        dropdown.style.right = 'auto';
        dropdown.style.width = dropdownWidth + 'px';
        dropdown.style.minWidth = dropdownWidth + 'px';
        dropdown.style.maxWidth = availableWidth + 'px';
        dropdown.style.maxHeight = maxHeight + 'px';
        dropdown.style.boxSizing = 'border-box';
        dropdown.style.zIndex = '200100';
        if (placeAbove) {
            dropdown.style.top = 'auto';
            dropdown.style.bottom = aboveBottom + 'px';
            dropdown.setAttribute('data-placement', 'body-above');
        } else {
            dropdown.style.top = belowTop + 'px';
            dropdown.style.bottom = 'auto';
            dropdown.setAttribute('data-placement', 'body-below');
        }
        if (list) {
            list.style.maxHeight = listMaxHeight + 'px';
        }
    }

    function openDropdown() {
        if (displayOnly || !dropdown) {
            return;
        }
        positionDropdown();
        wrapper.classList.add('is-open');
        dropdown.style.display = 'block';
        renderList(searchInput ? searchInput.value : '');
        if (searchInput) {
            window.setTimeout(function () {
                searchInput.focus();
            }, 0);
        }
        document.addEventListener('click', handleOutsideClick);
        document.addEventListener('keydown', handleEscape);
        window.addEventListener('resize', handleViewportChange);
        window.addEventListener('scroll', handleViewportChange, true);
    }

    function closeDropdown() {
        if (!dropdown) {
            return;
        }
        dropdown.style.display = 'none';
        wrapper.classList.remove('is-open');
        document.removeEventListener('click', handleOutsideClick);
        document.removeEventListener('keydown', handleEscape);
        window.removeEventListener('resize', handleViewportChange);
        window.removeEventListener('scroll', handleViewportChange, true);
    }

    function handleOutsideClick(event) {
        if (!wrapper.contains(event.target) && (!dropdown || !dropdown.contains(event.target))) {
            closeDropdown();
        }
    }

    function handleViewportChange() {
        if (!dropdown || dropdown.style.display !== 'block') {
            return;
        }
        positionDropdown();
    }

    function handleEscape(event) {
        if (event.key === 'Escape') {
            closeDropdown();
        }
    }

    var api = {
        getValue: function () {
            return selectedValues[0] || '';
        },
        getValues: function () {
            return selectedValues.slice();
        },
        getReadonlyValues: function () {
            return readonlyValues.slice();
        },
        getDetail: function () {
            return {
                componentId: componentId,
                fieldId: fieldId,
                multiple: isMultiple,
                displayOnly: displayOnly,
                value: selectedValues[0] || '',
                values: selectedValues.slice(),
                readonlyValues: readonlyValues.slice()
            };
        },
        setValue: function (value) {
            setValues(value ? [value] : []);
        },
        setValues: function (values) {
            setValues(values);
        },
        addValue: function (value) {
            addValue(value);
        },
        removeValue: function (value) {
            removeValue(value);
        },
        setReadonlyValues: function (values) {
            setReadonlyValues(values);
        },
        setDisplayOnly: function (value) {
            displayOnly = !!value;
            wrapper.setAttribute('data-display-only', displayOnly ? 'true' : 'false');
            if (trigger) {
                trigger.disabled = displayOnly;
                trigger.classList.toggle('is-display-only', displayOnly);
            }
            if (displayOnly) {
                closeDropdown();
            }
            renderTags();
            renderList(searchInput ? searchInput.value : '');
        },
        refresh: function () {
            renderTags();
            renderList(searchInput ? searchInput.value : '');
        },
        getFieldInput: function () {
            return fieldInput;
        }
    };

    if (trigger && !displayOnly) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (!dropdown || dropdown.style.display === 'block') {
                closeDropdown();
                return;
            }
            openDropdown();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            renderList(searchInput.value);
        });
    }

    if (!window.WelineLanguageSelect) {
        window.WelineLanguageSelect = {};
    }

    if (!window.WelineLanguageSelectUtils) {
        window.WelineLanguageSelectUtils = {
            get: function (componentId) {
                if (!window.WelineLanguageSelect) {
                    return null;
                }
                return window.WelineLanguageSelect[componentId] || null;
            },
            bindDefaultToMultiple: function (config) {
                config = config || {};

                var single = this.get(config.singleId || '');
                var multiple = this.get(config.multipleId || '');
                if (!single || !multiple) {
                    return null;
                }

                var syncing = false;

                function unique(values) {
                    var result = [];
                    (Array.isArray(values) ? values : []).forEach(function (value) {
                        value = String(value || '').trim();
                        if (!value || result.indexOf(value) !== -1) {
                            return;
                        }
                        result.push(value);
                    });
                    return result;
                }

                function sync() {
                    if (syncing) {
                        return;
                    }
                    syncing = true;

                    try {
                        var current = String(single.getValue() || '').trim();
                        var values = unique(multiple.getValues());
                        if (current && values.indexOf(current) === -1) {
                            values.unshift(current);
                        }

                        multiple.setReadonlyValues(current ? [current] : []);
                        multiple.setValues(values);
                    } finally {
                        syncing = false;
                    }
                }

                var fieldInput = single.getFieldInput();
                if (fieldInput && typeof fieldInput.addEventListener === 'function') {
                    fieldInput.addEventListener('change', sync);
                }

                sync();

                return {
                    single: single,
                    multiple: multiple,
                    sync: sync
                };
            }
        };
    }

    window.WelineLanguageSelect[componentId] = api;

    selectedValues = filterAllowedValues(selectedValues);
    readonlyValues = filterAllowedValues(readonlyValues);
    ensureReadonlySelected();
    syncInputs();
})();
</script>
SCRIPT;

            return \implode("\n", $html);
        };
    }

    public static function tag_self_close(): bool
    {
        return true;
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    public static function parent(): ?string
    {
        return null;
    }

    public static function document(): string
    {
        $doc = <<<DOC
<h3><code>&lt;w:i18n:language:select&gt;</code> 使用文档</h3>
<p>提供统一的后台语言选择组件，支持国家分组搜索、单选/多选、标签展示与只读标签。</p>
DOC;

        return \htmlspecialchars($doc, ENT_NOQUOTES);
    }

    private static function getLanguageItems(string $displayLocale): array
    {
        if (isset(self::$itemsCache[$displayLocale])) {
            return self::$itemsCache[$displayLocale];
        }

        /** @var I18n $i18n */
        $i18n = ObjectManager::getInstance(I18n::class);
        /** @var Locals $localsModel */
        $localsModel = ObjectManager::getInstance(Locals::class);
        /** @var Locale $localeModel */
        $localeModel = ObjectManager::getInstance(Locale::class);

        $localsRows = $localsModel
            ->clearQuery()
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->select()
            ->fetchArray();

        $rowsByCode = [];
        foreach ($localsRows as $row) {
            $code = (string)($row[Locals::schema_fields_CODE] ?? '');
            if ($code === '') {
                continue;
            }
            $rowsByCode[$code][] = $row;
        }

        $localeRows = $localeModel
            ->clearQuery()
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->where(Locale::schema_fields_IS_INSTALL, 1)
            ->select()
            ->fetchArray();

        foreach ($localeRows as $row) {
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code === '' || isset($rowsByCode[$code])) {
                continue;
            }
            $rowsByCode[$code][] = [
                Locals::schema_fields_CODE => $code,
                Locals::schema_fields_TARGET_CODE => $displayLocale,
                Locals::schema_fields_NAME => $i18n->getLocaleName($code, $displayLocale),
            ];
        }

        $localeMetaRows = $localeModel
            ->clearQuery()
            ->where(Locale::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();

        $localeMeta = [];
        foreach ($localeMetaRows as $row) {
            $code = (string)($row[Locale::schema_fields_CODE] ?? '');
            if ($code === '') {
                continue;
            }
            $localeMeta[$code] = $row;
        }

        $countryNames = [];
        foreach (Countries::getNames(\extension_loaded('intl') ? $displayLocale : 'en') as $code => $name) {
            $countryNames[\strtoupper((string)$code)] = (string)$name;
        }

        $items = [];
        foreach ($rowsByCode as $code => $rows) {
            $preferred = $rows[0];
            foreach ($rows as $row) {
                if ((string)($row[Locals::schema_fields_TARGET_CODE] ?? '') === $displayLocale) {
                    $preferred = $row;
                    break;
                }
            }

            $name = \trim((string)($preferred[Locals::schema_fields_NAME] ?? ''));
            if ($name === '' || (string)($preferred[Locals::schema_fields_TARGET_CODE] ?? '') !== $displayLocale) {
                $name = $i18n->getLocaleName($code, $displayLocale);
            }

            $meta = $localeMeta[$code] ?? [];
            $countryCode = \strtoupper((string)($meta[Locale::schema_fields_COUNTRY_CODE] ?? self::extractCountryCode($code)));
            $countryName = $countryCode !== ''
                ? (string)($countryNames[$countryCode] ?? $countryCode)
                : (string)__('未分组国家');
            $flag = (string)($meta[Locale::schema_fields_FLAG] ?? '');
            if ($flag === '' && $countryCode !== '') {
                $flag = (string)$i18n->getCountryFlag($countryCode, 20, 15, true);
            }

            $shortCode = (string)($meta[Locale::schema_fields_SHORT_CODE] ?? Locale::extractShortCode($code));
            $iso2 = (string)($meta[Locale::schema_fields_ISO2] ?? '');
            $iso3 = (string)($meta[Locale::schema_fields_ISO3] ?? '');
            $selfName = $i18n->getLocaleName($code, $code);
            $referenceName = $i18n->getLocaleName($code, 'en');
            $displayName = self::buildDisplayName($name, $referenceName, $selfName, $code);
            $tagLabel = self::buildTagLabel($name, $selfName, $referenceName, $code);
            $searchTerms = self::buildSearchTerms([
                $code,
                $name,
                $selfName,
                $referenceName,
                $displayName,
                $tagLabel,
                $countryCode,
                $countryName,
                $shortCode,
                $iso2,
                $iso3,
            ]);

            $items[] = [
                'code' => $code,
                'name' => $name,
                'self_name' => $selfName,
                'english_name' => $referenceName,
                'reference_name' => $referenceName,
                'display_name' => $displayName,
                'tag_label' => $tagLabel,
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'flag' => $flag,
                'short_code' => $shortCode,
                'iso2' => $iso2,
                'iso3' => $iso3,
                'search' => \implode(' ', $searchTerms),
            ];
        }

        \usort($items, static function (array $a, array $b): int {
            $countryCompare = \strnatcasecmp((string)($a['country_name'] ?? ''), (string)($b['country_name'] ?? ''));
            if ($countryCompare !== 0) {
                return $countryCompare;
            }

            $nameCompare = \strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return \strnatcasecmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
        });

        self::$itemsCache[$displayLocale] = $items;
        return $items;
    }

    public static function getLanguageItemsJson(string $displayLocale): string
    {
        $displayLocale = trim($displayLocale) !== '' ? trim($displayLocale) : (State::getLang() ?: State::getLangLocal() ?: 'zh_Hans_CN');
        $itemsJson = \json_encode(
            self::getLanguageItems($displayLocale),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $itemsJson === false ? '[]' : $itemsJson;
    }

    private static function buildDisplayName(string $localizedName, string $referenceName, string $selfName, string $code): string
    {
        $localizedName = \trim($localizedName);
        $referenceName = \trim($referenceName);
        $selfName = \trim($selfName);

        if ($localizedName === '') {
            $localizedName = $selfName !== '' ? $selfName : ($referenceName !== '' ? $referenceName : $code);
        }

        $locatorName = '';
        if ($referenceName !== '' && $referenceName !== $localizedName) {
            $locatorName = $referenceName;
        } elseif ($selfName !== '' && $selfName !== $localizedName) {
            $locatorName = $selfName;
        } elseif ($code !== $localizedName) {
            $locatorName = $code;
        }

        return $locatorName !== '' ? $localizedName . ' (' . $locatorName . ')' : $localizedName;
    }

    private static function buildTagLabel(string $localizedName, string $selfName, string $referenceName, string $code): string
    {
        foreach ([$localizedName, $selfName, $referenceName, $code] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $code;
    }

    /**
     * @param array<int, mixed> $values
     * @return list<string>
     */
    private static function buildSearchTerms(array $values): array
    {
        $terms = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }

            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }

            $normalized = \mb_strtolower($value, 'UTF-8');
            if (isset($terms[$normalized])) {
                continue;
            }
            $terms[$normalized] = $value;
        }

        return \array_values($terms);
    }

    private static function extractCountryCode(string $localeCode): string
    {
        $parts = \explode('_', $localeCode);
        $last = \end($parts);
        if (\is_string($last) && \strlen($last) === 2) {
            return \strtoupper($last);
        }
        return '';
    }
}
