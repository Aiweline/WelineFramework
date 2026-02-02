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
 * Widget 参数类型抽象基类
 * 
 * 提供通用的 HTML 渲染辅助方法和默认实现
 */
abstract class AbstractParamType implements WidgetParamTypeInterface
{
    /**
     * 生成唯一的字段 ID
     *
     * @param string $key 参数键名
     * @param int|string $layoutId 布局ID
     * @return string
     */
    protected function generateFieldId(string $key, int|string $layoutId): string
    {
        return 'config_' . $layoutId . '_' . $key;
    }

    /**
     * 将属性数组转换为 HTML 属性字符串
     *
     * @param array $attrs 属性数组
     * @return string
     */
    protected function buildAttrString(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            if ($value === true) {
                $parts[] = htmlspecialchars($name);
            } elseif ($value !== false && $value !== null) {
                $parts[] = htmlspecialchars($name) . '="' . htmlspecialchars((string)$value) . '"';
            }
        }
        return implode(' ', $parts);
    }

    /**
     * 渲染字段容器开始
     *
     * @param string $key 参数键名
     * @param array $param 参数定义
     * @param int|string $layoutId 布局ID
     * @return string
     */
    protected function renderFieldStart(string $key, array $param, int|string $layoutId): string
    {
        $translatable = $param['translatable'] ?? false;
        $fieldClass = $translatable ? 'config-field translatable-field' : 'config-field';
        
        return '<div class="' . $fieldClass . '" data-field-key="' . htmlspecialchars($key) . '" data-translatable="' . ($translatable ? 'true' : 'false') . '">';
    }

    /**
     * 渲染字段头部（标签 + 多语言按钮）
     *
     * @param string $key 参数键名
     * @param array $param 参数定义
     * @param int|string $layoutId 布局ID
     * @return string
     */
    protected function renderFieldHeader(string $key, array $param, int|string $layoutId): string
    {
        $fieldId = $this->generateFieldId($key, $layoutId);
        $label = $param['label'] ?? $key;
        $translatable = $param['translatable'] ?? false;
        $required = $param['required'] ?? false;
        
        $html = '<div class="config-field-header">';
        $html .= '<label class="config-label" for="' . htmlspecialchars($fieldId) . '">';
        $html .= htmlspecialchars($label);
        if ($required) {
            $html .= ' <span class="required-mark">*</span>';
        }
        $html .= '</label>';
        
        if ($translatable) {
            $html .= '<button type="button" class="btn-i18n-edit" data-field="' . htmlspecialchars($key) . '" data-layout-id="' . htmlspecialchars((string)$layoutId) . '" title="' . __('编辑多语言') . '">';
            $html .= '<i class="ri-translate-2"></i>';
            $html .= '<span>' . __('多语言') . '</span>';
            $html .= '</button>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    /**
     * 渲染多语言编辑面板
     *
     * @param string $key 参数键名
     * @param int|string $layoutId 布局ID
     * @return string
     */
    protected function renderI18nPanel(string $key, int|string $layoutId): string
    {
        $html = '<div class="i18n-edit-panel" id="i18n_panel_' . $layoutId . '_' . htmlspecialchars($key) . '" style="display:none;">';
        $html .= '<div class="i18n-panel-header">';
        $html .= '<span><i class="ri-global-line"></i> ' . __('多语言配置') . '</span>';
        $html .= '<button type="button" class="btn-i18n-close" data-field="' . htmlspecialchars($key) . '"><i class="ri-close-line"></i></button>';
        $html .= '</div>';
        $html .= '<div class="i18n-panel-body">';
        $html .= '<div class="i18n-lang-row">';
        $html .= '<label class="i18n-lang-label"><span class="lang-flag">🇨🇳</span> ' . __('简体中文') . '</label>';
        $html .= '<input type="text" class="form-control i18n-input" data-locale="zh_Hans_CN" data-field="' . htmlspecialchars($key) . '" placeholder="' . __('输入简体中文值') . '">';
        $html .= '</div>';
        $html .= '<div class="i18n-lang-row">';
        $html .= '<label class="i18n-lang-label"><span class="lang-flag">🇺🇸</span> English</label>';
        $html .= '<input type="text" class="form-control i18n-input" data-locale="en_US" data-field="' . htmlspecialchars($key) . '" placeholder="Enter English value">';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="i18n-panel-footer">';
        $html .= '<button type="button" class="btn btn-sm btn-primary btn-save-i18n" data-field="' . htmlspecialchars($key) . '" data-layout-id="' . htmlspecialchars((string)$layoutId) . '">';
        $html .= '<i class="ri-save-line"></i> ' . __('保存多语言');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * 渲染字段描述
     *
     * @param array $param 参数定义
     * @return string
     */
    protected function renderFieldDescription(array $param): string
    {
        $description = $param['description'] ?? '';
        if (empty($description)) {
            return '';
        }
        
        return '<div class="config-field-description"><i class="ri-information-line"></i> ' . htmlspecialchars($description) . '</div>';
    }

    /**
     * 渲染字段容器结束
     *
     * @return string
     */
    protected function renderFieldEnd(): string
    {
        return '</div>';
    }

    /**
     * 渲染完整的字段 HTML（包含容器、标签、输入控件、描述）
     *
     * @param string $key 参数键名
     * @param array $param 参数定义
     * @param string $inputHtml 输入控件 HTML
     * @param int|string $layoutId 布局ID
     * @return string
     */
    protected function wrapField(string $key, array $param, string $inputHtml, int|string $layoutId): string
    {
        $translatable = $param['translatable'] ?? false;
        
        $html = $this->renderFieldStart($key, $param, $layoutId);
        $html .= $this->renderFieldHeader($key, $param, $layoutId);
        $html .= '<div class="config-field-input">' . $inputHtml . '</div>';
        
        if ($translatable) {
            $html .= $this->renderI18nPanel($key, $layoutId);
        }
        
        $html .= $this->renderFieldDescription($param);
        $html .= $this->renderFieldEnd();
        
        return $html;
    }

    /**
     * 默认验证实现
     */
    public function validate(mixed $value, array $param): bool
    {
        $required = $param['required'] ?? false;
        if ($required && ($value === null || $value === '')) {
            return false;
        }
        return true;
    }

    /**
     * 默认值处理实现
     */
    public function processValue(mixed $value, array $param): mixed
    {
        return $value;
    }

    /**
     * 获取默认值
     */
    public function getDefaultValue(array $param): mixed
    {
        return $param['default'] ?? null;
    }
}
