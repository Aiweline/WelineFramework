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
 * 图标类型参数 UI 组件
 * 
 * 渲染图标选择器，集成 RemixIcon 图标库，支持搜索过滤
 */
class IconType extends AbstractParamType
{
    /**
     * 常用图标列表
     */
    private const COMMON_ICONS = [
        'ri-home-line', 'ri-home-fill',
        'ri-user-line', 'ri-user-fill',
        'ri-settings-line', 'ri-settings-fill',
        'ri-search-line', 'ri-search-fill',
        'ri-menu-line', 'ri-menu-fill',
        'ri-close-line', 'ri-close-fill',
        'ri-arrow-left-line', 'ri-arrow-right-line',
        'ri-arrow-up-line', 'ri-arrow-down-line',
        'ri-check-line', 'ri-check-fill',
        'ri-add-line', 'ri-subtract-line',
        'ri-edit-line', 'ri-delete-bin-line',
        'ri-eye-line', 'ri-eye-off-line',
        'ri-heart-line', 'ri-heart-fill',
        'ri-star-line', 'ri-star-fill',
        'ri-shopping-cart-line', 'ri-shopping-bag-line',
        'ri-mail-line', 'ri-phone-line',
        'ri-map-pin-line', 'ri-calendar-line',
        'ri-time-line', 'ri-notification-line',
        'ri-share-line', 'ri-link',
        'ri-image-line', 'ri-video-line',
        'ri-file-line', 'ri-folder-line',
        'ri-download-line', 'ri-upload-line',
        'ri-refresh-line', 'ri-loop-left-line',
        'ri-information-line', 'ri-question-line',
        'ri-error-warning-line', 'ri-checkbox-circle-line',
        'ri-facebook-fill', 'ri-twitter-fill',
        'ri-instagram-fill', 'ri-youtube-fill',
        'ri-linkedin-fill', 'ri-github-fill',
        'ri-wechat-fill', 'ri-weibo-fill',
        'ri-telegram-fill', 'ri-whatsapp-fill',
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
        
        // 获取当前值
        $currentValue = $value ?? $this->getDefaultValue($param) ?? '';
        
        $inputHtml = '<div class="icon-picker-wrapper">';
        
        // 当前图标预览
        $inputHtml .= '<div class="icon-preview">';
        $inputHtml .= '<span class="icon-preview-display">';
        if (!empty($currentValue)) {
            $inputHtml .= '<i class="' . htmlspecialchars($currentValue) . '"></i>';
        } else {
            $inputHtml .= '<i class="ri-add-line placeholder-icon"></i>';
        }
        $inputHtml .= '</span>';
        $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-primary btn-icon-picker" data-target="' . htmlspecialchars($fieldId) . '">';
        $inputHtml .= '<i class="ri-apps-line"></i> ' . __('选择图标');
        $inputHtml .= '</button>';
        if (!empty($currentValue)) {
            $inputHtml .= '<button type="button" class="btn btn-sm btn-outline-danger btn-icon-clear" data-target="' . htmlspecialchars($fieldId) . '">';
            $inputHtml .= '<i class="ri-close-line"></i>';
            $inputHtml .= '</button>';
        }
        $inputHtml .= '</div>';
        
        // 隐藏输入框
        $inputHtml .= '<input type="hidden" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($currentValue) . '">';
        
        // 图标选择面板（初始隐藏）
        $inputHtml .= '<div class="icon-picker-panel" id="' . htmlspecialchars($fieldId) . '_panel" style="display:none;">';
        
        // 搜索框
        $inputHtml .= '<div class="icon-picker-search">';
        $inputHtml .= '<input type="text" class="form-control" placeholder="' . __('搜索图标...') . '" data-panel="' . htmlspecialchars($fieldId) . '_panel">';
        $inputHtml .= '</div>';
        
        // 图标网格
        $inputHtml .= '<div class="icon-picker-grid">';
        
        // 使用自定义图标或默认图标
        $icons = !empty($customIcons) ? $customIcons : self::COMMON_ICONS;
        foreach ($icons as $icon) {
            $isSelected = $icon === $currentValue ? ' selected' : '';
            $inputHtml .= '<button type="button" class="icon-picker-item' . $isSelected . '" data-icon="' . htmlspecialchars($icon) . '" data-target="' . htmlspecialchars($fieldId) . '" title="' . htmlspecialchars($icon) . '">';
            $inputHtml .= '<i class="' . htmlspecialchars($icon) . '"></i>';
            $inputHtml .= '</button>';
        }
        
        $inputHtml .= '</div>';
        
        // 自定义输入
        if ($allowCustom) {
            $inputHtml .= '<div class="icon-picker-custom">';
            $inputHtml .= '<input type="text" class="form-control" placeholder="' . __('输入自定义图标类名') . '" data-target="' . htmlspecialchars($fieldId) . '">';
            $inputHtml .= '<button type="button" class="btn btn-sm btn-primary btn-apply-custom" data-target="' . htmlspecialchars($fieldId) . '">' . __('应用') . '</button>';
            $inputHtml .= '</div>';
        }
        
        $inputHtml .= '</div>';
        
        $inputHtml .= '</div>';
        
        return $this->wrapField($key, $param, $inputHtml, $layoutId);
    }

    public function validate(mixed $value, array $param): bool
    {
        if (!parent::validate($value, $param)) {
            return false;
        }
        
        // 图标类名验证（允许空值）
        if ($value === null || $value === '') {
            return true;
        }
        
        // 基本格式验证：只允许字母、数字、连字符、空格
        return (bool)preg_match('/^[a-zA-Z0-9\-\s]+$/', (string)$value);
    }

    public function processValue(mixed $value, array $param): mixed
    {
        return trim((string)$value);
    }
}
