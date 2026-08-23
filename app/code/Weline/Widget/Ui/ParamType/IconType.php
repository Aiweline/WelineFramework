<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * Semantic Weline SVG icon picker.
 *
 * Values are icon registry names, never third-party font class strings.
 */
class IconType extends AbstractParamType
{
    private const COMMON_ICONS = [
        'home', 'user', 'settings', 'search', 'menu', 'close',
        'arrow-left', 'arrow-right', 'arrow-up', 'arrow-down',
        'check', 'plus', 'minus', 'edit', 'trash', 'eye', 'eye-off',
        'heart', 'star', 'pin', 'mail', 'phone', 'calendar', 'clock',
        'bell', 'share', 'link', 'image', 'file', 'folder', 'download',
        'upload', 'refresh', 'info', 'help', 'warning', 'circle',
    ];

    public function getTypeCode(): string
    {
        return 'icon';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $panelId = $fieldId . '_panel';
        $allowCustom = $param['allow_custom'] ?? true;
        $rawValue = (string)($value ?? $this->getDefaultValue($param) ?? '');
        $currentValue = $rawValue === '' ? '' : $this->normalizeIconName($rawValue);
        $customIcons = is_array($param['icons'] ?? null) ? $param['icons'] : [];
        $icons = array_values(array_unique(array_filter(array_map(
            fn (mixed $icon): string => $this->normalizeIconName((string)$icon, false),
            $customIcons,
        ))));
        if ($icons === []) {
            $icons = self::COMMON_ICONS;
        }

        $escapedFieldId = htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8');
        $escapedPanelId = htmlspecialchars($panelId, ENT_QUOTES, 'UTF-8');
        $escapedKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $escapedValue = htmlspecialchars($currentValue, ENT_QUOTES, 'UTF-8');
        $displayValue = $currentValue !== ''
            ? $escapedValue
            : htmlspecialchars((string)__('未选择图标'), ENT_QUOTES, 'UTF-8');

        $inputHtml = '<div class="w-icon-picker w-param-icon" data-w-component="icon-picker" data-w-placement="bottom-start"'
            . ' data-state="closed" data-w-empty-label="' . htmlspecialchars((string)__('未选择图标'), ENT_QUOTES, 'UTF-8') . '">';
        $inputHtml .= '<input type="hidden" id="' . $escapedFieldId . '" name="' . $escapedKey . '" value="' . $escapedValue . '" data-w-icon-input>';
        $inputHtml .= '<button type="button" class="w-icon-picker__trigger w-param-icon-trigger"'
            . ' data-w-icon-trigger aria-expanded="false" aria-controls="' . $escapedPanelId . '">';
        $inputHtml .= '<span class="w-icon-picker__preview" data-w-icon-preview>';
        if ($currentValue !== '') {
            $inputHtml .= '<w-icon name="' . $escapedValue . '" size="sm"></w-icon>';
        }
        $inputHtml .= '</span>';
        $inputHtml .= '<span class="w-icon-picker__text" data-w-icon-text>' . $displayValue . '</span>';
        $inputHtml .= '</button>';
        $inputHtml .= '<button type="button" class="w-button w-icon-picker__clear" data-tone="quiet" data-size="sm"'
            . ' data-icon-only="true" data-w-icon-clear aria-label="' . htmlspecialchars((string)__('清除图标'), ENT_QUOTES, 'UTF-8') . '"'
            . ($currentValue === '' ? ' hidden' : '') . '><w-icon name="close" size="sm"></w-icon></button>';

        $inputHtml .= '<div class="w-icon-picker__panel w-param-icon-panel" id="' . $escapedPanelId . '"'
            . ' data-w-icon-panel data-state="closed" aria-hidden="true" hidden>';
        $inputHtml .= '<input type="search" class="w-input w-icon-picker__search"'
            . ' placeholder="' . htmlspecialchars((string)__('搜索图标…'), ENT_QUOTES, 'UTF-8') . '"'
            . ' autocomplete="off" data-w-icon-search>';
        $inputHtml .= '<div class="w-icon-picker__list" role="listbox">';
        foreach ($icons as $icon) {
            $escapedIcon = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
            $selected = $icon === $currentValue;
            $inputHtml .= '<button type="button" class="w-icon-picker__option" role="option"'
                . ' data-w-icon-value="' . $escapedIcon . '" aria-selected="' . ($selected ? 'true' : 'false') . '"'
                . ' aria-label="' . $escapedIcon . '" title="' . $escapedIcon . '">'
                . '<w-icon name="' . $escapedIcon . '" size="sm"></w-icon></button>';
        }
        $inputHtml .= '</div>';
        $inputHtml .= '<p class="w-icon-picker__empty" data-w-icon-empty hidden>' . __('没有匹配图标') . '</p>';
        if ($allowCustom) {
            $inputHtml .= '<div class="w-icon-picker__custom">';
            $inputHtml .= '<input type="text" class="w-input" value="' . $escapedValue . '"'
                . ' placeholder="' . htmlspecialchars((string)__('输入 Weline 图标名称'), ENT_QUOTES, 'UTF-8') . '"'
                . ' pattern="[a-z][a-z0-9-]{0,63}" maxlength="64" data-w-icon-custom>';
            $inputHtml .= '<button type="button" class="w-button" data-tone="primary" data-size="sm" data-w-icon-apply>'
                . __('应用') . '</button>';
            $inputHtml .= '</div>';
        }
        $inputHtml .= '</div></div>';

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

        return $this->normalizeIconName((string)$value, false) !== '';
    }

    public function processValue(mixed $value, array $param): mixed
    {
        $value = trim((string)$value);
        return $value === '' ? '' : $this->normalizeIconName($value);
    }

    private function normalizeIconName(string $value, bool $fallback = true): string
    {
        $value = strtolower(trim($value));
        $valid = preg_match('/^[a-z][a-z0-9-]{0,63}$/', $value) === 1
            && preg_match('/^(?:mdi|fa[brs]?|ri)-/', $value) !== 1;

        return $valid ? $value : ($fallback ? 'circle' : '');
    }
}
