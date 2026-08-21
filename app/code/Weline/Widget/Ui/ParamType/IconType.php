<?php

declare(strict_types=1);

namespace Weline\Widget\Ui\ParamType;

/**
 * 图标类型参数 UI 组件
 */
class IconType extends AbstractParamType
{
    private const COMMON_ICONS = [
        'home', 'home', 'user', 'user', 'settings', 'settings',
        'search', 'search', 'menu', 'menu', 'close', 'close',
        'arrow-left', 'arrow-right', 'arrow-up', 'arrow-down',
        'check', 'check', 'plus', 'circle', 'edit', 'trash',
        'eye', 'eye-off', 'heart', 'heart', 'star', 'star',
        'pin', 'pin', 'mail', 'phone',
        'pin', 'calendar', 'clock', 'bell', 'share', 'link',
        'image', 'circle', 'file', 'folder', 'download', 'upload',
        'refresh', 'circle', 'info', 'help',
        'warning', 'check',
        'circle', 'circle', 'circle', 'circle', 'link', 'circle',
        'circle', 'circle', 'circle', 'circle',
    ];

    public function getTypeCode(): string
    {
        return 'icon';
    }

    public function getHtml(string $key, array $param, mixed $value, int|string $layoutId = '', array $attrs = []): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $allowCustom = $param['allow_custom'] ?? true;
        $customIcons = $param['icons'] ?? [];
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        $inputHtml = '<div class="w-param-icon">';
        $inputHtml .= '<div class="w-param-icon-preview">';
        $inputHtml .= '<span class="w-param-icon-preview-display">';
        $inputHtml .= !empty($currentValue) ? '<i class="' . htmlspecialchars($currentValue) . '"></i>' : '<span class="w-param-placeholder-icon">◇</span>';
        $inputHtml .= '</span>';
        $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-primary w-param-icon-trigger" data-target="' . htmlspecialchars($fieldId) . '">' . __('选择图标') . '</button>';
        if (!empty($currentValue)) {
            $inputHtml .= '<button type="button" class="w-param-btn w-param-btn-sm w-param-btn-outline-danger w-param-icon-clear" data-target="' . htmlspecialchars($fieldId) . '">×</button>';
        }
        $inputHtml .= '</div>';
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '">';
        $inputHtml .= '<div class="w-param-icon-panel" id="' . htmlspecialchars($fieldId) . '_panel" style="display:none;">';
        $inputHtml .= '<div class="w-param-icon-search"><input type="text" class="w-param-input" placeholder="' . __('搜索图标...') . '" data-panel="' . htmlspecialchars($fieldId) . '_panel"></div>';
        $inputHtml .= '<div class="w-param-icon-grid">';
        $icons = !empty($customIcons) ? $customIcons : self::COMMON_ICONS;
        foreach ($icons as $icon) {
            $inputHtml .= '<button type="button" class="w-param-icon-item' . ($icon === $currentValue ? ' w-param-selected' : '') . '" data-icon="' . htmlspecialchars($icon) . '" data-target="' . htmlspecialchars($fieldId) . '" title="' . htmlspecialchars($icon) . '"><i class="' . htmlspecialchars($icon) . '"></i></button>';
        }
        $inputHtml .= '</div>';
        if ($allowCustom) {
            $inputHtml .= '<div class="w-param-icon-custom"><input type="text" class="w-param-input" placeholder="' . __('输入自定义图标类名') . '" data-target="' . htmlspecialchars($fieldId) . '"><button type="button" class="w-param-btn w-param-btn-sm w-param-btn-primary w-param-icon-apply" data-target="' . htmlspecialchars($fieldId) . '">' . __('应用') . '</button></div>';
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
        return (bool)preg_match('/^[a-zA-Z0-9\-\s]+$/', (string)$value);
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return trim((string)$value);
    }
}
